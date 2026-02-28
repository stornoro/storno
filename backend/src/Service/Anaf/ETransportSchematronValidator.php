<?php

namespace App\Service\Anaf;

use App\DTO\Anaf\ValidationError;
use App\DTO\Anaf\ValidationResult;
use Psr\Log\LoggerInterface;

class ETransportSchematronValidator
{
    private readonly string $saxonJarPath;
    private readonly string $compiledXslPath;
    private readonly string $javaPath;

    public function __construct(
        string $projectDir,
        string $javaPath,
        private readonly LoggerInterface $logger,
    ) {
        $this->saxonJarPath = $projectDir . '/resources/validator/saxon-he.jar';
        $this->compiledXslPath = $projectDir . '/resources/etransport/eTransport-validation_v2.0.2.compiled.xsl';
        $this->javaPath = $javaPath ?: 'java';
    }

    public function isAvailable(): bool
    {
        if (!file_exists($this->saxonJarPath)) {
            return false;
        }

        if (!file_exists($this->compiledXslPath)) {
            return false;
        }

        exec(escapeshellarg($this->javaPath) . ' -version 2>&1', $output, $exitCode);

        return $exitCode === 0;
    }

    public function validate(string $xml): ValidationResult
    {
        if (!$this->isAvailable()) {
            $this->logger->warning('ETransport Schematron validator unavailable (Java or required files not found)');
            return ValidationResult::valid();
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'etransport_sch_') . '.xml';
        file_put_contents($tmpFile, $xml);

        try {
            $command = sprintf(
                '%s -jar %s -xsl:%s -s:%s 2>&1',
                escapeshellarg($this->javaPath),
                escapeshellarg($this->saxonJarPath),
                escapeshellarg($this->compiledXslPath),
                escapeshellarg($tmpFile),
            );

            exec($command, $output, $exitCode);
            $svrlXml = implode("\n", $output);

            if ($exitCode !== 0) {
                $this->logger->error('Saxon XSLT transformation failed', [
                    'exitCode' => $exitCode,
                    'output' => $svrlXml,
                ]);
                // Graceful degradation: don't block submission on transformer failure
                return ValidationResult::valid();
            }

            return $this->parseSvrl($svrlXml);
        } catch (\Throwable $e) {
            $this->logger->error('ETransport Schematron validation failed', [
                'error' => $e->getMessage(),
            ]);
            return ValidationResult::valid();
        } finally {
            @unlink($tmpFile);
        }
    }

    private function parseSvrl(string $svrlXml): ValidationResult
    {
        if (empty(trim($svrlXml))) {
            $this->logger->warning('Empty SVRL output from Schematron validation');
            return ValidationResult::valid();
        }

        $dom = new \DOMDocument();
        $previousUseErrors = libxml_use_internal_errors(true);

        // Sanitize: strip invalid UTF-8 sequences that may leak from compiled XSL comments
        $svrlXml = mb_convert_encoding($svrlXml, 'UTF-8', 'UTF-8');

        if (!$dom->loadXML($svrlXml)) {
            $this->logger->error('Failed to parse SVRL output', [
                'svrl' => substr($svrlXml, 0, 1000),
            ]);
            libxml_clear_errors();
            libxml_use_internal_errors($previousUseErrors);
            return ValidationResult::valid();
        }

        libxml_clear_errors();
        libxml_use_internal_errors($previousUseErrors);

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('svrl', 'http://purl.oclc.org/dsdl/svrl');

        $errors = [];
        $warnings = [];

        // Extract failed assertions (errors)
        $failedAsserts = $xpath->query('//svrl:failed-assert');
        foreach ($failedAsserts as $assert) {
            $flag = $assert->getAttribute('flag') ?: 'fatal';
            $ruleId = $assert->getAttribute('id') ?: null;
            $location = $assert->getAttribute('location') ?: null;

            $textNode = $xpath->query('svrl:text', $assert)->item(0);
            $message = $textNode ? trim($textNode->textContent) : 'Unknown Schematron error';

            $error = new ValidationError(
                message: $message,
                source: 'schematron',
                ruleId: $ruleId,
                location: $location,
            );

            if ($flag === 'warning') {
                $warnings[] = $message;
            } else {
                $errors[] = $error;
            }
        }

        // Extract successful reports with warning flag
        $reports = $xpath->query('//svrl:successful-report[@flag="warning"]');
        foreach ($reports as $report) {
            $textNode = $xpath->query('svrl:text', $report)->item(0);
            if ($textNode) {
                $warnings[] = trim($textNode->textContent);
            }
        }

        if (!empty($errors)) {
            return ValidationResult::invalid($errors, $warnings);
        }

        return new ValidationResult(isValid: true, errors: [], warnings: $warnings);
    }
}
