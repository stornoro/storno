<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class SignatureVerifierService
{
    public function __construct(
        private readonly string $projectDir,
        private readonly LoggerInterface $logger,
        private readonly HttpClientInterface $httpClient,
        private readonly string $javaServiceUrl = '',
    ) {}

    /**
     * Verify an ANAF detached XML signature against invoice XML.
     *
     * @return array{valid: bool, message: string}
     */
    public function verify(string $xmlContent, string $signatureContent): array
    {
        // Try HTTP service first (fast path)
        $result = $this->verifyViaHttp($xmlContent, $signatureContent);
        if ($result !== null) {
            return $result;
        }

        // Fallback to shell script
        $this->logger->info('Falling back to shell-based signature verification (HTTP service unavailable)');
        return $this->verifyViaProcess($xmlContent, $signatureContent);
    }

    public function isAvailable(): bool
    {
        try {
            $response = $this->httpClient->request('GET', $this->getServiceUrl() . '/health', [
                'timeout' => 2,
            ]);
            $data = $response->toArray(false);
            if (($data['status'] ?? '') === 'ok') {
                return true;
            }
        } catch (\Throwable $e) {
            // Fall through
        }

        $script = $this->projectDir . '/tools/signature-verifier/verify-signature.sh';
        return file_exists($script);
    }

    /**
     * @return array{valid: bool, message: string}|null
     */
    private function verifyViaHttp(string $xmlContent, string $signatureContent): ?array
    {
        try {
            $response = $this->httpClient->request('POST', $this->getServiceUrl() . '/verify-signature', [
                'json' => [
                    'xml' => $xmlContent,
                    'signature' => $signatureContent,
                ],
                'timeout' => 30,
            ]);

            $data = $response->toArray();

            return [
                'valid' => $data['valid'] ?? false,
                'message' => $data['message'] ?? '',
            ];
        } catch (\Throwable $e) {
            $this->logger->warning('Signature HTTP service unavailable', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * @return array{valid: bool, message: string}
     */
    private function verifyViaProcess(string $xmlContent, string $signatureContent): array
    {
        $id = Uuid::v4()->toRfc4122();
        $tmpXml = sys_get_temp_dir() . "/storno_sig_{$id}.xml";
        $tmpSig = sys_get_temp_dir() . "/storno_sig_{$id}_sig.xml";

        try {
            file_put_contents($tmpXml, $xmlContent);
            file_put_contents($tmpSig, $signatureContent);

            $script = $this->projectDir . '/tools/signature-verifier/verify-signature.sh';
            $process = new Process([$script, $tmpXml, $tmpSig]);
            $process->setTimeout(30);
            $process->run();

            $output = trim($process->getOutput());
            $lines = explode("\n", $output);

            $valid = false;
            $message = $output;
            foreach ($lines as $i => $line) {
                $trimmed = trim($line);
                if ($trimmed === 'VALID' || $trimmed === 'INVALID') {
                    $valid = $trimmed === 'VALID';
                    $message = trim($lines[$i + 1] ?? $output);
                    break;
                }
            }

            return [
                'valid' => $valid,
                'message' => $message,
            ];
        } catch (\Throwable $e) {
            return [
                'valid' => false,
                'message' => 'Verification failed: ' . $e->getMessage(),
            ];
        } finally {
            @unlink($tmpXml);
            @unlink($tmpSig);
        }
    }

    private function getServiceUrl(): string
    {
        return $this->javaServiceUrl ?: 'http://127.0.0.1:8082';
    }
}
