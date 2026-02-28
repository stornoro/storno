<?php

namespace App\Service\Anaf;

use App\DTO\Anaf\ValidationError;
use App\DTO\Anaf\ValidationResult;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class SchematronValidator
{
    private string $serviceUrl;
    private string $jarPath;
    private string $resourcesDir;
    private string $javaPath;

    public function __construct(
        string $projectDir,
        string $javaPath,
        private readonly LoggerInterface $logger,
        private readonly HttpClientInterface $httpClient,
        string $javaServiceUrl = '',
    ) {
        $this->jarPath = $projectDir . '/resources/validator/ROeFacturaValidator.jar';
        $this->resourcesDir = $projectDir . '/resources';
        $this->javaPath = $javaPath ?: 'java';
        $this->serviceUrl = $javaServiceUrl ?: 'http://127.0.0.1:8082';
    }

    public function isAvailable(): bool
    {
        // Check if the HTTP service is running
        try {
            $response = $this->httpClient->request('GET', $this->serviceUrl . '/health', [
                'timeout' => 2,
            ]);

            $data = $response->toArray(false);
            return ($data['status'] ?? '') === 'ok';
        } catch (\Throwable $e) {
            $this->logger->debug('Validator HTTP service not available, checking JAR fallback', [
                'error' => $e->getMessage(),
            ]);
        }

        // Fallback: check JAR availability
        return $this->isJarAvailable();
    }

    /**
     * @param string $xml      The XML content to validate
     * @param string $docType  'Invoice' or 'CreditNote' (root element name)
     */
    public function validate(string $xml, string $docType): ValidationResult
    {
        // Try HTTP service first (fast path: ~2-65ms)
        $result = $this->validateViaHttp($xml, $docType);
        if ($result !== null) {
            return $result;
        }

        // Fallback to JAR process (slow path: ~2-3s per validation)
        $this->logger->info('Falling back to JAR-based validation (HTTP service unavailable)');
        return $this->validateViaJar($xml, $docType);
    }

    /**
     * Validate via the persistent HTTP service (preferred).
     */
    private function validateViaHttp(string $xml, string $docType): ?ValidationResult
    {
        $type = match ($docType) {
            'CreditNote' => 'FCN',
            default => 'FACT1',
        };

        try {
            $response = $this->httpClient->request('POST', $this->serviceUrl . '/validate', [
                'query' => ['type' => $type],
                'body' => $xml,
                'headers' => ['Content-Type' => 'application/xml'],
                'timeout' => 30,
            ]);

            $data = $response->toArray();

            if ($data['valid'] ?? false) {
                return ValidationResult::valid();
            }

            $errors = [];
            foreach ($data['errors'] ?? [] as $err) {
                $errors[] = new ValidationError(
                    message: $err['ruleId']
                        ? '[' . $err['ruleId'] . '] ' . $err['message']
                        : $err['message'],
                    source: $err['source'] ?? 'schematron',
                    ruleId: $err['ruleId'] ?? null,
                    location: $err['location'] ?? null,
                );
            }

            return ValidationResult::invalid($errors);
        } catch (\Throwable $e) {
            $this->logger->warning('HTTP validation failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Fallback: validate by spawning a JVM process.
     */
    private function validateViaJar(string $xml, string $docType): ValidationResult
    {
        if (!$this->isJarAvailable()) {
            $this->logger->info('Schematron validator unavailable (Java or JAR not found)');
            return ValidationResult::valid();
        }

        $type = match ($docType) {
            'CreditNote' => 'FCN',
            default => 'FACT1',
        };

        $tmpFile = tempnam(sys_get_temp_dir(), 'ubl_') . '.xml';
        file_put_contents($tmpFile, $xml);

        $basename = basename($tmpFile);
        $responseFile = dirname($tmpFile) . '/RASP_' . $basename . '.txt';

        try {
            $command = sprintf(
                'cd %s && %s -jar %s -t %s -f %s 2>&1',
                escapeshellarg($this->resourcesDir),
                escapeshellarg($this->javaPath),
                escapeshellarg($this->jarPath),
                escapeshellarg($type),
                escapeshellarg($tmpFile),
            );

            exec($command, $output, $exitCode);

            if (!file_exists($responseFile)) {
                $this->logger->warning('Schematron validator response file not found', [
                    'responseFile' => $responseFile,
                    'stdout' => implode("\n", $output),
                ]);
                return ValidationResult::valid();
            }

            $responseText = file_get_contents($responseFile);
            $errors = $this->parseResponseFile($responseText);

            if (empty($errors)) {
                return ValidationResult::valid();
            }

            return ValidationResult::invalid($errors);
        } catch (\Throwable $e) {
            $this->logger->error('Schematron validation failed', ['error' => $e->getMessage()]);
            return ValidationResult::valid();
        } finally {
            @unlink($tmpFile);
            @unlink($responseFile);
        }
    }

    private function isJarAvailable(): bool
    {
        if (!file_exists($this->jarPath)) {
            return false;
        }

        exec(escapeshellarg($this->javaPath) . ' -version 2>&1', $output, $exitCode);

        return $exitCode === 0;
    }

    /**
     * Parse the RASP_*.txt response file from the JAR validator.
     *
     * @return ValidationError[]
     */
    private function parseResponseFile(string $text): array
    {
        $errors = [];

        if (preg_match_all('/textEroare=\[([^\]]+)\]-(.+)/m', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $errors[] = new ValidationError(
                    message: '[' . trim($match[1]) . '] ' . trim($match[2]),
                    source: 'schematron',
                    ruleId: trim($match[1]),
                );
            }
        }

        if (preg_match_all('/A aparut o eroare tehnica\.\s*Cod:\s*(\d+)/m', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $errors[] = new ValidationError(
                    message: 'Eroare tehnica validator (Cod: ' . $match[1] . ')',
                    source: 'schematron',
                    ruleId: 'TECH-' . $match[1],
                );
            }
        }

        if (preg_match_all('/org\.xml\.sax\.SAXParseException[^;]*;\s*(.+)/m', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $errors[] = new ValidationError(
                    message: trim($match[1]),
                    source: 'xsd',
                );
            }
        }

        return $errors;
    }
}
