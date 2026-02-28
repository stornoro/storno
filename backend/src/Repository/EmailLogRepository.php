<?php

namespace App\Repository;

use App\Entity\Company;
use App\Entity\DeliveryNote;
use App\Entity\EmailLog;
use App\Entity\Invoice;
use App\Entity\Receipt;
use App\Enum\EmailStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EmailLog>
 */
class EmailLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmailLog::class);
    }

    public function findAllPaginated(int $page = 1, int $limit = 10, ?string $search = null, ?string $category = null, ?string $status = null): array
    {
        $qb = $this->createQueryBuilder('el')
            ->orderBy('el.sentAt', 'DESC');

        if ($search) {
            $qb->andWhere('el.toEmail LIKE :search OR el.subject LIKE :search')
                ->setParameter('search', '%' . $search . '%');
        }

        if ($category) {
            $qb->andWhere('el.category = :category')
                ->setParameter('category', $category);
        }

        if ($status) {
            $statusEnum = EmailStatus::tryFrom($status);
            if ($statusEnum) {
                $qb->andWhere('el.status = :status')
                    ->setParameter('status', $statusEnum);
            }
        }

        $countQb = clone $qb;
        $total = (int) $countQb->select('COUNT(el.id)')->getQuery()->getSingleScalarResult();

        $data = $qb
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return ['data' => $data, 'total' => $total];
    }

    public function findByCompanyPaginated(Company $company, int $page = 1, int $limit = 20): array
    {
        return $this->createQueryBuilder('el')
            ->where('el.company = :company')
            ->setParameter('company', $company)
            ->orderBy('el.sentAt', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findByInvoice(Invoice $invoice): array
    {
        return $this->createQueryBuilder('el')
            ->leftJoin('el.events', 'ev')
            ->addSelect('ev')
            ->where('el.invoice = :invoice')
            ->setParameter('invoice', $invoice)
            ->orderBy('el.sentAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findBySesMessageId(string $messageId): ?EmailLog
    {
        return $this->createQueryBuilder('el')
            ->where('el.sesMessageId = :messageId')
            ->setParameter('messageId', $messageId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByDeliveryNote(DeliveryNote $deliveryNote): array
    {
        return $this->createQueryBuilder('el')
            ->leftJoin('el.events', 'ev')
            ->addSelect('ev')
            ->where('el.deliveryNote = :deliveryNote')
            ->setParameter('deliveryNote', $deliveryNote)
            ->orderBy('el.sentAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findByReceipt(Receipt $receipt): array
    {
        return $this->createQueryBuilder('el')
            ->leftJoin('el.events', 'ev')
            ->addSelect('ev')
            ->where('el.receipt = :receipt')
            ->setParameter('receipt', $receipt)
            ->orderBy('el.sentAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
