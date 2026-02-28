<?php

namespace App\Repository;

use App\Entity\Invoice;
use App\Entity\InvoiceShareToken;
use App\Enum\ShareTokenStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InvoiceShareToken>
 */
class InvoiceShareTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InvoiceShareToken::class);
    }

    public function findValidByToken(string $token): ?InvoiceShareToken
    {
        return $this->createQueryBuilder('t')
            ->where('t.token = :token')
            ->andWhere('t.status = :status')
            ->andWhere('t.expiresAt > :now')
            ->setParameter('token', $token)
            ->setParameter('status', ShareTokenStatus::ACTIVE)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return InvoiceShareToken[]
     */
    public function findByInvoice(Invoice $invoice): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.invoice = :invoice')
            ->orderBy('t.createdAt', 'DESC')
            ->setParameter('invoice', $invoice)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return InvoiceShareToken[]
     */
    public function findActiveByInvoice(Invoice $invoice): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.invoice = :invoice')
            ->andWhere('t.status = :status')
            ->andWhere('t.expiresAt > :now')
            ->orderBy('t.createdAt', 'DESC')
            ->setParameter('invoice', $invoice)
            ->setParameter('status', ShareTokenStatus::ACTIVE)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getResult();
    }
}
