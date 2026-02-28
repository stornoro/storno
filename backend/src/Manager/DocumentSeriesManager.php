<?php

namespace App\Manager;

use App\Entity\Company;
use App\Entity\DocumentSeries;
use App\Repository\DocumentSeriesRepository;
use Doctrine\ORM\EntityManagerInterface;

class DocumentSeriesManager
{
    /** @var array<string, DocumentSeries> Tracks unflushed series by "companyId:prefix" */
    private array $pendingSeries = [];

    public function __construct(
        private readonly DocumentSeriesRepository $documentSeriesRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * Find-or-create a series from e-Factura data. Only updates currentNumber upward.
     *
     * @return array{series: DocumentSeries, created: bool}
     */
    public function upsertFromEfactura(Company $company, string $prefix, int $number, string $type): array
    {
        $cacheKey = $company->getId()->toRfc4122() . ':' . $prefix;

        // Check local cache first (handles unflushed entities in same batch)
        if (isset($this->pendingSeries[$cacheKey])) {
            $series = $this->pendingSeries[$cacheKey];
            if ($number > $series->getCurrentNumber()) {
                $series->setCurrentNumber($number);
            }
            return ['series' => $series, 'created' => false];
        }

        $series = $this->documentSeriesRepository->findByPrefix($company, $prefix);

        if ($series) {
            if ($number > $series->getCurrentNumber()) {
                $series->setCurrentNumber($number);
            }
            return ['series' => $series, 'created' => false];
        }

        $series = new DocumentSeries();
        $series->setCompany($company);
        $series->setPrefix($prefix);
        $series->setCurrentNumber($number);
        $series->setType($type);
        $series->setSource('efactura');

        $this->entityManager->persist($series);
        $this->pendingSeries[$cacheKey] = $series;

        return ['series' => $series, 'created' => true];
    }

    /**
     * If company has zero series, create defaults for all document types.
     */
    public function ensureDefaultSeries(Company $company): void
    {
        $existing = $this->documentSeriesRepository->findByCompany($company);

        if (count($existing) > 0) {
            return;
        }

        $companyId = $company->getId()->toRfc4122();

        $defaults = [
            ['prefix' => 'FCT', 'type' => 'invoice'],
            ['prefix' => 'PRO', 'type' => 'proforma'],
            ['prefix' => 'CH', 'type' => 'receipt'],
            ['prefix' => 'BON', 'type' => 'voucher'],
        ];

        foreach ($defaults as $def) {
            $cacheKey = $companyId . ':' . $def['prefix'];

            // Skip if already created by upsertFromEfactura in this batch
            if (isset($this->pendingSeries[$cacheKey])) {
                continue;
            }

            // Also check DB in case it was flushed but not cached
            $existingByPrefix = $this->documentSeriesRepository->findByPrefix($company, $def['prefix']);
            if ($existingByPrefix) {
                continue;
            }

            $series = new DocumentSeries();
            $series->setCompany($company);
            $series->setPrefix($def['prefix']);
            $series->setType($def['type']);
            $series->setCurrentNumber(0);
            $series->setSource('default');

            $this->entityManager->persist($series);
            $this->pendingSeries[$cacheKey] = $series;
        }
    }

    /**
     * Clear the local cache (call after entityManager->clear()).
     */
    public function clearCache(): void
    {
        $this->pendingSeries = [];
    }
}
