<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class PdfGeneratorService
{
    public function __construct(
        private readonly string $projectDir,
        private readonly LoggerInterface $logger,
        private readonly HttpClientInterface $httpClient,
        private readonly string $javaServiceUrl = '',
    ) {}

    /**
     * Generate a PDF from UBL XML invoice content.
     *
     * @return string PDF binary content
     *
     * @throws \RuntimeException if generation fails
     */
    public function generatePdf(string $xmlContent): string
    {
        // Try HTTP service first (fast path: ~100-200ms)
        $pdf = $this->generateViaHttp($xmlContent);
        if ($pdf !== null) {
            return $pdf;
        }

        // Fallback to shell script (slow path: ~2-4s per PDF)
        $this->logger->info('Falling back to shell-based PDF generation (HTTP service unavailable)');
        return $this->generateViaProcess($xmlContent);
    }

    public function isAvailable(): bool
    {
        // Check HTTP service
        try {
            $response = $this->httpClient->request('GET', $this->getServiceUrl() . '/health', [
                'timeout' => 2,
            ]);
            $data = $response->toArray(false);
            if (($data['status'] ?? '') === 'ok') {
                return true;
            }
        } catch (\Throwable $e) {
            // Fall through to script check
        }

        $script = $this->projectDir . '/tools/pdf-generator/generate-pdf.sh';
        return file_exists($script);
    }

    private function generateViaHttp(string $xmlContent): ?string
    {
        try {
            $response = $this->httpClient->request('POST', $this->getServiceUrl() . '/generate-pdf', [
                'body' => $xmlContent,
                'headers' => ['Content-Type' => 'application/xml'],
                'timeout' => 30,
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode === 200) {
                return $response->getContent();
            }

            // Error response (JSON)
            $data = $response->toArray(false);
            $this->logger->warning('PDF HTTP service returned error', [
                'status' => $statusCode,
                'error' => $data['error'] ?? 'unknown',
            ]);

            return null;
        } catch (\Throwable $e) {
            $this->logger->warning('PDF HTTP service unavailable', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function generateViaProcess(string $xmlContent): string
    {
        $id = Uuid::v4()->toRfc4122();
        $tmpXml = sys_get_temp_dir() . "/storno_pdf_{$id}.xml";

        try {
            file_put_contents($tmpXml, $xmlContent);

            $script = $this->projectDir . '/tools/pdf-generator/generate-pdf.sh';
            $process = new Process([$script, $tmpXml]);
            $process->setTimeout(30);
            $process->run();

            if (!$process->isSuccessful()) {
                throw new \RuntimeException(
                    'PDF generation failed: ' . trim($process->getErrorOutput() ?: $process->getOutput())
                );
            }

            // GenFactura prints debug info to stdout; the PDF path is the last line
            $output = trim($process->getOutput());
            $lines = explode("\n", $output);
            $pdfPath = trim(end($lines));
            if (!$pdfPath || !file_exists($pdfPath)) {
                // Fallback: expect PDF at same path with .pdf extension
                $pdfPath = preg_replace('/\.xml$/', '.pdf', $tmpXml);
            }

            if (!file_exists($pdfPath)) {
                throw new \RuntimeException('PDF file was not generated.');
            }

            return file_get_contents($pdfPath);
        } finally {
            @unlink($tmpXml);
            $tmpPdf = preg_replace('/\.xml$/', '.pdf', $tmpXml);
            @unlink($tmpPdf);
            $tmpAtas = preg_replace('/\.xml$/', '_ATAS', $tmpXml);
            @unlink($tmpAtas);
        }
    }

    private function getServiceUrl(): string
    {
        return $this->javaServiceUrl ?: 'http://127.0.0.1:8082';
    }
}
