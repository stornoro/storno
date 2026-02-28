<?php

namespace App\Repository;

use App\Entity\Company;
use App\Entity\Invoice;
use App\Entity\Payment;
use App\Enum\InvoiceDirection;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Payment>
 *
 * @method Payment|null find($id, $lockMode = null, $lockVersion = null)
 * @method Payment|null findOneBy(array $criteria, array $orderBy = null)
 * @method Payment[]    findAll()
 * @method Payment[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class PaymentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Payment::class);
    }

    /**
     * @return Payment[]
     */
    public function findByInvoice(Invoice $invoice): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.invoice = :invoice')
            ->setParameter('invoice', $invoice)
            ->orderBy('p.paymentDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find payments by company and invoice direction (outgoing = receipts, incoming = payments).
     *
     * @return Payment[]
     */
    public function findByCompanyAndDirection(Company $company, InvoiceDirection $direction): array
    {
        return $this->createQueryBuilder('p')
            ->join('p.invoice', 'i')
            ->where('p.company = :company')
            ->andWhere('i.direction = :direction')
            ->andWhere('i.deletedAt IS NULL')
            ->setParameter('company', $company)
            ->setParameter('direction', $direction)
            ->orderBy('p.paymentDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find payments by company and invoice direction with optional date filtering.
     *
     * @return Payment[]
     */
    public function findByCompanyAndDirectionFiltered(
        Company $company,
        InvoiceDirection $direction,
        ?string $dateFrom = null,
        ?string $dateTo = null,
    ): array {
        $qb = $this->createQueryBuilder('p')
            ->join('p.invoice', 'i')
            ->where('p.company = :company')
            ->andWhere('i.direction = :direction')
            ->andWhere('i.deletedAt IS NULL')
            ->setParameter('company', $company)
            ->setParameter('direction', $direction);

        if ($dateFrom) {
            $qb->andWhere('p.paymentDate >= :dateFrom')
                ->setParameter('dateFrom', $dateFrom);
        }

        if ($dateTo) {
            $qb->andWhere('p.paymentDate <= :dateTo')
                ->setParameter('dateTo', $dateTo);
        }

        return $qb->orderBy('p.paymentDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function sumByInvoice(Invoice $invoice): string
    {
        $result = $this->createQueryBuilder('p')
            ->select('COALESCE(SUM(p.amount), 0) AS total')
            ->where('p.invoice = :invoice')
            ->setParameter('invoice', $invoice)
            ->getQuery()
            ->getSingleScalarResult();

        return (string) $result;
    }

    public function findByReference(string $reference): ?Payment
    {
        return $this->findOneBy(['reference' => $reference]);
    }

    /**
     * @return Payment[]
     */
    public function findStripePaymentsByCompany(Company $company, int $limit = 10): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.company = :company')
            ->andWhere('p.paymentMethod = :method')
            ->setParameter('company', $company)
            ->setParameter('method', 'stripe')
            ->orderBy('p.paymentDate', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function sumStripePaymentsByCompany(Company $company): string
    {
        $result = $this->createQueryBuilder('p')
            ->select('COALESCE(SUM(p.amount), 0) AS total')
            ->where('p.company = :company')
            ->andWhere('p.paymentMethod = :method')
            ->setParameter('company', $company)
            ->setParameter('method', 'stripe')
            ->getQuery()
            ->getSingleScalarResult();

        return (string) $result;
    }

    public function countStripePaymentsByCompany(Company $company): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.company = :company')
            ->andWhere('p.paymentMethod = :method')
            ->setParameter('company', $company)
            ->setParameter('method', 'stripe')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
