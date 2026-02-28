<?php

namespace App\Service\Import\Persister;

use App\Entity\Client;
use App\Entity\Company;
use App\Entity\DocumentSeries;
use App\Entity\RecurringInvoice;
use App\Entity\RecurringInvoiceLine;
use App\Service\Import\ImportResult;
use App\Services\AnafService;
use App\Util\AddressNormalizer;
use Doctrine\ORM\EntityManagerInterface;

class RecurringInvoicePersister implements EntityPersisterInterface
{
    private const BATCH_SIZE = 20;
    private int $batchCount = 0;

    /**
     * In-memory dedup cache keyed by companyId:reference:clientCif.
     *
     * @var array<string, RecurringInvoice>
     */
    private array $pendingCache = [];

    /**
     * Cache for resolved clients by companyId:cif.
     *
     * @var array<string, Client|null>
     */
    private array $clientCache = [];

    /**
     * Cache for resolved series by companyId:name.
     *
     * @var array<string, DocumentSeries|null>
     */
    private array $seriesCache = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AnafService $anafService,
    ) {}

    public function supports(string $importType): bool
    {
        return $importType === 'recurring_invoices';
    }

    public function persist(array $mappedData, Company $company, ImportResult $result): void
    {
        $clientName = $mappedData['clientName'] ?? null;
        if (empty($clientName)) {
            return;
        }

        $clientCif = !empty($mappedData['clientCif']) ? trim($mappedData['clientCif']) : '';
        $reference = $mappedData['reference'] ?? '';

        // Build dedup key: reference + clientCif (or clientName as fallback)
        $dedupKey = implode(':', [
            $company->getId()->toRfc4122(),
            mb_strtolower($reference),
            $clientCif ?: mb_strtolower($clientName),
        ]);

        // Within-batch duplicate: append lines (multi-line recurring invoices)
        if (isset($this->pendingCache[$dedupKey])) {
            $existing = $this->pendingCache[$dedupKey];
            $lines = $mappedData['lines'] ?? [];
            $currentPosition = $existing->getLines()->count();
            foreach ($lines as $i => $lineData) {
                $line = $this->buildLine($lineData, $currentPosition + $i);
                $existing->addLine($line);
            }
            return;
        }

        // Database check: find existing recurring invoice with same reference + client
        $existingInDb = $this->findExisting($company, $reference, $clientCif, $clientName);
        if ($existingInDb !== null) {
            $this->pendingCache[$dedupKey] = $existingInDb;
            $result->incrementSkipped();
            return;
        }

        // Create the recurring invoice
        $recurringInvoice = $this->buildRecurringInvoice($mappedData, $company);
        $this->entityManager->persist($recurringInvoice);

        // Create line items
        $lines = $mappedData['lines'] ?? [];
        foreach ($lines as $position => $lineData) {
            $line = $this->buildLine($lineData, $position);
            $recurringInvoice->addLine($line);
        }

        $this->pendingCache[$dedupKey] = $recurringInvoice;
        $result->incrementCreated();

        $this->batchCount++;
        if ($this->batchCount >= self::BATCH_SIZE) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        $this->entityManager->flush();
        $this->batchCount = 0;
    }

    public function reset(): void
    {
        $this->pendingCache = [];
        $this->clientCache = [];
        $this->seriesCache = [];
        $this->batchCount = 0;
    }

    private function findExisting(Company $company, string $reference, string $clientCif, string $clientName): ?RecurringInvoice
    {
        if (empty($reference) && empty($clientCif) && empty($clientName)) {
            return null;
        }

        $qb = $this->entityManager->createQueryBuilder()
            ->select('ri')
            ->from(RecurringInvoice::class, 'ri')
            ->leftJoin('ri.client', 'c')
            ->where('ri.company = :company')
            ->andWhere('ri.deletedAt IS NULL')
            ->setParameter('company', $company);

        if (!empty($reference)) {
            $qb->andWhere('ri.reference = :reference')
                ->setParameter('reference', $reference);
        }

        if (!empty($clientCif)) {
            $qb->andWhere('c.cui = :cui')
                ->setParameter('cui', preg_replace('/^RO/i', '', $clientCif));
        } elseif (!empty($clientName)) {
            $qb->andWhere('c.name = :clientName')
                ->setParameter('clientName', $clientName);
        }

        return $qb->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    private function buildRecurringInvoice(array $data, Company $company): RecurringInvoice
    {
        $ri = new RecurringInvoice();
        $ri->setCompany($company);
        $ri->setIsActive(false);

        // Reference
        if (!empty($data['reference'])) {
            $ri->setReference($data['reference']);
        }

        // Currency
        if (!empty($data['currency'])) {
            $ri->setCurrency(strtoupper(trim($data['currency'])));
        }

        // Frequency
        $ri->setFrequency($data['frequency'] ?? 'monthly');

        // Frequency day
        if (isset($data['frequencyDay']) && $data['frequencyDay'] !== '') {
            $ri->setFrequencyDay((int) $data['frequencyDay']);
        }

        // Next issuance date — use imported value or null (don't default to today)
        if (!empty($data['nextIssuanceDate'])) {
            try {
                $ri->setNextIssuanceDate(new \DateTime($data['nextIssuanceDate']));
            } catch (\Exception) {
                $ri->setNextIssuanceDate(null);
            }
        } else {
            $ri->setNextIssuanceDate(null);
        }

        // Due date configuration
        if (!empty($data['dueDateDays']) && (int) $data['dueDateDays'] > 0) {
            $ri->setDueDateType('days');
            $ri->setDueDateDays((int) $data['dueDateDays']);
        } elseif (!empty($data['dueDateFixedDay']) && (int) $data['dueDateFixedDay'] > 0) {
            $ri->setDueDateType('fixed_day');
            $ri->setDueDateFixedDay((int) $data['dueDateFixedDay']);
        }

        // Penalty settings
        if (!empty($data['penaltyEnabled'])) {
            $ri->setPenaltyEnabled((bool) $data['penaltyEnabled']);
        }
        if (!empty($data['penaltyPercentPerDay'])) {
            $ri->setPenaltyPercentPerDay(number_format((float) $data['penaltyPercentPerDay'], 2, '.', ''));
        }
        if (isset($data['penaltyGraceDays']) && $data['penaltyGraceDays'] !== '') {
            $ri->setPenaltyGraceDays((int) $data['penaltyGraceDays']);
        }

        // Auto email settings
        if (!empty($data['autoEmailEnabled'])) {
            $ri->setAutoEmailEnabled((bool) $data['autoEmailEnabled']);
        }
        if (!empty($data['autoEmailTime'])) {
            $ri->setAutoEmailTime(substr(trim($data['autoEmailTime']), 0, 5));
        }
        if (isset($data['autoEmailDayOffset']) && $data['autoEmailDayOffset'] !== '') {
            $ri->setAutoEmailDayOffset((int) $data['autoEmailDayOffset']);
        }

        // Notes (from FGO "Descriere" field)
        if (!empty($data['notes'])) {
            $ri->setNotes($data['notes']);
        }

        // Resolve client by CIF or name
        $client = $this->resolveClient($company, $data);
        if ($client) {
            $ri->setClient($client);
        }

        // Resolve document series by name
        if (!empty($data['seriesName'])) {
            $series = $this->resolveDocumentSeries($company, $data['seriesName']);
            if ($series) {
                $ri->setDocumentSeries($series);
            }
        }

        return $ri;
    }

    private function buildLine(array $lineData, int $position): RecurringInvoiceLine
    {
        $line = new RecurringInvoiceLine();
        $line->setPosition($position);

        $line->setDescription(!empty($lineData['description']) ? mb_substr($lineData['description'], 0, 500) : '-');

        if (isset($lineData['quantity']) && $lineData['quantity'] !== '') {
            $line->setQuantity(number_format((float) $lineData['quantity'], 4, '.', ''));
        }

        if (!empty($lineData['unitOfMeasure'])) {
            $line->setUnitOfMeasure($lineData['unitOfMeasure']);
        }

        if (isset($lineData['unitPrice']) && $lineData['unitPrice'] !== '') {
            $line->setUnitPrice(number_format((float) $lineData['unitPrice'], 2, '.', ''));
        }

        if (isset($lineData['vatRate']) && $lineData['vatRate'] !== '') {
            $line->setVatRate((string) $lineData['vatRate']);
        }

        if (isset($lineData['total']) && $lineData['total'] !== '') {
            $line->setLineTotal(number_format((float) $lineData['total'], 2, '.', ''));
        }

        if (!empty($lineData['productCode'])) {
            $line->setProductCode($lineData['productCode']);
        }

        // Recurring invoice specific fields
        if (!empty($lineData['referenceCurrency'])) {
            $line->setReferenceCurrency(strtoupper(trim($lineData['referenceCurrency'])));
        }

        if (!empty($lineData['priceRule'])) {
            $line->setPriceRule($lineData['priceRule']);
        }

        // Calculate VAT amount if not provided
        if (isset($lineData['unitPrice']) && isset($lineData['vatRate'])) {
            $unitPrice = (float) $lineData['unitPrice'];
            $quantity = isset($lineData['quantity']) ? (float) $lineData['quantity'] : 1.0;
            $vatRate = (float) $lineData['vatRate'];
            $lineTotal = $unitPrice * $quantity;
            $vatAmount = $lineTotal * $vatRate / 100;

            $line->setVatAmount(number_format($vatAmount, 2, '.', ''));

            if (!isset($lineData['total']) || $lineData['total'] === '') {
                $line->setLineTotal(number_format($lineTotal, 2, '.', ''));
            }
        }

        return $line;
    }

    private function resolveClient(Company $company, array $data): ?Client
    {
        $clientCif = !empty($data['clientCif']) ? trim($data['clientCif']) : null;
        $clientName = $data['clientName'] ?? null;

        if (!$clientCif && !$clientName) {
            return null;
        }

        // Normalize CIF: strip RO prefix
        $normalizedCif = $clientCif;
        $hasRoPrefix = false;
        if ($normalizedCif && stripos($normalizedCif, 'RO') === 0) {
            $hasRoPrefix = true;
            $normalizedCif = substr($normalizedCif, 2);
        }

        // Try to find by CIF
        if ($normalizedCif) {
            $cacheKey = $company->getId()->toRfc4122() . ':' . $normalizedCif;
            if (array_key_exists($cacheKey, $this->clientCache)) {
                return $this->clientCache[$cacheKey];
            }

            $client = $this->entityManager->getRepository(Client::class)->findOneBy([
                'company' => $company,
                'cui' => $normalizedCif,
                'deletedAt' => null,
            ]);

            if ($client) {
                $this->clientCache[$cacheKey] = $client;
                return $client;
            }
        }

        // Try to find by name
        if ($clientName) {
            $nameCacheKey = $company->getId()->toRfc4122() . ':name:' . mb_strtolower($clientName);
            if (array_key_exists($nameCacheKey, $this->clientCache)) {
                return $this->clientCache[$nameCacheKey];
            }

            $client = $this->entityManager->getRepository(Client::class)->findOneBy([
                'company' => $company,
                'name' => $clientName,
                'deletedAt' => null,
            ]);

            if ($client) {
                $this->clientCache[$nameCacheKey] = $client;
                return $client;
            }
        }

        // Client not found — create from ANAF data
        $client = new Client();
        $client->setCompany($company);
        $client->setType('company');
        $client->setSource('import:' . ($data['_source'] ?? 'generic'));

        if ($normalizedCif) {
            $client->setCui($normalizedCif);

            try {
                $anafInfo = $this->anafService->findCompany($normalizedCif);
            } catch (\Throwable) {
                $anafInfo = null;
            }

            if ($anafInfo) {
                $client->setName($anafInfo->getName());
                $client->setAddress($anafInfo->getAddress());
                $addr = AddressNormalizer::normalizeBucharest($anafInfo->getState(), $anafInfo->getCity());
                $client->setCity($addr['city']);
                $client->setCounty($addr['county']);
                $client->setPostalCode($anafInfo->getPostalCode());
                $client->setPhone($anafInfo->getPhone());
                $client->setRegistrationNumber($anafInfo->getRegistrationNumber());
                $client->setIsVatPayer($anafInfo->isVatPayer());
                $client->setVatCode($anafInfo->getVatCode());
            } else {
                $client->setName($clientName ?? 'CUI ' . $normalizedCif);
                if ($hasRoPrefix) {
                    $client->setVatCode('RO' . $normalizedCif);
                    $client->setIsVatPayer(true);
                }
            }
        } else {
            $client->setName($clientName ?? 'Unknown');
        }

        $this->entityManager->persist($client);

        // Cache the new client
        if ($normalizedCif) {
            $this->clientCache[$company->getId()->toRfc4122() . ':' . $normalizedCif] = $client;
        }
        if ($clientName) {
            $this->clientCache[$company->getId()->toRfc4122() . ':name:' . mb_strtolower($clientName)] = $client;
        }

        return $client;
    }

    private function resolveDocumentSeries(Company $company, string $seriesName): ?DocumentSeries
    {
        $cacheKey = $company->getId()->toRfc4122() . ':' . mb_strtolower($seriesName);
        if (array_key_exists($cacheKey, $this->seriesCache)) {
            return $this->seriesCache[$cacheKey];
        }

        $series = $this->entityManager->getRepository(DocumentSeries::class)->findOneBy([
            'company' => $company,
            'prefix' => $seriesName,
        ]);

        $this->seriesCache[$cacheKey] = $series;
        return $series;
    }
}
