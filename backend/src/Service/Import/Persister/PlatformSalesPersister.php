<?php

namespace App\Service\Import\Persister;

use App\Entity\Client;
use App\Entity\Company;
use App\Entity\ImportJob;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\Supplier;
use App\Enum\DocumentStatus;
use App\Enum\DocumentType;
use App\Enum\InvoiceDirection;
use App\Enum\InvoiceTypeCode;
use App\Repository\VatRateRepository;
use App\Service\ExchangeRateService;
use App\Service\Import\ImportResult;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Turns the rows of a platform statement (Uber, Bolt, Glovo, Tazz) into
 * documents: per group (`groupBy` = week | day | order) one sales invoice to
 * the platform for what was collected from the end customers and, when the
 * statement carries it, one purchase invoice from the platform for its
 * commission.
 *
 * VAT: the platform's commission from an EU entity is a reverse-charge
 * purchase (tax category AE, no VAT amount, invoice type services art. 278)
 * when the company is a VAT payer, and a plain expense otherwise; sales to an
 * EU platform are intra-community services under the same rule; sales to and
 * commissions from a Romanian platform carry the VAT of the export, matched
 * against the company's VAT rates.
 *
 * Idempotency: every document carries a key made of the platform, the company
 * and the group; a group already imported is skipped, so re-uploading the
 * same statement creates nothing.
 */
class PlatformSalesPersister implements EntityPersisterInterface, SummaryProviderInterface
{
    public const IMPORT_TYPE = 'platform_sales';

    private const EU = ['AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'GR', 'ES', 'FI', 'FR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK'];

    private const LABELS = [
        'uber'  => ['platform' => 'Uber', 'sales' => 'Servicii de transport persoane prin platforma Uber'],
        'bolt'  => ['platform' => 'Bolt', 'sales' => 'Servicii de transport persoane prin platforma Bolt'],
        'glovo' => ['platform' => 'Glovo', 'sales' => 'Vânzări prin platforma Glovo'],
        'tazz'  => ['platform' => 'Tazz', 'sales' => 'Vânzări prin platforma Tazz'],
    ];

    /** @var array<string, array<string, mixed>> groupKey => accumulator */
    private array $groups = [];

    /** @var array<string, true> */
    private array $existingKeys = [];

    /** @var array<string, true> */
    private array $existingNumbers = [];

    /** @var array<string, string> lookup key => client uuid */
    private array $clientCache = [];

    /** @var array<string, string> lookup key => supplier uuid */
    private array $supplierCache = [];

    /** @var float[] */
    private array $companyRates = [];

    private ?Company $company = null;
    private ?ImportJob $job = null;
    private int $rowsAggregated = 0;
    private int $rowsSkipped = 0;

    /** @var array<int, array{number: string, type: string, total: string, currency: string}> */
    private array $documents = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly VatRateRepository $vatRateRepository,
        private readonly ?ExchangeRateService $exchangeRateService = null,
    ) {}

    public function supports(string $importType): bool
    {
        return $importType === self::IMPORT_TYPE;
    }

    public function persist(array $mappedData, Company $company, ImportResult $result): void
    {
        $this->initialize($company, $mappedData['_importJob'] ?? null);

        $options = $mappedData['_importOptions'] ?? [];
        $groupBy = in_array($options['groupBy'] ?? null, ['week', 'day', 'order', 'trip', 'month'], true) ? $options['groupBy'] : 'week';
        if ($groupBy === 'trip') {
            $groupBy = 'order';
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($mappedData['date'] ?? ''));
        if ($date === false) {
            throw new \RuntimeException(sprintf('Data "%s" nu a putut fi interpretată (format așteptat: AAAA-LL-ZZ).', $mappedData['date'] ?? ''));
        }

        $externalId = trim((string) ($mappedData['externalId'] ?? ''));
        $groupKey = match ($groupBy) {
            'day'   => $date->format('Y-m-d'),
            'month' => $date->format('Y-m'),
            'order' => $externalId !== '' ? $externalId : $date->format('Y-m-d'),
            default => $date->format('o-\WW'),
        };

        $source = (string) ($mappedData['_source'] ?? $mappedData['platform'] ?? 'platform');
        $saleKey = $this->keyFor($source, 'sales', $groupKey);

        if (isset($this->existingKeys[$saleKey])) {
            $this->rowsSkipped++;
            $result->incrementSkipped();

            return;
        }

        $currency = strtoupper((string) ($mappedData['currency'] ?? '')) ?: strtoupper($company->getDefaultCurrency() ?: 'RON');

        if (!isset($this->groups[$groupKey])) {
            $this->groups[$groupKey] = [
                'groupBy'   => $groupBy,
                'source'    => $source,
                'from'      => $date,
                'to'        => $date,
                'count'     => 0,
                'gross'     => 0.0,
                'tips'      => 0.0,
                'tolls'     => 0.0,
                'vat'       => 0.0,
                'commission' => 0.0,
                'commissionVat' => 0.0,
                'payout'    => 0.0,
                'currency'  => $currency,
                'ids'       => [],
                'platform'  => $this->resolvePlatform($mappedData, $options),
                'options'   => $options,
            ];
        }

        $g = &$this->groups[$groupKey];
        $g['count']++;
        $g['from'] = min($g['from'], $date);
        $g['to'] = max($g['to'], $date);
        foreach (['gross', 'tips', 'tolls', 'vat', 'commission', 'commissionVat', 'payout'] as $f) {
            $g[$f] += (float) ($mappedData[$f] ?? 0);
        }
        if ($externalId !== '' && !in_array($externalId, $g['ids'], true)) {
            $g['ids'][] = $externalId;
        }
        unset($g);

        $this->rowsAggregated++;
    }

    public function flush(): void
    {
        if ($this->company === null) {
            return;
        }

        foreach ($this->groups as $groupKey => $group) {
            $this->buildDocuments($groupKey, $group);
        }
        $this->groups = [];

        $this->entityManager->flush();
    }

    public function reset(): void
    {
        $this->groups = [];
        $this->existingKeys = [];
        $this->existingNumbers = [];
        $this->clientCache = [];
        $this->supplierCache = [];
        $this->companyRates = [];
        $this->company = null;
        $this->job = null;
        $this->rowsAggregated = 0;
        $this->rowsSkipped = 0;
        $this->documents = [];
    }

    /**
     * Summary for the import job: documents created, rows aggregated.
     *
     * @return array<string, mixed>
     */
    public function getSummary(): array
    {
        $sales = array_values(array_filter($this->documents, fn ($d) => $d['type'] === 'sales'));
        $commissions = array_values(array_filter($this->documents, fn ($d) => $d['type'] === 'commission'));

        return [
            'rowsAggregated'     => $this->rowsAggregated,
            'rowsSkipped'        => $this->rowsSkipped,
            'documentsCreated'   => count($this->documents),
            'salesInvoices'      => count($sales),
            'commissionInvoices' => count($commissions),
            'documents'          => array_slice($this->documents, 0, 100),
        ];
    }

    // -------------------------------------------------------------------------

    private function initialize(Company $company, ?ImportJob $job): void
    {
        if ($this->company !== null && $this->company->getId()->equals($company->getId())) {
            $this->job ??= $job;

            return;
        }

        $this->company = $company;
        $this->job = $job;
        $companyId = $company->getId()->toRfc4122();
        $conn = $this->entityManager->getConnection();

        $rows = $conn->fetchAllAssociative(
            'SELECT idempotency_key, LOWER(number) AS number FROM invoice WHERE company_id = :c AND deleted_at IS NULL',
            ['c' => $companyId],
        );
        foreach ($rows as $row) {
            if (!empty($row['idempotency_key'])) {
                $this->existingKeys[$row['idempotency_key']] = true;
            }
            if (!empty($row['number'])) {
                $this->existingNumbers[$row['number']] = true;
            }
        }

        foreach ($conn->fetchAllAssociative('SELECT id, LOWER(name) AS name, cui, vat_code FROM client WHERE company_id = :c AND deleted_at IS NULL', ['c' => $companyId]) as $row) {
            $this->clientCache['name:' . $row['name']] = $row['id'];
            if (!empty($row['cui'])) {
                $this->clientCache['cif:' . self::normalizeCif($row['cui'])] = $row['id'];
            }
            if (!empty($row['vat_code'])) {
                $this->clientCache['cif:' . self::normalizeCif($row['vat_code'])] = $row['id'];
            }
        }

        foreach ($conn->fetchAllAssociative('SELECT id, LOWER(name) AS name, cif, vat_code FROM supplier WHERE company_id = :c AND deleted_at IS NULL', ['c' => $companyId]) as $row) {
            $this->supplierCache['name:' . $row['name']] = $row['id'];
            if (!empty($row['cif'])) {
                $this->supplierCache['cif:' . self::normalizeCif($row['cif'])] = $row['id'];
            }
            if (!empty($row['vat_code'])) {
                $this->supplierCache['cif:' . self::normalizeCif($row['vat_code'])] = $row['id'];
            }
        }

        foreach ($this->vatRateRepository->findActiveByCompany($company) as $rate) {
            $this->companyRates[] = (float) $rate->getRate();
        }
    }

    /**
     * @param array<string, mixed> $mappedData
     * @param array<string, mixed> $options
     * @return array{name: string, country: string, cif: string|null}
     */
    private function resolvePlatform(array $mappedData, array $options): array
    {
        $defaults = $mappedData['platformDefaults'] ?? ['name' => ucfirst((string) ($mappedData['platform'] ?? 'Platformă')), 'country' => 'RO', 'cif' => null];

        $name = trim((string) ($options['platformName'] ?? '')) ?: $defaults['name'];
        $cif = trim((string) ($options['platformCif'] ?? '')) ?: ($defaults['cif'] ?? null);
        $country = strtoupper(trim((string) ($options['platformCountry'] ?? ''))) ?: $defaults['country'];
        if ($cif && preg_match('/^[A-Z]{2}/', strtoupper($cif)) && empty($options['platformCountry'])) {
            $country = strtoupper(substr($cif, 0, 2));
            if ($country === 'EL') {
                $country = 'GR';
            }
        }

        return ['name' => $name, 'country' => $country, 'cif' => $cif ?: null];
    }

    /**
     * @param array<string, mixed> $group
     */
    private function buildDocuments(string $groupKey, array $group): void
    {
        $company = $this->company;
        $source = $group['source'];
        $labels = self::LABELS[$source] ?? ['platform' => ucfirst($source), 'sales' => 'Vânzări prin platforma ' . ucfirst($source)];
        $platform = $group['platform'];
        $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $source) ?: 'PLATFORM');
        $groupLabel = preg_replace('/[^A-Za-z0-9\-]/', '', $groupKey) ?: $groupKey;
        $period = $this->periodLabel($group);
        // Statements carry more than one row per trip / order (a fare, a tip, an
        // adjustment), so the documents count the distinct ids when they exist.
        $units = count($group['ids']) ?: $group['count'];
        $countLabel = sprintf('%d %s', $units, in_array($source, ['uber', 'bolt'], true) ? ($units === 1 ? 'cursă' : 'curse') : ($units === 1 ? 'comandă' : 'comenzi'));

        $isEuForeign = $platform['country'] !== 'RO' && in_array($platform['country'], self::EU, true);
        $vatPayer = $company->isVatPayer();
        $reverseCharge = $isEuForeign && $vatPayer;
        $exchangeRate = $this->exchangeRate($group['currency'], $group['to']);

        // ---- Sales invoice -------------------------------------------------
        $saleKey = $this->keyFor($source, 'sales', $groupKey);
        $number = sprintf('%s-%s', $prefix, $groupLabel);
        if (!isset($this->existingKeys[$saleKey]) && !isset($this->existingNumbers[mb_strtolower($number)]) && $group['gross'] + $group['tips'] + $group['tolls'] > 0) {
            $invoice = $this->newInvoice($number, InvoiceDirection::OUTGOING, $group['to'], $group['currency'], $exchangeRate);
            $invoice->setSenderName($company->getName());
            $invoice->setSenderCif((string) $company->getCif());
            $invoice->setReceiverName($platform['name']);
            if ($platform['cif']) {
                $invoice->setReceiverCif($platform['cif']);
            }
            $invoice->setClient($this->clientFor($platform));
            $invoice->setIdempotencyKey($saleKey);
            $invoice->setNotes(sprintf('Import %s — %s, %s. ID: %s', $labels['platform'], $period, $countLabel, mb_substr(implode(', ', $group['ids']), 0, 900)));

            $position = 0;
            [$net, $vat, $rate, $category] = $this->salesVat($group['gross'], $group['vat'], $platform['country'], $vatPayer);
            $this->addLine($invoice, $position++, sprintf('%s — %s (%s)', $labels['sales'], $period, $countLabel), $net, $vat, $rate, $category);
            if ($group['tips'] > 0) {
                [$n, $v, $r, $c] = $this->outsideVat($group['tips'], $platform['country'], $vatPayer);
                $this->addLine($invoice, $position++, sprintf('Bacșiș — %s', $period), $n, $v, $r, $c);
            }
            if ($group['tolls'] > 0) {
                [$n, $v, $r, $c] = $this->outsideVat($group['tolls'], $platform['country'], $vatPayer);
                $this->addLine($invoice, $position++, sprintf('Taxe de drum și alte sume rambursate — %s', $period), $n, $v, $r, $c);
            }
            if ($reverseCharge) {
                $invoice->setInvoiceTypeCode(InvoiceTypeCode::SERVICES_ART_278->value);
            }
            $this->finalizeTotals($invoice);
            $this->entityManager->persist($invoice);
            $this->existingKeys[$saleKey] = true;
            $this->existingNumbers[mb_strtolower($number)] = true;
            $this->documents[] = ['number' => $number, 'type' => 'sales', 'total' => $invoice->getTotal(), 'currency' => $group['currency']];
        }

        // ---- Commission purchase invoice -----------------------------------
        if ($group['commission'] > 0) {
            $commissionKey = $this->keyFor($source, 'commission', $groupKey);
            $number = sprintf('%s-COM-%s', $prefix, $groupLabel);
            if (!isset($this->existingKeys[$commissionKey]) && !isset($this->existingNumbers[mb_strtolower($number)])) {
                $invoice = $this->newInvoice($number, InvoiceDirection::INCOMING, $group['to'], $group['currency'], $exchangeRate);
                $invoice->setSenderName($platform['name']);
                if ($platform['cif']) {
                    $invoice->setSenderCif($platform['cif']);
                }
                $invoice->setReceiverName($company->getName());
                $invoice->setReceiverCif((string) $company->getCif());
                $invoice->setSupplier($this->supplierFor($platform));
                $invoice->setIdempotencyKey($commissionKey);
                $invoice->setNotes(sprintf('Import %s — comision %s, %s.', $labels['platform'], $period, $countLabel));

                if ($reverseCharge) {
                    $this->addLine($invoice, 0, sprintf('Comision platformă %s — %s', $labels['platform'], $period), $group['commission'], 0.0, 0.0, 'AE');
                    $invoice->setInvoiceTypeCode(InvoiceTypeCode::SERVICES_ART_278->value);
                } elseif ($isEuForeign || !$vatPayer) {
                    // Plain expense: whatever VAT the platform charged is part of the cost.
                    $this->addLine($invoice, 0, sprintf('Comision platformă %s — %s', $labels['platform'], $period), $group['commission'] + $group['commissionVat'], 0.0, 0.0, 'O');
                } else {
                    $rate = $this->matchRate($group['commission'] > 0 ? $group['commissionVat'] / $group['commission'] * 100 : 0.0);
                    $this->addLine($invoice, 0, sprintf('Comision platformă %s — %s', $labels['platform'], $period), $group['commission'], $group['commissionVat'], $rate, $rate > 0 ? 'S' : 'E');
                }
                $this->finalizeTotals($invoice);
                $this->entityManager->persist($invoice);
                $this->existingKeys[$commissionKey] = true;
                $this->existingNumbers[mb_strtolower($number)] = true;
                $this->documents[] = ['number' => $number, 'type' => 'commission', 'total' => $invoice->getTotal(), 'currency' => $group['currency']];
            }
        }
    }

    /**
     * Sales VAT split for the platform's country and the company's VAT status.
     *
     * @return array{0: float, 1: float, 2: float, 3: string} net, vat, rate, category
     */
    private function salesVat(float $gross, float $vatFromExport, string $platformCountry, bool $vatPayer): array
    {
        if (!$vatPayer) {
            return [$gross, 0.0, 0.0, 'O'];
        }
        if ($platformCountry !== 'RO' && in_array($platformCountry, self::EU, true)) {
            return [$gross, 0.0, 0.0, 'AE'];
        }
        if ($platformCountry !== 'RO') {
            return [$gross, 0.0, 0.0, 'O'];
        }

        // Romanian platform: the export's VAT, or the default rate included in the gross amount
        if ($vatFromExport > 0 && $vatFromExport < $gross) {
            $net = $gross - $vatFromExport;
            $rate = $this->matchRate($vatFromExport / $net * 100);

            return [$net, $vatFromExport, $rate, $rate > 0 ? 'S' : 'E'];
        }

        $rate = $this->defaultRate();
        $net = $rate > 0 ? round($gross / (1 + $rate / 100), 2) : $gross;

        return [$net, round($gross - $net, 2), $rate, $rate > 0 ? 'S' : 'E'];
    }

    /**
     * Tips and reimbursed amounts: outside the VAT base for a Romanian
     * platform, same treatment as the service for an EU one.
     *
     * @return array{0: float, 1: float, 2: float, 3: string}
     */
    private function outsideVat(float $amount, string $platformCountry, bool $vatPayer): array
    {
        if ($vatPayer && $platformCountry !== 'RO' && in_array($platformCountry, self::EU, true)) {
            return [$amount, 0.0, 0.0, 'AE'];
        }

        return [$amount, 0.0, 0.0, 'O'];
    }

    private function matchRate(float $computed): float
    {
        $candidates = $this->companyRates ?: [21.0, 11.0, 5.0, 0.0];
        $best = $candidates[0];
        foreach ($candidates as $rate) {
            if (abs($rate - $computed) < abs($best - $computed)) {
                $best = $rate;
            }
        }

        return abs($best - $computed) <= 1.5 ? $best : round($computed, 2);
    }

    private function defaultRate(): float
    {
        $default = $this->vatRateRepository->findDefaultByCompany($this->company);
        if ($default !== null) {
            return (float) $default->getRate();
        }

        return $this->companyRates === [] ? 21.0 : max($this->companyRates);
    }

    private function newInvoice(string $number, InvoiceDirection $direction, \DateTimeImmutable $issueDate, string $currency, ?float $exchangeRate): Invoice
    {
        $invoice = new Invoice();
        $invoice->setCompany($this->company);
        $invoice->setNumber($number);
        $invoice->setDocumentType(DocumentType::INVOICE);
        $invoice->setStatus(DocumentStatus::SYNCED);
        $invoice->setDirection($direction);
        $invoice->setIssueDate(\DateTime::createFromImmutable($issueDate));
        $invoice->setDueDate(\DateTime::createFromImmutable($issueDate));
        $invoice->setCurrency($currency);
        if ($exchangeRate !== null) {
            $invoice->setExchangeRate(number_format($exchangeRate, 4, '.', ''));
        }
        if ($this->job !== null) {
            $invoice->setImportJob($this->job);
        }

        return $invoice;
    }

    private function addLine(Invoice $invoice, int $position, string $description, float $net, float $vat, float $rate, string $category): void
    {
        $line = new InvoiceLine();
        $line->setPosition($position);
        $line->setDescription(mb_substr($description, 0, 500));
        $line->setQuantity('1.0000');
        $line->setUnitOfMeasure('buc');
        $line->setUnitPrice(number_format($net, 2, '.', ''));
        $line->setVatRate(number_format($rate, 2, '.', ''));
        $line->setVatCategoryCode($category);
        $line->setVatAmount(number_format($vat, 2, '.', ''));
        $line->setLineTotal(number_format($net, 2, '.', ''));
        $invoice->addLine($line);
    }

    private function finalizeTotals(Invoice $invoice): void
    {
        $subtotal = 0.0;
        $vat = 0.0;
        foreach ($invoice->getLines() as $line) {
            $subtotal += (float) $line->getLineTotal();
            $vat += (float) $line->getVatAmount();
        }
        $invoice->setSubtotal(number_format($subtotal, 2, '.', ''));
        $invoice->setVatTotal(number_format($vat, 2, '.', ''));
        $invoice->setTotal(number_format($subtotal + $vat, 2, '.', ''));
    }

    /**
     * @param array{name: string, country: string, cif: string|null} $platform
     */
    private function clientFor(array $platform): Client
    {
        $id = null;
        if ($platform['cif']) {
            $id = $this->clientCache['cif:' . self::normalizeCif($platform['cif'])] ?? null;
        }
        $id ??= $this->clientCache['name:' . mb_strtolower($platform['name'])] ?? null;
        if ($id !== null) {
            return $this->entityManager->getReference(Client::class, Uuid::fromString($id));
        }

        $client = new Client();
        $client->setCompany($this->company);
        $client->setName($platform['name']);
        $client->setType('company');
        $client->setCountry($platform['country']);
        if ($platform['cif']) {
            $cif = strtoupper($platform['cif']);
            if ($platform['country'] === 'RO') {
                $client->setCui(preg_replace('/^RO/i', '', $cif));
                $client->setVatCode($cif);
            } else {
                $client->setCui($cif);
                $client->setVatCode($cif);
            }
            $client->setIsVatPayer(true);
        }
        $client->setSource('import:' . ($this->job?->getSource() ?? 'platform'));
        if ($this->job !== null) {
            $client->setImportJob($this->job);
        }
        $this->entityManager->persist($client);

        $this->clientCache['name:' . mb_strtolower($platform['name'])] = $client->getId()->toRfc4122();
        if ($platform['cif']) {
            $this->clientCache['cif:' . self::normalizeCif($platform['cif'])] = $client->getId()->toRfc4122();
        }

        return $client;
    }

    /**
     * Existing suppliers only: the commission invoice keeps the platform's
     * name / VAT id in its sender fields either way.
     *
     * @param array{name: string, country: string, cif: string|null} $platform
     */
    private function supplierFor(array $platform): ?Supplier
    {
        $id = null;
        if ($platform['cif']) {
            $id = $this->supplierCache['cif:' . self::normalizeCif($platform['cif'])] ?? null;
        }
        $id ??= $this->supplierCache['name:' . mb_strtolower($platform['name'])] ?? null;

        return $id !== null ? $this->entityManager->getReference(Supplier::class, Uuid::fromString($id)) : null;
    }

    private function exchangeRate(string $currency, \DateTimeImmutable $date): ?float
    {
        if ($currency === 'RON' || $this->exchangeRateService === null) {
            return null;
        }
        try {
            $rate = $this->exchangeRateService->getRateForDate($currency, $date);
            if ($rate === null) {
                $live = $this->exchangeRateService->getRate($currency);

                return $live !== null ? (float) $live : null;
            }

            return (float) $rate['rate'];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $group
     */
    private function periodLabel(array $group): string
    {
        /** @var \DateTimeImmutable $from */
        $from = $group['from'];
        /** @var \DateTimeImmutable $to */
        $to = $group['to'];

        return match ($group['groupBy']) {
            'day'   => $to->format('d.m.Y'),
            'month' => $to->format('m.Y'),
            'order' => sprintf('%s din %s', $group['ids'][0] ?? 'comandă', $to->format('d.m.Y')),
            default => sprintf('săptămâna %d/%d (%s – %s)', (int) $to->format('W'), (int) $to->format('o'), $from->format('d.m.Y'), $to->format('d.m.Y')),
        };
    }

    private function keyFor(string $source, string $kind, string $groupKey): string
    {
        return hash('sha256', sprintf('import:platform:%s:%s:%s:%s', $source, $this->company->getId()->toRfc4122(), $kind, $groupKey));
    }

    private static function normalizeCif(string $cif): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $cif) ?? $cif);
    }
}
