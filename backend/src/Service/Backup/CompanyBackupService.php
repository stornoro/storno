<?php

namespace App\Service\Backup;

use Doctrine\DBAL\Connection;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;

class CompanyBackupService
{
    /**
     * Tables with company_id column — matches CompanyDataPurger exactly.
     */
    private const COMPANY_TABLES = [
        'document_series',
        'bank_account',
        'vat_rate',
        'email_template',
        'product',
        'client',
        'supplier',
        'invoice',
        'payment',
        'efactura_message',
        'proforma_invoice',
        'recurring_invoice',
        'delivery_note',
        'email_log',
        'anaf_token_link',
        'import_job',
        'borderou_transaction',
    ];

    /**
     * Child tables joined through a parent table.
     * [childTable, fkColumn, parentTable]
     */
    private const JOIN_TABLES = [
        ['invoice_line', 'invoice_id', 'invoice'],
        ['invoice_attachment', 'invoice_id', 'invoice'],
        ['invoice_share_token', 'invoice_id', 'invoice'],
        ['document_event', 'invoice_id', 'invoice'],
        ['proforma_invoice_line', 'proforma_invoice_id', 'proforma_invoice'],
        ['recurring_invoice_line', 'recurring_invoice_id', 'recurring_invoice'],
        ['delivery_note_line', 'delivery_note_id', 'delivery_note'],
        ['email_event', 'email_log_id', 'email_log'],
    ];

    public function __construct(
        private readonly FilesystemOperator $defaultStorage,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Generate a full company backup as ZIP content.
     *
     * @param callable|null $progressCallback fn(int $percent, string $step)
     */
    public function generate(
        Connection $conn,
        string $companyId,
        string $companyName,
        string $companyCui,
        bool $includeFiles = true,
        ?callable $progressCallback = null,
    ): string {
        $tmpFile = tempnam(sys_get_temp_dir(), 'backup_') . '.zip';
        $zip = new \ZipArchive();

        if ($zip->open($tmpFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Failed to create ZIP archive');
        }

        $entityCounts = [];
        $allTables = $this->getAllTableNames();
        $totalSteps = count($allTables) + ($includeFiles ? 1 : 0);
        $currentStepIndex = 0;

        // ── Export company-level tables ──────────────────────────────
        foreach (self::COMPANY_TABLES as $table) {
            $this->reportProgress($progressCallback, $currentStepIndex, $totalSteps, "Exporting {$table}");

            $rows = $conn->fetchAllAssociative(
                "SELECT * FROM `{$table}` WHERE company_id = ?",
                [$companyId]
            );

            $entityCounts[$table] = count($rows);
            $zip->addFromString("data/{$table}.json", json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $currentStepIndex++;
        }

        // ── Export child tables (joined through parent) ─────────────
        foreach (self::JOIN_TABLES as [$child, $fk, $parent]) {
            $this->reportProgress($progressCallback, $currentStepIndex, $totalSteps, "Exporting {$child}");

            $rows = $conn->fetchAllAssociative(
                "SELECT c.* FROM `{$child}` c INNER JOIN `{$parent}` p ON c.`{$fk}` = p.id WHERE p.company_id = ?",
                [$companyId]
            );

            // Base64-encode any blob content fields (invoice_attachment)
            if ($child === 'invoice_attachment') {
                $rows = array_map(function (array $row) {
                    if (isset($row['content']) && $row['content'] !== null) {
                        $row['content_base64'] = base64_encode($row['content']);
                        unset($row['content']);
                    }
                    return $row;
                }, $rows);
            }

            $entityCounts[$child] = count($rows);
            $zip->addFromString("data/{$child}.json", json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $currentStepIndex++;
        }

        // ── Export files from storage ───────────────────────────────
        if ($includeFiles) {
            $this->reportProgress($progressCallback, $currentStepIndex, $totalSteps, 'Exporting files');
            $this->exportFiles($conn, $companyId, $zip);
            $currentStepIndex++;
        }

        // ── Create manifest ─────────────────────────────────────────
        $zip->close();

        // Compute checksum of the data
        $checksum = md5_file($tmpFile);

        $manifest = BackupManifest::create(
            companyName: $companyName,
            companyCui: $companyCui,
            entityCounts: $entityCounts,
            checksum: $checksum,
            includesFiles: $includeFiles,
        );

        // Re-open to add manifest
        $zip->open($tmpFile, \ZipArchive::CREATE);
        $zip->addFromString('manifest.json', $manifest->toJson());
        $zip->close();

        $this->reportProgress($progressCallback, $totalSteps, $totalSteps, 'Complete');

        if (!file_exists($tmpFile)) {
            throw new \RuntimeException('Failed to create backup ZIP');
        }

        $content = file_get_contents($tmpFile);
        unlink($tmpFile);

        return $content;
    }

    private function exportFiles(Connection $conn, string $companyId, \ZipArchive $zip): void
    {
        // Invoice files (xml, pdf, signature)
        $invoices = $conn->fetchAllAssociative(
            'SELECT id, xml_path, pdf_path, signature_path FROM invoice WHERE company_id = ?',
            [$companyId]
        );

        foreach ($invoices as $invoice) {
            $id = $invoice['id'];
            $filePaths = [
                'invoice.xml' => $invoice['xml_path'],
                'invoice.pdf' => $invoice['pdf_path'],
                'signature.p7s' => $invoice['signature_path'],
            ];

            foreach ($filePaths as $zipName => $storagePath) {
                if ($storagePath && $this->defaultStorage->fileExists($storagePath)) {
                    try {
                        $content = $this->defaultStorage->read($storagePath);
                        $zip->addFromString("files/invoices/{$id}/{$zipName}", $content);
                    } catch (\Throwable $e) {
                        $this->logger->warning('Failed to read invoice file for backup', [
                            'invoiceId' => $id,
                            'path' => $storagePath,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        }

        // Attachment files
        $attachments = $conn->fetchAllAssociative(
            'SELECT a.id, a.filename, a.storage_path FROM invoice_attachment a INNER JOIN invoice i ON a.invoice_id = i.id WHERE i.company_id = ?',
            [$companyId]
        );

        foreach ($attachments as $attachment) {
            $storagePath = $attachment['storage_path'];
            if ($storagePath && $this->defaultStorage->fileExists($storagePath)) {
                try {
                    $content = $this->defaultStorage->read($storagePath);
                    $zip->addFromString("files/attachments/{$attachment['id']}/{$attachment['filename']}", $content);
                } catch (\Throwable $e) {
                    $this->logger->warning('Failed to read attachment file for backup', [
                        'attachmentId' => $attachment['id'],
                        'path' => $storagePath,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * @return string[]
     */
    private function getAllTableNames(): array
    {
        $tables = self::COMPANY_TABLES;
        foreach (self::JOIN_TABLES as [$child]) {
            $tables[] = $child;
        }
        return $tables;
    }

    private function reportProgress(?callable $callback, int $current, int $total, string $step): void
    {
        if ($callback) {
            $percent = $total > 0 ? (int) round(($current / $total) * 100) : 0;
            $callback($percent, $step);
        }
    }
}
