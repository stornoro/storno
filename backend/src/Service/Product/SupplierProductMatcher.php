<?php

declare(strict_types=1);

namespace App\Service\Product;

use App\Entity\Company;
use App\Entity\Product;
use App\Entity\Supplier;
use App\Entity\SupplierProductMapping;
use App\Repository\ProductRepository;
use App\Repository\SupplierProductMappingRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Finds the product a received-invoice line refers to, and remembers the answer per supplier.
 *
 * Match order (strongest key first): the supplier's barcode → the supplier's article code →
 * the supplier's item description → our own code as the supplier prints it (BT-156) → our
 * code equal to the barcode → the caller's fallback (name + unit, then a new product).
 * Every hit is remembered again so the last used mapping stays current; a product chosen by
 * the user (`confirmed`) is preferred over one learned from an import.
 */
class SupplierProductMatcher
{
    public function __construct(
        private readonly SupplierProductMappingRepository $mappings,
        private readonly ProductRepository $products,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * @param array{barcode?: ?string, sellerCode?: ?string, buyerCode?: ?string} $ids
     */
    public function match(Company $company, Supplier $supplier, array $ids, ?string $description): ?Product
    {
        foreach ($this->keys($ids, $description) as [$field, $value]) {
            $mapping = $this->mappings->findOneByKey($supplier, $field, $value);
            if ($mapping && $mapping->getProduct()->isActive() !== false && $mapping->getProduct()->getCompany()?->getId()?->equals($company->getId())) {
                $mapping->touch();
                return $mapping->getProduct();
            }
        }

        foreach (array_filter([$ids['buyerCode'] ?? null, $ids['barcode'] ?? null]) as $code) {
            $product = $this->products->findOneBy(['company' => $company, 'code' => $code]);
            if ($product) {
                return $product;
            }
        }

        return null;
    }

    /**
     * Remember that, for this supplier, these identifiers mean this product.
     *
     * @param array{barcode?: ?string, sellerCode?: ?string, buyerCode?: ?string} $ids
     */
    public function learn(Company $company, Supplier $supplier, Product $product, array $ids, ?string $description, bool $confirmed = false): void
    {
        foreach ($this->keys($ids, $description) as [$field, $value]) {
            $mapping = $this->mappings->findOneByKey($supplier, $field, $value);
            if ($mapping === null) {
                $mapping = new SupplierProductMapping($company, $supplier, $product);
                match ($field) {
                    'barcode' => $mapping->setBarcode($value),
                    'supplierCode' => $mapping->setSupplierCode($value),
                    'descriptionKey' => $mapping->setDescriptionKey($value),
                };
                $this->entityManager->persist($mapping);
            } elseif ($mapping->getProduct() !== $product) {
                // An import never overrides what the user decided by hand
                if ($mapping->isConfirmed() && !$confirmed) {
                    continue;
                }
                $mapping->setProduct($product);
            }
            if ($confirmed) {
                $mapping->setConfirmed(true);
            }
            $mapping->touch();
        }
    }

    /** @return list<array{0: string, 1: string}> */
    private function keys(array $ids, ?string $description): array
    {
        $keys = [];
        if (!empty($ids['barcode'])) {
            $keys[] = ['barcode', mb_substr(trim((string) $ids['barcode']), 0, 100)];
        }
        if (!empty($ids['sellerCode'])) {
            $keys[] = ['supplierCode', mb_substr(trim((string) $ids['sellerCode']), 0, 100)];
        }
        $descriptionKey = SupplierProductMapping::normalizeDescription($description);
        if ($descriptionKey !== null) {
            $keys[] = ['descriptionKey', $descriptionKey];
        }
        return $keys;
    }
}
