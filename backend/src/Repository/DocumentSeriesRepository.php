<?php

namespace App\Repository;

use App\Entity\Company;
use App\Entity\DeliveryNote;
use App\Entity\DocumentSeries;
use App\Entity\Invoice;
use App\Entity\ProformaInvoice;
use App\Entity\Receipt;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DocumentSeries>
 */
class DocumentSeriesRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DocumentSeries::class);
    }

    public function findByCompany(Company $company): array
    {
        return $this->createQueryBuilder('ds')
            ->where('ds.company = :company')
            ->setParameter('company', $company)
            ->orderBy('ds.prefix', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByPrefix(Company $company, string $prefix): ?DocumentSeries
    {
        return $this->createQueryBuilder('ds')
            ->where('ds.company = :company')
            ->andWhere('ds.prefix = :prefix')
            ->setParameter('company', $company)
            ->setParameter('prefix', $prefix)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find the default series of the given type for a company.
     * Falls back to the first active series if none is marked as default.
     */
    public function findDefaultByType(Company $company, string $type): ?DocumentSeries
    {
        // Try explicit default first
        $default = $this->createQueryBuilder('ds')
            ->where('ds.company = :company')
            ->andWhere('ds.type = :type')
            ->andWhere('ds.active = true')
            ->andWhere('ds.isDefault = true')
            ->setParameter('company', $company)
            ->setParameter('type', $type)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($default) {
            return $default;
        }

        // Fallback to first active
        return $this->createQueryBuilder('ds')
            ->where('ds.company = :company')
            ->andWhere('ds.type = :type')
            ->andWhere('ds.active = true')
            ->setParameter('company', $company)
            ->setParameter('type', $type)
            ->orderBy('ds.createdAt', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Sequence numbers (prefix stripped) issued from the series in a calendar
     * year, across invoices, proformas, delivery notes and receipts. Drafts
     * (no number) and soft-deleted documents are ignored.
     *
     * @return int[] sorted ascending, unique
     */
    public function findIssuedNumbersForYear(DocumentSeries $series, int $year): array
    {
        $prefix = (string) $series->getPrefix();
        $from = new \DateTimeImmutable(sprintf('%04d-01-01', $year));
        $to = $from->modify('+1 year');
        $em = $this->getEntityManager();
        $numbers = [];

        foreach ([Invoice::class, ProformaInvoice::class, DeliveryNote::class, Receipt::class] as $class) {
            $rows = $em->createQueryBuilder()
                ->select('d.number')
                ->from($class, 'd')
                ->where('d.documentSeries = :series')
                ->andWhere('d.number IS NOT NULL')
                ->andWhere('d.deletedAt IS NULL')
                ->andWhere('d.issueDate >= :from')
                ->andWhere('d.issueDate < :to')
                ->setParameter('series', $series)
                ->setParameter('from', $from)
                ->setParameter('to', $to)
                ->getQuery()
                ->getScalarResult();

            foreach ($rows as $row) {
                $number = (string) $row['number'];
                if ($prefix !== '' && !str_starts_with($number, $prefix)) {
                    continue;
                }
                $sequence = substr($number, strlen($prefix));
                if ($sequence !== '' && ctype_digit($sequence)) {
                    $numbers[] = (int) $sequence;
                }
            }
        }

        $numbers = array_values(array_unique(array_filter($numbers, fn (int $n) => $n > 0)));
        sort($numbers);

        return $numbers;
    }

    /**
     * Unset isDefault for all series of a given type for a company.
     */
    public function clearDefaultsForType(Company $company, string $type): void
    {
        $this->createQueryBuilder('ds')
            ->update()
            ->set('ds.isDefault', 'false')
            ->where('ds.company = :company')
            ->andWhere('ds.type = :type')
            ->andWhere('ds.isDefault = true')
            ->setParameter('company', $company)
            ->setParameter('type', $type)
            ->getQuery()
            ->execute();
    }
}
