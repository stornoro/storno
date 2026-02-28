<?php

namespace App\Service\Import\Persister;

use App\Entity\Company;
use App\Entity\Product;
use App\Repository\ProductRepository;
use App\Service\Import\ImportResult;
use Doctrine\ORM\EntityManagerInterface;

class ProductPersister implements EntityPersisterInterface
{
    private const BATCH_SIZE = 50;
    private int $batchCount = 0;

    /**
     * In-memory dedup cache.
     * Keys: companyId:code:value  or  companyId:name+uom:value
     *
     * @var array<string, Product>
     */
    private array $pendingCache = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProductRepository $productRepository,
    ) {}

    public function supports(string $importType): bool
    {
        return $importType === 'products';
    }

    public function persist(array $mappedData, Company $company, ImportResult $result): void
    {
        $name = $mappedData['name'] ?? null;
        if (empty($name)) {
            return;
        }

        $code = !empty($mappedData['code']) ? trim($mappedData['code']) : null;
        $unitOfMeasure = !empty($mappedData['unitOfMeasure']) ? trim($mappedData['unitOfMeasure']) : 'buc';
        $companyPrefix = $company->getId()->toRfc4122();

        // Primary dedup: by code (scoped to company), most reliable
        $existing = null;
        if ($code) {
            $codeCacheKey = $companyPrefix . ':code:' . $code;
            $existing = $this->pendingCache[$codeCacheKey]
                ?? $this->productRepository->findOneBy([
                    'company'   => $company,
                    'code'      => $code,
                    'deletedAt' => null,
                ]);
        }

        // Fallback dedup: name + unitOfMeasure combination
        if (!$existing) {
            $nameCacheKey = $companyPrefix . ':name+uom:' . mb_strtolower($name) . ':' . mb_strtolower($unitOfMeasure);
            $existing = $this->pendingCache[$nameCacheKey]
                ?? $this->productRepository->findOneBy([
                    'company'       => $company,
                    'name'          => $name,
                    'unitOfMeasure' => $unitOfMeasure,
                    'deletedAt'     => null,
                ]);
        }

        if ($existing) {
            $this->setProductFields($existing, $mappedData);
            $result->incrementUpdated();
        } else {
            $product = new Product();
            $product->setCompany($company);
            $product->setName($name);
            $product->setSource('import:' . ($mappedData['_source'] ?? 'generic'));

            $this->setProductFields($product, $mappedData);

            $this->entityManager->persist($product);

            // Populate in-memory cache to prevent within-batch duplicates
            if ($code) {
                $this->pendingCache[$companyPrefix . ':code:' . $code] = $product;
            }
            $nameCacheKey = $companyPrefix . ':name+uom:' . mb_strtolower($name) . ':' . mb_strtolower($unitOfMeasure);
            $this->pendingCache[$nameCacheKey] = $product;

            $result->incrementCreated();
        }

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
        $this->batchCount = 0;
    }

    private function setProductFields(Product $product, array $data): void
    {
        if (!empty($data['code'])) {
            $product->setCode(trim($data['code']));
        }

        if (!empty($data['description'])) {
            $product->setDescription($data['description']);
        }

        if (!empty($data['unitOfMeasure'])) {
            $product->setUnitOfMeasure($data['unitOfMeasure']);
        }

        if (isset($data['defaultPrice']) && $data['defaultPrice'] !== '') {
            $product->setDefaultPrice(number_format((float) $data['defaultPrice'], 2, '.', ''));
        }

        if (!empty($data['currency'])) {
            $product->setCurrency(strtoupper(trim($data['currency'])));
        }

        if (isset($data['vatRate']) && $data['vatRate'] !== '') {
            $product->setVatRate(number_format((float) $data['vatRate'], 2, '.', ''));
        }

        if (!empty($data['vatCategoryCode'])) {
            $product->setVatCategoryCode($data['vatCategoryCode']);
        }

        if (isset($data['isService'])) {
            $product->setIsService((bool) $data['isService']);
        }

        if (!empty($data['usage'])) {
            $allowedUsages = ['sales', 'purchases', 'both', 'internal'];
            if (in_array($data['usage'], $allowedUsages, true)) {
                $product->setUsage($data['usage']);
            }
        }

        if (!empty($data['ncCode'])) {
            $product->setNcCode($data['ncCode']);
        }

        if (!empty($data['cpvCode'])) {
            $product->setCpvCode($data['cpvCode']);
        }
    }
}
