<?php

namespace App\Service\Backup;

use Doctrine\DBAL\Connection;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

class CompanyRestoreService
{
    /**
     * FK-safe import order (reverse of CompanyDataPurger purge order).
     * Parent tables first, children after.
     */
    private const IMPORT_ORDER = [
        // Level 1: No FK dependencies (other than company)
        'document_series',
        'bank_account',
        'vat_rate',
        'email_template',
        'product',
        'client',
        'supplier',
        // Level 2: Depend on above
        'invoice',          // parent_document_id handled in post-fixup
        'proforma_invoice',
        'recurring_invoice',
        'delivery_note',
        'email_log',
        'payment',
        'efactura_message',
        'anaf_token_link',
        'import_job',
        'borderou_transaction',
        // Level 3: Children of invoice
        'invoice_line',
        'invoice_attachment',
        'invoice_share_token',
        'document_event',
        // Level 4: Children of other parents
        'proforma_invoice_line',
        'recurring_invoice_line',
        'delivery_note_line',
        'email_event',
    ];

    /**
     * FK columns that reference company-scoped entities (need UUID remapping).
     * column_name => source_table
     */
    private const FK_REMAP = [
        'company_id' => null,       // special: always remap to target company
        'client_id' => 'client',
        'supplier_id' => 'supplier',
        'product_id' => 'product',
        'invoice_id' => 'invoice',
        'proforma_invoice_id' => 'proforma_invoice',
        'recurring_invoice_id' => 'recurring_invoice',
        'delivery_note_id' => 'delivery_note',
        'email_log_id' => 'email_log',
        'parent_document_id' => 'invoice',
        'document_series_id' => 'document_series',
        'bank_account_id' => 'bank_account',
        'vat_rate_id' => 'vat_rate',
        'email_template_id' => 'email_template',
    ];

    /**
     * User FK columns — nullified on restore (not transferable).
     */
    private const USER_FK_COLUMNS = [
        'created_by_id',
        'updated_by_id',
        'initiated_by_id',
        'user_id',
    ];

    public function __construct(
        private readonly FilesystemOperator $defaultStorage,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Restore company data from a backup ZIP.
     *
     * @param callable|null $progressCallback fn(int $percent, string $step)
     */
    public function restore(
        Connection $conn,
        string $targetCompanyId,
        string $zipContent,
        bool $includeFiles = true,
        ?callable $progressCallback = null,
    ): array {
        $tmpFile = tempnam(sys_get_temp_dir(), 'restore_') . '.zip';
        file_put_contents($tmpFile, $zipContent);

        $zip = new \ZipArchive();
        if ($zip->open($tmpFile) !== true) {
            unlink($tmpFile);
            throw new \RuntimeException('Failed to open backup ZIP');
        }

        // Validate manifest
        $manifestJson = $zip->getFromName('manifest.json');
        if (!$manifestJson) {
            $zip->close();
            unlink($tmpFile);
            throw new \RuntimeException('Invalid backup: manifest.json not found');
        }

        $manifest = BackupManifest::fromJson($manifestJson);
        if (!$manifest->isCompatible()) {
            $zip->close();
            unlink($tmpFile);
            throw new \RuntimeException(sprintf('Incompatible backup version: %s', $manifest->version));
        }

        /** @var array<string, array<string, string>> $uuidMap table => [oldId => newId] */
        $uuidMap = [];
        $entityCounts = [];

        $totalSteps = count(self::IMPORT_ORDER) + ($includeFiles && $manifest->includesFiles ? 1 : 0) + 1; // +1 for post-fixup
        $currentStep = 0;

        $conn->beginTransaction();

        try {
            // ── Import each table ───────────────────────────────────
            foreach (self::IMPORT_ORDER as $table) {
                $this->reportProgress($progressCallback, $currentStep, $totalSteps, "Restoring {$table}");

                $json = $zip->getFromName("data/{$table}.json");
                if ($json === false) {
                    $currentStep++;
                    continue;
                }

                $rows = json_decode($json, true);
                if (!is_array($rows) || empty($rows)) {
                    $currentStep++;
                    continue;
                }

                $uuidMap[$table] = [];
                $count = 0;

                foreach ($rows as $row) {
                    $oldId = $row['id'] ?? null;
                    $newId = (string) Uuid::v7();

                    if ($oldId) {
                        $uuidMap[$table][$oldId] = $newId;
                        $row['id'] = $newId;
                    }

                    // Remap company_id
                    if (array_key_exists('company_id', $row)) {
                        $row['company_id'] = $targetCompanyId;
                    }

                    // Remap entity FKs
                    foreach (self::FK_REMAP as $column => $sourceTable) {
                        if ($column === 'company_id' || $sourceTable === null) {
                            continue;
                        }
                        if (array_key_exists($column, $row) && $row[$column] !== null) {
                            // For parent_document_id during initial insert, set to null
                            // We'll fix it up in the post-fixup pass
                            if ($column === 'parent_document_id') {
                                $row[$column] = null;
                                continue;
                            }
                            $row[$column] = $uuidMap[$sourceTable][$row[$column]] ?? null;
                        }
                    }

                    // Nullify user FKs
                    foreach (self::USER_FK_COLUMNS as $column) {
                        if (array_key_exists($column, $row)) {
                            $row[$column] = null;
                        }
                    }

                    // Decode base64 content for attachments
                    if ($table === 'invoice_attachment' && isset($row['content_base64'])) {
                        $row['content'] = base64_decode($row['content_base64']);
                        unset($row['content_base64']);
                    }

                    $this->insertRow($conn, $table, $row);
                    $count++;
                }

                $entityCounts[$table] = $count;
                $currentStep++;
            }

            // ── Post-fixup: restore parent_document_id on invoices ──
            $this->reportProgress($progressCallback, $currentStep, $totalSteps, 'Fixing references');

            if (isset($uuidMap['invoice'])) {
                $json = $zip->getFromName('data/invoice.json');
                if ($json !== false) {
                    $invoices = json_decode($json, true);
                    foreach ($invoices as $inv) {
                        if (!empty($inv['parent_document_id']) && !empty($inv['id'])) {
                            $newParentId = $uuidMap['invoice'][$inv['parent_document_id']] ?? null;
                            $newId = $uuidMap['invoice'][$inv['id']] ?? null;
                            if ($newParentId && $newId) {
                                $conn->executeStatement(
                                    'UPDATE invoice SET parent_document_id = ? WHERE id = ?',
                                    [$newParentId, $newId]
                                );
                            }
                        }
                    }
                }
            }
            $currentStep++;

            // ── Restore files ───────────────────────────────────────
            if ($includeFiles && $manifest->includesFiles) {
                $this->reportProgress($progressCallback, $currentStep, $totalSteps, 'Restoring files');
                $this->restoreFiles($conn, $zip, $uuidMap, $targetCompanyId);
                $currentStep++;
            }

            $conn->commit();

            $this->reportProgress($progressCallback, $totalSteps, $totalSteps, 'Complete');
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        } finally {
            $zip->close();
            unlink($tmpFile);
        }

        return $entityCounts;
    }

    private function insertRow(Connection $conn, string $table, array $row): void
    {
        // Filter out any keys with null that don't exist as columns
        $filteredRow = array_filter($row, fn ($v) => $v !== null || true);

        $columns = array_keys($filteredRow);
        $placeholders = array_fill(0, count($columns), '?');

        $quotedColumns = array_map(fn ($c) => "`{$c}`", $columns);

        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            implode(', ', $quotedColumns),
            implode(', ', $placeholders)
        );

        $conn->executeStatement($sql, array_values($filteredRow));
    }

    private function restoreFiles(Connection $conn, \ZipArchive $zip, array $uuidMap, string $targetCompanyId): void
    {
        // Restore invoice files and UPDATE database paths
        $invoiceMap = $uuidMap['invoice'] ?? [];
        foreach ($invoiceMap as $oldId => $newId) {
            $fileMap = [
                'xml_path' => [
                    'zipPath' => "files/invoices/{$oldId}/invoice.xml",
                    'storagePath' => "invoices/{$targetCompanyId}/{$newId}.xml",
                ],
                'pdf_path' => [
                    'zipPath' => "files/invoices/{$oldId}/invoice.pdf",
                    'storagePath' => "invoices/{$targetCompanyId}/{$newId}.pdf",
                ],
                'signature_path' => [
                    'zipPath' => "files/invoices/{$oldId}/signature.p7s",
                    'storagePath' => "signatures/{$targetCompanyId}/{$newId}.p7s",
                ],
            ];

            $dbUpdates = [];

            foreach ($fileMap as $dbColumn => $paths) {
                $content = $zip->getFromName($paths['zipPath']);
                if ($content !== false) {
                    try {
                        $this->defaultStorage->write($paths['storagePath'], $content);
                        $dbUpdates[$dbColumn] = $paths['storagePath'];
                    } catch (\Throwable $e) {
                        $this->logger->warning('Failed to restore invoice file', [
                            'invoiceId' => $newId,
                            'zipPath' => $paths['zipPath'],
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }

            // Update the invoice row with new file paths
            if (!empty($dbUpdates)) {
                $setParts = [];
                $params = [];
                foreach ($dbUpdates as $col => $path) {
                    $setParts[] = "`{$col}` = ?";
                    $params[] = $path;
                }
                $params[] = $newId;
                $conn->executeStatement(
                    sprintf('UPDATE `invoice` SET %s WHERE id = ?', implode(', ', $setParts)),
                    $params
                );
            }
        }

        // Restore attachment files and UPDATE database paths
        $attachmentMap = $uuidMap['invoice_attachment'] ?? [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (str_starts_with($name, 'files/attachments/')) {
                $parts = explode('/', $name);
                if (count($parts) >= 4) {
                    $oldId = $parts[2];
                    $filename = $parts[3];
                    $newId = $attachmentMap[$oldId] ?? null;
                    if ($newId && $filename) {
                        $content = $zip->getFromName($name);
                        if ($content !== false) {
                            try {
                                $storagePath = "attachments/{$targetCompanyId}/{$newId}/{$filename}";
                                $this->defaultStorage->write($storagePath, $content);

                                // Update the attachment row with new storage path
                                $conn->executeStatement(
                                    'UPDATE `invoice_attachment` SET `storage_path` = ? WHERE id = ?',
                                    [$storagePath, $newId]
                                );
                            } catch (\Throwable $e) {
                                $this->logger->warning('Failed to restore attachment', [
                                    'attachmentId' => $newId,
                                    'zipPath' => $name,
                                    'error' => $e->getMessage(),
                                ]);
                            }
                        }
                    }
                }
            }
        }
    }

    private function reportProgress(?callable $callback, int $current, int $total, string $step): void
    {
        if ($callback) {
            $percent = $total > 0 ? (int) round(($current / $total) * 100) : 0;
            $callback($percent, $step);
        }
    }
}
