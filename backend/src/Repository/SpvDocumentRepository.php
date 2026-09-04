<?php

namespace App\Repository;

use App\Entity\Company;
use App\Entity\SpvDocument;
use App\Enum\SpvDocumentCategory;
use App\Enum\SpvDocumentSeverity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SpvDocument>
 */
class SpvDocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SpvDocument::class);
    }

    public function findOneByAnafMessageId(Company $company, string $anafMessageId): ?SpvDocument
    {
        return $this->findOneBy(['company' => $company, 'anafMessageId' => $anafMessageId]);
    }

    /**
     * @param array{category?: ?string, severity?: ?string, unread?: bool, search?: ?string, from?: ?\DateTimeInterface, to?: ?\DateTimeInterface} $filters
     * @return array{data: SpvDocument[], total: int, page: int, limit: int}
     */
    public function findByCompanyPaginated(Company $company, array $filters, int $page, int $limit): array
    {
        $qb = $this->createQueryBuilder('d')
            ->where('d.company = :company')
            ->setParameter('company', $company)
            ->orderBy('d.anafCreatedAt', 'DESC')
            ->addOrderBy('d.createdAt', 'DESC');

        if (!empty($filters['category']) && SpvDocumentCategory::tryFrom($filters['category'])) {
            $qb->andWhere('d.category = :category')->setParameter('category', SpvDocumentCategory::from($filters['category']));
        }
        if (!empty($filters['severity']) && SpvDocumentSeverity::tryFrom($filters['severity'])) {
            $qb->andWhere('d.severity = :severity')->setParameter('severity', SpvDocumentSeverity::from($filters['severity']));
        }
        if (!empty($filters['unread'])) {
            $qb->andWhere('d.readAt IS NULL');
        }
        if (!empty($filters['search'])) {
            $qb->andWhere('d.details LIKE :search OR d.messageType LIKE :search OR d.anafMessageId LIKE :search')
                ->setParameter('search', '%' . $filters['search'] . '%');
        }
        if (!empty($filters['from'])) {
            $qb->andWhere('d.anafCreatedAt >= :from')->setParameter('from', $filters['from']);
        }
        if (!empty($filters['to'])) {
            $qb->andWhere('d.anafCreatedAt <= :to')->setParameter('to', $filters['to']);
        }

        $qb->setFirstResult(($page - 1) * $limit)->setMaxResults($limit);
        $paginator = new Paginator($qb->getQuery());

        return [
            'data' => iterator_to_array($paginator),
            'total' => count($paginator),
            'page' => $page,
            'limit' => $limit,
        ];
    }

    /**
     * Documents whose PDF has not been archived yet (and not purged).
     * @return SpvDocument[]
     */
    public function findPendingDownload(Company $company, int $limit = 100): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.company = :company')
            ->andWhere('d.pdfPath IS NULL')
            ->andWhere('d.purgedAt IS NULL')
            ->setParameter('company', $company)
            ->orderBy('d.anafCreatedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array{total: int, unread: int, pendingPdf: int, byCategory: array<string, int>, bySeverity: array<string, int>}
     */
    public function statsForCompany(Company $company): array
    {
        $rows = $this->createQueryBuilder('d')
            ->select('d.category AS category, d.severity AS severity, COUNT(d.id) AS cnt, SUM(CASE WHEN d.readAt IS NULL THEN 1 ELSE 0 END) AS unread, SUM(CASE WHEN d.pdfPath IS NULL AND d.purgedAt IS NULL THEN 1 ELSE 0 END) AS pending')
            ->where('d.company = :company')
            ->setParameter('company', $company)
            ->groupBy('d.category, d.severity')
            ->getQuery()
            ->getArrayResult();

        $stats = ['total' => 0, 'unread' => 0, 'pendingPdf' => 0, 'byCategory' => [], 'bySeverity' => []];
        foreach ($rows as $r) {
            $cat = $r['category'] instanceof SpvDocumentCategory ? $r['category']->value : (string) $r['category'];
            $sev = $r['severity'] instanceof SpvDocumentSeverity ? $r['severity']->value : (string) $r['severity'];
            $stats['total'] += (int) $r['cnt'];
            $stats['unread'] += (int) $r['unread'];
            $stats['pendingPdf'] += (int) $r['pending'];
            $stats['byCategory'][$cat] = ($stats['byCategory'][$cat] ?? 0) + (int) $r['cnt'];
            $stats['bySeverity'][$sev] = ($stats['bySeverity'][$sev] ?? 0) + (int) $r['cnt'];
        }

        return $stats;
    }

    /**
     * Archived PDFs older than the cutoff (by ANAF date), for retention cleanup.
     * @return SpvDocument[]
     */
    public function findExpiredPdfs(Company $company, \DateTimeImmutable $cutoff, int $limit = 200): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.company = :company')
            ->andWhere('d.pdfPath IS NOT NULL')
            ->andWhere('d.purgedAt IS NULL')
            ->andWhere('COALESCE(d.anafCreatedAt, d.createdAt) < :cutoff')
            ->setParameter('company', $company)
            ->setParameter('cutoff', $cutoff)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
