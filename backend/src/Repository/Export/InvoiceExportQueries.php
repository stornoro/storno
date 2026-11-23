<?php

namespace App\Repository\Export;

use App\Entity\Company;
use App\Entity\Invoice;
use App\Enum\DocumentStatus;
use App\Enum\DocumentType;
use App\Enum\InvoiceDirection;
use App\Repository\InvoiceRepository;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * Invoice queries for the exports that InvoiceRepository does not offer: the date range and
 * count of a filtered export, the ids for the e-Factura ZIP job, and an iteration that also
 * detaches the clients loaded with each batch.
 *
 * applyFilters() mirrors InvoiceRepository::filteredQueryBuilder(), which is private. Keep the
 * two in step until the repository exposes an aggregate over its filtered query.
 */
class InvoiceExportQueries
{
    private const CLIENT_DETACH_EVERY = 500;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly InvoiceRepository $invoiceRepository,
    ) {}

    /**
     * InvoiceRepository::iterateByCompanyFiltered() detaches the invoices and lines of each
     * finished batch but leaves the fetch-joined clients managed. Over a year of invoices that
     * is most of the 300k clients of a large company, so they are detached here every 500
     * invoices. A detached client stays readable; a later batch that joins the same client
     * gets a fresh managed copy.
     *
     * @return \Generator<int, Invoice>
     */
    public function iterate(Company $company, array $filters): \Generator
    {
        $clients = [];
        $seen = 0;

        foreach ($this->invoiceRepository->iterateByCompanyFiltered($company, $filters) as $invoice) {
            yield $invoice;

            $client = $invoice->getClient();
            if ($client !== null) {
                $clients[spl_object_id($client)] = $client;
            }

            if (++$seen % self::CLIENT_DETACH_EVERY === 0) {
                $this->detachAll($clients);
                $clients = [];
            }
        }

        $this->detachAll($clients);
    }

    /**
     * Earliest and latest issue date of the invoices the export would contain.
     *
     * @return array{0: ?\DateTimeInterface, 1: ?\DateTimeInterface}
     */
    public function issueDateRange(Company $company, array $filters): array
    {
        $row = $this->filtered($company, $filters)
            ->select('MIN(i.issueDate) AS minDate', 'MAX(i.issueDate) AS maxDate')
            ->getQuery()
            ->getSingleResult(AbstractQuery::HYDRATE_ARRAY);

        $parse = static fn (mixed $v): ?\DateTimeInterface => match (true) {
            $v instanceof \DateTimeInterface => $v,
            \is_string($v) && $v !== '' => new \DateTimeImmutable($v),
            default => null,
        };

        return [$parse($row['minDate'] ?? null), $parse($row['maxDate'] ?? null)];
    }

    public function count(Company $company, array $filters): int
    {
        return (int) $this->filtered($company, $filters)
            ->select('COUNT(i.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Ids in the export order (issue date descending, id descending), read without hydrating
     * any entity.
     *
     * @return list<string>
     */
    public function ids(Company $company, array $filters): array
    {
        $qb = $this->filtered($company, $filters)
            ->select('i.id AS id')
            ->orderBy('i.issueDate', 'DESC')
            ->addOrderBy('i.id', 'DESC');

        $ids = [];
        foreach ($qb->getQuery()->toIterable([], AbstractQuery::HYDRATE_SCALAR) as $row) {
            $ids[] = strtolower((string) $row['id']);
        }

        return $ids;
    }

    private function filtered(Company $company, array $filters): QueryBuilder
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->from(Invoice::class, 'i')
            ->leftJoin('i.client', 'c')
            ->where('i.company = :company')
            ->setParameter('company', $company);

        $this->applyFilters($qb, $filters);

        return $qb;
    }

    private function applyFilters(QueryBuilder $qb, array $filters): void
    {
        if (isset($filters['type'])) {
            $qb->andWhere('i.documentType = :type')
                ->setParameter('type', DocumentType::from($filters['type']));
        }

        if (isset($filters['status'])) {
            $qb->andWhere('i.status = :status')
                ->setParameter('status', DocumentStatus::from($filters['status']));
        }

        if (isset($filters['excludeCancelled']) && filter_var($filters['excludeCancelled'], FILTER_VALIDATE_BOOLEAN)) {
            $qb->andWhere('i.status != :cancelledStatus')
                ->setParameter('cancelledStatus', DocumentStatus::CANCELLED);
        }

        if (!empty($filters['excludeStatuses'])) {
            $qb->andWhere('i.status NOT IN (:excludedStatuses)')
                ->setParameter('excludedStatuses', array_map(
                    static fn (DocumentStatus|string $s) => $s instanceof DocumentStatus ? $s->value : $s,
                    $filters['excludeStatuses'],
                ));
        }

        if (isset($filters['direction'])) {
            $qb->andWhere('i.direction = :direction')
                ->setParameter('direction', InvoiceDirection::from($filters['direction']));
        }

        if (isset($filters['search'])) {
            $qb->andWhere('i.number LIKE :search OR c.name LIKE :search OR i.senderName LIKE :search OR i.receiverName LIKE :search')
                ->setParameter('search', '%' . $filters['search'] . '%');
        }

        if (isset($filters['dateFrom'])) {
            $qb->andWhere('i.issueDate >= :dateFrom')
                ->setParameter('dateFrom', new \DateTime($filters['dateFrom']));
        }

        if (isset($filters['dateTo'])) {
            $qb->andWhere('i.issueDate <= :dateTo')
                ->setParameter('dateTo', new \DateTime($filters['dateTo']));
        }

        if (isset($filters['isPaid'])) {
            if (filter_var($filters['isPaid'], FILTER_VALIDATE_BOOLEAN)) {
                $qb->andWhere('i.paidAt IS NOT NULL');
            } else {
                $qb->andWhere('i.paidAt IS NULL');
            }
        }

        if (isset($filters['isOverdue']) && filter_var($filters['isOverdue'], FILTER_VALIDATE_BOOLEAN)) {
            $qb->andWhere('i.dueDate < :now')
                ->andWhere('i.paidAt IS NULL')
                ->setParameter('now', new \DateTime());
        }

        if (isset($filters['isDuplicate'])) {
            $qb->andWhere('i.isDuplicate = :isDuplicate')
                ->setParameter('isDuplicate', filter_var($filters['isDuplicate'], FILTER_VALIDATE_BOOLEAN));
        }

        if (isset($filters['clientId'])) {
            $qb->andWhere('i.client = :clientId')
                ->setParameter('clientId', $filters['clientId']);
        }

        if (isset($filters['supplierId'])) {
            $qb->andWhere('i.supplier = :supplierId')
                ->setParameter('supplierId', $filters['supplierId']);
        }

        if (isset($filters['currency'])) {
            $qb->andWhere('i.currency = :currency')
                ->setParameter('currency', strtoupper((string) $filters['currency']));
        }
    }

    /**
     * @param array<int, object> $entities
     */
    private function detachAll(array $entities): void
    {
        foreach ($entities as $entity) {
            if ($this->entityManager->contains($entity)) {
                $this->entityManager->detach($entity);
            }
        }
    }
}
