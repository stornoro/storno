<?php

namespace App\Service\Export;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Builds the streamed responses of the CSV and XML exports, so a file of any size is written
 * to php://output row by row instead of being built in memory first.
 *
 * Once the first byte is out the status code and headers can no longer change. Callers
 * therefore run their permission checks and prime their row iterator (which runs the first
 * query) before calling this. An exception raised while streaming is logged with the export
 * name and the number of rows already written, and the stream ends there: the client gets a
 * truncated file rather than an error page appended to it.
 */
class StreamedExportFactory
{
    private const FLUSH_EVERY = 500;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Same bytes as the former in-memory exports: UTF-8 BOM, the header row, then one
     * fputcsv() row per item (default delimiter, enclosure and escape).
     *
     * @param list<string>              $header
     * @param iterable<array<int, mixed>> $rows
     */
    public function csv(string $filename, array $header, iterable $rows): StreamedResponse
    {
        return $this->response(function () use ($header, $rows, $filename): void {
            $out = fopen('php://output', 'w');
            $written = 0;
            try {
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, $header);
                foreach ($rows as $row) {
                    fputcsv($out, $row);
                    if (++$written % self::FLUSH_EVERY === 0) {
                        fflush($out);
                        flush();
                    }
                }
                fflush($out);
            } catch (\Throwable $e) {
                $this->logFailure($filename, $written, $e);
            } finally {
                fclose($out);
            }
        }, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
        ]);
    }

    /**
     * @param callable(): void $write writes the whole document to php://output
     */
    public function xml(string $filename, callable $write): StreamedResponse
    {
        return $this->response(function () use ($write, $filename): void {
            try {
                $write();
            } catch (\Throwable $e) {
                $this->logFailure($filename, null, $e);
            }
        }, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
        ]);
    }

    /**
     * Pulls the first item of a generator so its first query runs now, while an error can
     * still become a normal error response. foreach over a generator paused at its first
     * yield does not rerun it.
     */
    public static function prime(\Generator $rows): \Generator
    {
        $rows->current();

        return $rows;
    }

    /**
     * @param array<string, string> $headers
     */
    private function response(callable $callback, array $headers): StreamedResponse
    {
        return new StreamedResponse($callback, 200, $headers);
    }

    private function logFailure(string $filename, ?int $rows, \Throwable $e): void
    {
        $this->logger->error('Export failed while streaming; the client received a truncated file.', [
            'file' => $filename,
            'rowsWritten' => $rows,
            'exception' => $e,
        ]);
    }
}
