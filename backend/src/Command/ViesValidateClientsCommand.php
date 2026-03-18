<?php

namespace App\Command;

use App\Service\Vies\ViesService;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:vies-validate-clients',
    description: 'Validate EU VAT numbers via VIES for clients not yet validated (excludes RO)',
)]
class ViesValidateClientsCommand extends Command
{
    private const EU_PREFIXES = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'ES',
        'FI', 'FR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT',
        'NL', 'PL', 'PT', 'SE', 'SI', 'SK',
    ];

    public function __construct(
        private readonly Connection $conn,
        private readonly ViesService $viesService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('company', null, InputOption::VALUE_OPTIONAL, 'Limit to a specific company UUID')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only show what would be validated, without calling VIES')
            ->addOption('delay', null, InputOption::VALUE_OPTIONAL, 'Delay in ms between VIES calls (avoid rate limiting)', '500')
            ->addOption('fix-invoices', null, InputOption::VALUE_NONE, 'Also update existing invoices for validated clients to 0% VAT (reverse charge)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $companyId = $input->getOption('company');
        $dryRun = $input->getOption('dry-run');
        $delayMs = (int) $input->getOption('delay');
        $fixInvoices = $input->getOption('fix-invoices');
        // Only validate clients that have never been checked (vies_valid IS NULL).
        // Clients with vies_valid = 0 were confirmed invalid by VIES — do not retry.
        $viesCondition = 'vies_valid IS NULL';
        $sql = <<<SQL
            SELECT id, name, vat_code, cui, country, company_id
            FROM client
            WHERE deleted_at IS NULL
              AND {$viesCondition}
              AND (
                (vat_code IS NOT NULL AND vat_code != '')
                OR (cui IS NOT NULL AND cui != '')
              )
        SQL;

        $params = [];
        if ($companyId) {
            $sql .= ' AND company_id = :companyId';
            $params['companyId'] = $companyId;
        }

        $sql .= ' ORDER BY company_id, name';

        $allClients = $this->conn->fetchAllAssociative($sql, $params);
        $seen = [];
        $toValidate = [];
        foreach ($allClients as $c) {
            if (isset($seen[$c['id']])) {
                continue;
            }
            $seen[$c['id']] = true;

            // Determine country code and VAT number to check
            $vatCode = $c['vat_code'] ?? '';
            $country = strtoupper(trim($c['country'] ?? ''));
            $cui = $c['cui'] ?? '';

            $checkCountry = null;
            $checkVatNum = null;

            // 1. Try vatCode (e.g. "DE274281064")
            if ($vatCode) {
                $parsed = $this->viesService->parseVatCode($vatCode);
                if ($parsed && in_array($parsed['countryCode'], self::EU_PREFIXES, true)) {
                    $checkCountry = $parsed['countryCode'];
                    $checkVatNum = $parsed['vatNumber'];
                }
            }

            // 2. Try CUI field — might contain full VAT number with EU prefix (e.g. imported as "DE274281064")
            if (!$checkCountry && $cui) {
                $parsed = $this->viesService->parseVatCode($cui);
                if ($parsed && in_array($parsed['countryCode'], self::EU_PREFIXES, true)) {
                    $checkCountry = $parsed['countryCode'];
                    $checkVatNum = $parsed['vatNumber'];
                }
            }

            // 3. Try CUI + country (e.g. cui="274281064", country="DE")
            if (!$checkCountry && $country && $cui && in_array($country, self::EU_PREFIXES, true)) {
                $checkCountry = $country;
                $checkVatNum = $cui;
            }

            // Skip RO — validated via ANAF, not VIES
            if ($checkCountry === 'RO') {
                continue;
            }

            // Map Greece country code
            if ($checkCountry === 'GR') {
                $checkCountry = 'EL';
            }

            if ($checkCountry && $checkVatNum) {
                $c['_checkCountry'] = $checkCountry;
                $c['_checkVatNum'] = $checkVatNum;
                $toValidate[] = $c;
            }
        }

        $output->writeln(sprintf('Found <info>%d</info> clients to validate', count($toValidate)));

        if (empty($toValidate)) {
            return Command::SUCCESS;
        }

        if ($dryRun) {
            foreach ($toValidate as $c) {
                $output->writeln(sprintf(
                    '  [DRY-RUN] %s — %s%s (company: %s)',
                    $c['name'],
                    $c['_checkCountry'],
                    $c['_checkVatNum'],
                    substr($c['company_id'], 0, 8),
                ));
            }
            return Command::SUCCESS;
        }

        $valid = 0;
        $invalid = 0;
        $errors = 0;
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        foreach ($toValidate as $i => $c) {
            $result = $this->viesService->validate($c['_checkCountry'], $c['_checkVatNum']);

            if ($result === null) {
                $output->writeln(sprintf(
                    '  <comment>[%d/%d] %s — %s%s — VIES API error (skipped)</comment>',
                    $i + 1, count($toValidate), $c['name'], $c['_checkCountry'], $c['_checkVatNum'],
                ));
                $errors++;
            } elseif ($result['valid']) {
                $countryFromPrefix = $c['_checkCountry'] === 'EL' ? 'GR' : $c['_checkCountry'];
                $fullVatCode = $c['_checkCountry'] . $c['_checkVatNum'];

                // Update client: VIES valid, VAT payer, fix country and vatCode
                $this->conn->executeStatement(
                    'UPDATE client SET vies_valid = 1, is_vat_payer = 1, vies_validated_at = :now, vat_code = :vatCode, cui = :cui, country = :country WHERE id = :id',
                    [
                        'now' => $now,
                        'vatCode' => $fullVatCode,
                        'cui' => $c['_checkVatNum'],
                        'country' => $countryFromPrefix,
                        'id' => $c['id'],
                    ],
                );

                $output->writeln(sprintf(
                    '  <info>[%d/%d] %s — %s%s — VALID</info>%s',
                    $i + 1, count($toValidate), $c['name'], $c['_checkCountry'], $c['_checkVatNum'],
                    $result['name'] ? " ({$result['name']})" : '',
                ));
                $valid++;
            } else {
                $this->conn->executeStatement(
                    'UPDATE client SET vies_valid = 0, vies_validated_at = :now WHERE id = :id',
                    ['now' => $now, 'id' => $c['id']],
                );
                $output->writeln(sprintf(
                    '  <error>[%d/%d] %s — %s%s — INVALID</error>',
                    $i + 1, count($toValidate), $c['name'], $c['_checkCountry'], $c['_checkVatNum'],
                ));
                $invalid++;
            }

            // Rate limit
            if ($delayMs > 0 && $i < count($toValidate) - 1) {
                usleep($delayMs * 1000);
            }
        }

        $output->writeln('');
        $output->writeln(sprintf('Done: <info>%d valid</info>, <error>%d invalid</error>, <comment>%d errors</comment>', $valid, $invalid, $errors));

        // Fix existing invoices for all VIES-valid clients
        if ($fixInvoices && $valid > 0) {
            $output->writeln('');
            $output->writeln('<info>Fixing invoices for VIES-valid clients...</info>');
            $fixed = $this->fixInvoicesForViesClients($companyId, $output);
            $output->writeln(sprintf('Fixed <info>%d</info> invoices', $fixed));
        }

        return Command::SUCCESS;
    }

    /**
     * Update invoices for VIES-valid EU clients (non-RO) to 0% VAT (reverse charge).
     * Recalculates line-level and invoice-level totals.
     */
    private function fixInvoicesForViesClients(?string $companyId, OutputInterface $output): int
    {
        // Find outgoing invoices linked to VIES-valid non-RO clients that still have VAT > 0
        $sql = <<<'SQL'
            SELECT i.id, i.number, i.total, i.vat_total, i.subtotal, c.name as client_name
            FROM invoice i
            INNER JOIN client c ON i.client_id = c.id
            WHERE i.deleted_at IS NULL
              AND i.direction = 'outgoing'
              AND c.vies_valid = 1
              AND c.country != 'RO'
              AND i.vat_total > 0
              AND i.issue_date >= :monthStart
              AND i.issue_date <= :monthEnd
        SQL;

        $now = new \DateTimeImmutable();
        $params = [
            'monthStart' => $now->format('Y-m-01'),
            'monthEnd' => $now->format('Y-m-t'),
        ];
        if ($companyId) {
            $sql .= ' AND i.company_id = :companyId';
            $params['companyId'] = $companyId;
        }

        $invoices = $this->conn->fetchAllAssociative($sql, $params);
        $fixed = 0;

        foreach ($invoices as $inv) {
            // Update all lines to 0% VAT with reverse charge category
            $this->conn->executeStatement(
                "UPDATE invoice_line SET vat_rate = '0.00', vat_amount = '0.00', vat_category_code = 'AE' WHERE invoice_id = :invoiceId",
                ['invoiceId' => $inv['id']],
            );

            // Recalculate invoice totals from lines
            $lineTotals = $this->conn->fetchAssociative(
                'SELECT COALESCE(SUM(line_total), 0) as subtotal, COALESCE(SUM(vat_amount), 0) as vat_total FROM invoice_line WHERE invoice_id = :invoiceId',
                ['invoiceId' => $inv['id']],
            );

            $subtotal = number_format((float) ($lineTotals['subtotal'] ?? $inv['subtotal']), 2, '.', '');
            $vatTotal = '0.00';
            $total = $subtotal; // total = subtotal when VAT is 0

            $this->conn->executeStatement(
                'UPDATE invoice SET subtotal = :subtotal, vat_total = :vatTotal, total = :total, amount_paid = CASE WHEN paid_at IS NOT NULL THEN :total ELSE amount_paid END WHERE id = :id',
                ['subtotal' => $subtotal, 'vatTotal' => $vatTotal, 'total' => $total, 'id' => $inv['id']],
            );

            // Also update payment amount if there's an auto-import payment
            $this->conn->executeStatement(
                "UPDATE payment SET amount = :total WHERE invoice_id = :invoiceId AND reference = 'Import automat'",
                ['total' => $total, 'invoiceId' => $inv['id']],
            );

            $output->writeln(sprintf(
                '  %s (%s) — VAT %s → 0.00, total %s → %s',
                $inv['number'], $inv['client_name'],
                $inv['vat_total'], $inv['total'], $total,
            ));

            $fixed++;
        }

        return $fixed;
    }
}
