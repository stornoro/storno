<?php

namespace App\Repository;

use App\Entity\AnafToken;
use App\Entity\Company;
use App\Entity\EInvoiceSubmission;
use App\Entity\Invoice;
use App\Enum\DocumentStatus;
use App\Enum\DocumentType;
use App\Enum\EInvoiceSubmissionStatus;
use App\Enum\InvoiceDirection;
use App\Service\Cache\CompanyStatsCache;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Invoice>
 */
class InvoiceRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly CompanyStatsCache $statsCache,
    ) {
        parent::__construct($registry, Invoice::class);
    }

    /**
     * Batch check which ANAF message IDs already exist. Returns associative array [messageId => true].
     */
    public function findExistingAnafMessageIds(array $messageIds): array
    {
        if (empty($messageIds)) {
            return [];
        }

        $rows = $this->createQueryBuilder('i')
            ->select('i.anafMessageId')
            ->where('i.anafMessageId IN (:ids)')
            ->setParameter('ids', $messageIds)
            ->getQuery()
            ->getSingleColumnResult();

        return array_flip($rows);
    }

    public function findWithDetails(string $uuid): ?Invoice
    {
        return $this->createQueryBuilder('i')
            ->leftJoin('i.client', 'c')->addSelect('c')
            ->leftJoin('i.company', 'co')->addSelect('co')
            ->leftJoin('i.lines', 'il')->addSelect('il')
            ->where('i.id = :id')
            ->setParameter('id', $uuid)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return array<array{id: string, number: string|null, issueDate: string|null, status: string}>
     */
    public function findRefundChildren(Invoice $invoice): array
    {
        $results = $this->createQueryBuilder('i')
            ->select('i.id', 'i.number', 'i.issueDate', 'i.status')
            ->where('i.parentDocument = :parent')
            ->setParameter('parent', $invoice)
            ->orderBy('i.createdAt', 'DESC')
            ->getQuery()
            ->getArrayResult();

        return array_map(fn (array $row) => [
            'id' => $row['id'] instanceof \Symfony\Component\Uid\Uuid ? $row['id']->toRfc4122() : (string) $row['id'],
            'number' => $row['number'],
            'issueDate' => $row['issueDate'] instanceof \DateTimeInterface ? $row['issueDate']->format('Y-m-d') : $row['issueDate'],
            'status' => $row['status'] instanceof DocumentStatus ? $row['status']->value : (string) $row['status'],
        ], $results);
    }

    private const SORTABLE_COLUMNS = ['number', 'issueDate', 'total', 'status', 'direction', 'createdAt'];

    public function findByCompanyPaginated(Company $company, array $filters = [], int $page = 1, int $limit = 20): array
    {
        $sortField = 'issueDate';
        $sortOrder = 'DESC';

        if (isset($filters['sort']) && in_array($filters['sort'], self::SORTABLE_COLUMNS, true)) {
            $sortField = $filters['sort'];
        }
        if (isset($filters['order']) && in_array(strtoupper($filters['order']), ['ASC', 'DESC'], true)) {
            $sortOrder = strtoupper($filters['order']);
        }

        $currency = $filters['currency'] ?? $company->getDefaultCurrency() ?? 'RON';

        $qb = $this->createQueryBuilder('i')
            ->leftJoin('i.client', 'c')->addSelect('c')
            ->where('i.company = :company')
            ->setParameter('company', $company)
            ->orderBy('i.' . $sortField, $sortOrder)
            ->addOrderBy('i.id', 'DESC');

        $this->applyFilters($qb, $filters);

        $qb->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        // The only join is the to-one client, so LIMIT counts invoices and the page needs no
        // DISTINCT id subquery.
        $data = $qb->getQuery()->getResult();

        // Count, totals and currencies read every filtered invoice of the company. They are
        // cached per company and filter set; any write to the company's records drops them.
        $companyId = $company->getId()?->toRfc4122() ?? '';
        $filterKey = $this->listFilterKey($filters);

        $total = $this->statsCache->get($companyId, 'invoice_list_count|' . $filterKey, function () use ($company, $filters): int {
            $countQb = $this->createQueryBuilder('i')
                ->select('COUNT(i.id)')
                ->leftJoin('i.client', 'c')
                ->where('i.company = :company')
                ->setParameter('company', $company);
            $this->applyFilters($countQb, $filters);

            return (int) $countQb->getQuery()->getSingleScalarResult();
        });

        // Aggregated totals across all filtered results (same currency, no conversion).
        // Exclude cancelled invoices — they're fiscally void and must not appear in
        // receivable/payable summaries or the subtotal/VAT/total ribbon above the list.
        $totals = $this->statsCache->get($companyId, 'invoice_list_totals|' . $filterKey, function () use ($company, $filters): array {
            $totalsQb = $this->createQueryBuilder('t')
                ->select(
                    'COALESCE(SUM(t.subtotal), 0) AS totalExcluding',
                    'COALESCE(SUM(t.vatTotal), 0) AS totalVat',
                    'COALESCE(SUM(t.total), 0) AS totalIncluding',
                    "COALESCE(SUM(CASE WHEN t.direction = 'outgoing' AND t.paidAt IS NULL THEN (t.total - t.amountPaid) ELSE 0 END), 0) AS totalReceivable",
                    "COALESCE(SUM(CASE WHEN t.direction = 'incoming' AND t.paidAt IS NULL THEN (t.total - t.amountPaid) ELSE 0 END), 0) AS totalPayable",
                )
                ->leftJoin('t.client', 'c')
                ->where('t.company = :company')
                ->andWhere('t.status != :cancelledStatus')
                ->setParameter('company', $company)
                ->setParameter('cancelledStatus', DocumentStatus::CANCELLED);

            $this->applyFilters($totalsQb, $filters);

            return $totalsQb->getQuery()->getSingleResult();
        });

        // Distinct currencies for this company
        $distinctCurrencies = $this->statsCache->get($companyId, 'invoice_currencies', fn (): array => $this->createQueryBuilder('dc')
            ->select('DISTINCT dc.currency')
            ->where('dc.company = :company')
            ->andWhere('dc.deletedAt IS NULL')
            ->setParameter('company', $company)
            ->getQuery()
            ->getSingleColumnResult());

        return [
            'data' => $data,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'currency' => $currency,
            'distinctCurrencies' => $distinctCurrencies,
            'totals' => [
                'subtotal' => $totals['totalExcluding'] ?? '0.00',
                'vatTotal' => $totals['totalVat'] ?? '0.00',
                'total' => $totals['totalIncluding'] ?? '0.00',
                'receivable' => $totals['totalReceivable'] ?? '0.00',
                'payable' => $totals['totalPayable'] ?? '0.00',
            ],
        ];
    }

    /** Cache key part for the filters applyFilters() reads; sort and paging do not change the aggregates. */
    private function listFilterKey(array $filters): string
    {
        $keys = ['currency', 'type', 'status', 'direction', 'clientId', 'search', 'dateFrom', 'dateTo', 'isDuplicate', 'isLateSubmission', 'isPaid', 'isOverdue', 'supplierId', 'vatTotalMin', 'vatTotalMax', 'totalMin', 'totalMax'];
        $normalized = [];
        foreach ($keys as $key) {
            if (isset($filters[$key])) {
                $normalized[$key] = is_scalar($filters[$key]) ? (string) $filters[$key] : $filters[$key];
            }
        }

        return hash('xxh128', json_encode($normalized, JSON_THROW_ON_ERROR));
    }

    private function applyFilters(\Doctrine\ORM\QueryBuilder $qb, array $filters): void
    {
        $a = $qb->getRootAliases()[0];

        if (isset($filters['currency'])) {
            $qb->andWhere("$a.currency = :currency")
                ->setParameter('currency', $filters['currency']);
        }

        if (isset($filters['type'])) {
            $qb->andWhere("$a.documentType = :type")
                ->setParameter('type', DocumentType::from($filters['type']));
        }

        if (isset($filters['status'])) {
            $qb->andWhere("$a.status = :status")
                ->setParameter('status', DocumentStatus::from($filters['status']));
        }

        if (isset($filters['direction'])) {
            $qb->andWhere("$a.direction = :direction")
                ->setParameter('direction', InvoiceDirection::from($filters['direction']));
        }

        if (isset($filters['clientId'])) {
            $qb->andWhere("$a.client = :clientId")
                ->setParameter('clientId', $filters['clientId']);
        }

        if (isset($filters['search'])) {
            $qb->andWhere("$a.number LIKE :search OR c.name LIKE :search OR $a.senderName LIKE :search OR $a.receiverName LIKE :search OR $a.senderCif LIKE :search OR $a.receiverCif LIKE :search")
                ->setParameter('search', '%' . $filters['search'] . '%');
        }

        if (isset($filters['dateFrom'])) {
            $qb->andWhere("$a.issueDate >= :dateFrom")
                ->setParameter('dateFrom', new \DateTime($filters['dateFrom']));
        }

        if (isset($filters['dateTo'])) {
            $qb->andWhere("$a.issueDate <= :dateTo")
                ->setParameter('dateTo', new \DateTime($filters['dateTo']));
        }

        if (isset($filters['isDuplicate'])) {
            $qb->andWhere("$a.isDuplicate = :isDuplicate")
                ->setParameter('isDuplicate', filter_var($filters['isDuplicate'], FILTER_VALIDATE_BOOLEAN));
        }

        if (isset($filters['isLateSubmission'])) {
            $qb->andWhere("$a.isLateSubmission = :isLateSubmission")
                ->setParameter('isLateSubmission', filter_var($filters['isLateSubmission'], FILTER_VALIDATE_BOOLEAN));
        }

        if (isset($filters['isPaid'])) {
            if (filter_var($filters['isPaid'], FILTER_VALIDATE_BOOLEAN)) {
                $qb->andWhere("$a.paidAt IS NOT NULL");
            } else {
                $qb->andWhere("$a.paidAt IS NULL");
            }
        }

        if (isset($filters['isOverdue']) && filter_var($filters['isOverdue'], FILTER_VALIDATE_BOOLEAN)) {
            $qb->andWhere("$a.dueDate < :now")
                ->andWhere("$a.paidAt IS NULL")
                ->setParameter('now', new \DateTime());
        }

        if (isset($filters['supplierId'])) {
            $qb->andWhere("$a.supplier = :supplierId")
                ->setParameter('supplierId', $filters['supplierId']);
        }

        if (isset($filters['vatTotalMin'])) {
            $qb->andWhere("$a.vatTotal >= :vatTotalMin")
                ->setParameter('vatTotalMin', $filters['vatTotalMin']);
        }

        if (isset($filters['vatTotalMax'])) {
            $qb->andWhere("$a.vatTotal <= :vatTotalMax")
                ->setParameter('vatTotalMax', $filters['vatTotalMax']);
        }

        if (isset($filters['totalMin'])) {
            $qb->andWhere("$a.total >= :totalMin")
                ->setParameter('totalMin', $filters['totalMin']);
        }

        if (isset($filters['totalMax'])) {
            $qb->andWhere("$a.total <= :totalMax")
                ->setParameter('totalMax', $filters['totalMax']);
        }
    }

    /**
     * Invoices that belong to a VAT period: sales by issue date, purchases by the date they
     * were recorded (a supplier invoice dated earlier but received now is deductible now,
     * as a regularisation). Drafts, cancelled, rejected and converted documents are out.
     *
     * @return Invoice[]
     */
    public function findForVatReturn(Company $company, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return $this->createQueryBuilder('i')
            ->leftJoin('i.lines', 'l')->addSelect('l')
            ->leftJoin('l.product', 'p')->addSelect('p')
            ->leftJoin('i.client', 'c')->addSelect('c')
            ->leftJoin('i.supplier', 's')->addSelect('s')
            ->where('i.company = :company')
            ->andWhere('i.deletedAt IS NULL')
            ->andWhere('i.status NOT IN (:excluded)')
            ->andWhere('(i.direction = :outgoing AND i.issueDate BETWEEN :from AND :to) OR (i.direction = :incoming AND i.createdAt BETWEEN :from AND :to)')
            ->setParameter('company', $company)
            ->setParameter('excluded', [DocumentStatus::DRAFT, DocumentStatus::CANCELLED, DocumentStatus::REJECTED, DocumentStatus::CONVERTED])
            ->setParameter('outgoing', InvoiceDirection::OUTGOING)
            ->setParameter('incoming', InvoiceDirection::INCOMING)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('i.issueDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * True when an invoice issued in the period has a counterparty (client of a sale, supplier of a
     * purchase) established in one of the given countries — the intra-community operations that
     * make a D390 due. Draft, cancelled, rejected and converted documents do not count.
     *
     * @param list<string> $countries ISO country codes
     */
    public function hasIntraCommunityOperations(Company $company, \DateTimeInterface $from, \DateTimeInterface $to, array $countries): bool
    {
        $count = $this->periodCounterpartyQuery($company, $from, $to)
            ->andWhere('(i.direction = :outgoing AND c.country IN (:countries)) OR (i.direction = :incoming AND s.country IN (:countries))')
            ->setParameter('outgoing', InvoiceDirection::OUTGOING)
            ->setParameter('incoming', InvoiceDirection::INCOMING)
            ->setParameter('countries', $countries)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count > 0;
    }

    /** True when a supplier established outside Romania issued an invoice to the company in the period (D301 for non-VAT payers). */
    public function hasForeignSupplierInvoices(Company $company, \DateTimeInterface $from, \DateTimeInterface $to): bool
    {
        $count = $this->periodCounterpartyQuery($company, $from, $to)
            ->andWhere('i.direction = :incoming')
            ->andWhere('s.country IS NOT NULL AND s.country <> :ro')
            ->setParameter('incoming', InvoiceDirection::INCOMING)
            ->setParameter('ro', 'RO')
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count > 0;
    }

    /**
     * True when the company issued or received at least one invoice in the period — the activity
     * that makes a declaration of that period worth a reminder. Draft, cancelled, rejected and
     * converted documents do not count.
     */
    public function hasActivity(Company $company, \DateTimeInterface $from, \DateTimeInterface $to): bool
    {
        $count = $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->where('i.company = :company')
            ->andWhere('i.deletedAt IS NULL')
            ->andWhere('i.status NOT IN (:excluded)')
            ->andWhere('i.issueDate BETWEEN :from AND :to')
            ->setParameter('company', $company)
            ->setParameter('excluded', [DocumentStatus::DRAFT, DocumentStatus::CANCELLED, DocumentStatus::REJECTED, DocumentStatus::CONVERTED])
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count > 0;
    }

    private function periodCounterpartyQuery(Company $company, \DateTimeInterface $from, \DateTimeInterface $to): \Doctrine\ORM\QueryBuilder
    {
        return $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->leftJoin('i.client', 'c')
            ->leftJoin('i.supplier', 's')
            ->where('i.company = :company')
            ->andWhere('i.deletedAt IS NULL')
            ->andWhere('i.status NOT IN (:excluded)')
            ->andWhere('i.issueDate BETWEEN :from AND :to')
            ->setParameter('company', $company)
            ->setParameter('excluded', [DocumentStatus::DRAFT, DocumentStatus::CANCELLED, DocumentStatus::REJECTED, DocumentStatus::CONVERTED])
            ->setParameter('from', $from)
            ->setParameter('to', $to);
    }

    /**
     * Invoices matching the export filters, newest first, each with its lines and client loaded.
     *
     * The limit counts invoices. The ids of the page are selected first, then those invoices are
     * loaded with their lines, so an invoice never comes back with part of its lines.
     *
     * @return Invoice[]
     */
    public function findByCompanyFiltered(Company $company, array $filters = [], ?int $limit = 500): array
    {
        if ($limit === null) {
            $qb = $this->filteredQueryBuilder($company, $filters)
                ->leftJoin('i.lines', 'il')->addSelect('il')
                ->addSelect('c');

            return $qb->getQuery()->getResult();
        }

        $ids = $this->filteredIdPage($company, $filters, $limit, null);

        return $this->loadWithLines(array_column($ids, 'id'));
    }

    /**
     * Same invoices and order as findByCompanyFiltered(), without a limit, loaded one batch at a
     * time. Pagination is by keyset on (issueDate, id). Each batch is detached from the entity
     * manager once the caller has moved past it, so memory stays at about one batch. What gets
     * detached: the batch's invoices and lines, and the clients, suppliers and products that
     * entered the entity manager while the batch was open. Entities managed before the batch
     * (the request's Company and User, an invoice the caller already loaded) stay managed.
     *
     * @return \Generator<int, Invoice>
     */
    public function iterateByCompanyFiltered(Company $company, array $filters = [], int $batchSize = 500): \Generator
    {
        $batchSize = max(1, $batchSize);
        $cursor = null;
        $em = $this->getEntityManager();

        do {
            $page = $this->filteredIdPage($company, $filters, $batchSize, $cursor);
            if ($page === []) {
                return;
            }
            $last = $page[array_key_last($page)];
            $cursor = ['issueDate' => $last['issueDate'], 'id' => $last['id']];

            // Invoices the caller already had in the entity manager stay managed, so their
            // pending changes are not lost.
            $uow = $em->getUnitOfWork();
            $alreadyManaged = [];
            foreach ($page as $row) {
                if ($uow->tryGetById($row['id'], Invoice::class) !== false) {
                    $alreadyManaged[$row['id']] = true;
                }
            }

            $related = [\App\Entity\Client::class, \App\Entity\Supplier::class, \App\Entity\Product::class];
            $before = [];
            foreach ($related as $class) {
                $before[$class] = $uow->getIdentityMap()[$class] ?? [];
            }

            $invoices = $this->loadWithLines(array_column($page, 'id'));
            foreach ($invoices as $invoice) {
                yield $invoice;
            }

            foreach ($invoices as $invoice) {
                if (isset($alreadyManaged[$invoice->getId()?->toRfc4122() ?? ''])) {
                    continue;
                }
                foreach ($invoice->getLines() as $line) {
                    if ($em->contains($line)) {
                        $em->detach($line);
                    }
                }
                if ($em->contains($invoice)) {
                    $em->detach($invoice);
                }
            }
            unset($invoices);

            // One client and product per invoice would otherwise stay in the identity map for
            // the whole run.
            foreach ($related as $class) {
                foreach (array_diff_key($uow->getIdentityMap()[$class] ?? [], $before[$class]) as $entity) {
                    $em->detach($entity);
                }
            }
            unset($before);
        } while (count($page) === $batchSize);
    }

    /**
     * The VAT figures of the invoices matching the export filters, summed in SQL.
     *
     * Returns per direction value (`outgoing`, `incoming`, or '' when the invoice has none):
     * - `rates`: line sums grouped by VAT rate, [rate => [taxableBase, vatAmount]]
     * - `totals`: [taxableBase, vatAmount, total, count]. Without a rate these are the invoice
     *   subtotal, VAT total and total, counted over every matching invoice (an invoice without
     *   lines still counts with its own amounts). With a rate they are the sums of the lines at
     *   that rate, counted over the invoices that have such a line.
     * - `currencies`: the currencies of the counted invoices, as stored.
     *
     * @return array<string, array{rates: array<string, array{taxableBase: string, vatAmount: string}>, totals: array{taxableBase: string, vatAmount: string, total: string, count: int}, currencies: list<string|null>}>
     */
    public function summarizeVatByCompanyFiltered(Company $company, array $filters = [], ?string $vatRate = null): array
    {
        $result = [];
        $bucket = static function (array &$result, string $dir): void {
            $result[$dir] ??= [
                'rates' => [],
                'totals' => ['taxableBase' => '0.00', 'vatAmount' => '0.00', 'total' => '0.00', 'count' => 0],
                'currencies' => [],
            ];
        };
        $dirKey = static fn (mixed $d) => $d instanceof InvoiceDirection ? $d->value : (string) $d;

        $lineQb = $this->filteredQueryBuilder($company, $filters)
            ->innerJoin('i.lines', 'l')
            ->select('i.direction AS direction', 'l.vatRate AS vatRate', 'COALESCE(SUM(l.lineTotal), 0) AS taxableBase', 'COALESCE(SUM(l.vatAmount), 0) AS vatAmount')
            ->groupBy('i.direction', 'l.vatRate')
            ->resetDQLPart('orderBy')
            ->orderBy('l.vatRate', 'DESC');
        if ($vatRate !== null) {
            $lineQb->andWhere('l.vatRate = :vatRate')->setParameter('vatRate', $vatRate);
        }
        foreach ($lineQb->getQuery()->getArrayResult() as $row) {
            $dir = $dirKey($row['direction']);
            $bucket($result, $dir);
            $result[$dir]['rates'][(string) $row['vatRate']] = ['taxableBase' => (string) $row['taxableBase'], 'vatAmount' => (string) $row['vatAmount']];
        }

        if ($vatRate === null) {
            $totalsQb = $this->filteredQueryBuilder($company, $filters)
                ->select('i.direction AS direction', 'COALESCE(SUM(i.subtotal), 0) AS taxableBase', 'COALESCE(SUM(i.vatTotal), 0) AS vatAmount', 'COALESCE(SUM(i.total), 0) AS total', 'COUNT(i.id) AS cnt')
                ->groupBy('i.direction')
                ->resetDQLPart('orderBy');
            $currencyQb = $this->filteredQueryBuilder($company, $filters)
                ->select('i.direction AS direction', 'i.currency AS currency')
                ->distinct()
                ->resetDQLPart('orderBy');
        } else {
            $totalsQb = $this->filteredQueryBuilder($company, $filters)
                ->innerJoin('i.lines', 'l')
                ->andWhere('l.vatRate = :vatRate')->setParameter('vatRate', $vatRate)
                ->select('i.direction AS direction', 'COALESCE(SUM(l.lineTotal), 0) AS taxableBase', 'COALESCE(SUM(l.vatAmount), 0) AS vatAmount', 'COALESCE(SUM(l.lineTotal + l.vatAmount), 0) AS total', 'COUNT(DISTINCT i.id) AS cnt')
                ->groupBy('i.direction')
                ->resetDQLPart('orderBy');
            $currencyQb = $this->filteredQueryBuilder($company, $filters)
                ->innerJoin('i.lines', 'l')
                ->andWhere('l.vatRate = :vatRate')->setParameter('vatRate', $vatRate)
                ->select('i.direction AS direction', 'i.currency AS currency')
                ->distinct()
                ->resetDQLPart('orderBy');
        }

        foreach ($totalsQb->getQuery()->getArrayResult() as $row) {
            $dir = $dirKey($row['direction']);
            $bucket($result, $dir);
            $result[$dir]['totals'] = [
                'taxableBase' => (string) $row['taxableBase'],
                'vatAmount' => (string) $row['vatAmount'],
                'total' => (string) $row['total'],
                'count' => (int) $row['cnt'],
            ];
        }
        foreach ($currencyQb->getQuery()->getArrayResult() as $row) {
            $dir = $dirKey($row['direction']);
            $bucket($result, $dir);
            $result[$dir]['currencies'][] = $row['currency'];
        }

        return $result;
    }

    /**
     * One page of ids (with their issue date, for the keyset cursor) in the export order:
     * issueDate DESC, id DESC.
     *
     * @param array{issueDate: \DateTimeInterface, id: string}|null $after
     *
     * @return list<array{id: string, issueDate: \DateTimeInterface}>
     */
    private function filteredIdPage(Company $company, array $filters, int $limit, ?array $after): array
    {
        $qb = $this->filteredQueryBuilder($company, $filters)
            ->select('i.id AS id', 'i.issueDate AS issueDate')
            ->setMaxResults($limit);

        if ($after !== null) {
            $qb->andWhere('i.issueDate < :afterDate OR (i.issueDate = :afterDate AND i.id < :afterId)')
                ->setParameter('afterDate', $after['issueDate'], \Doctrine\DBAL\Types\Types::DATE_MUTABLE)
                ->setParameter('afterId', $after['id']);
        }

        return array_map(static fn (array $row) => [
            'id' => $row['id'] instanceof \Symfony\Component\Uid\Uuid ? $row['id']->toRfc4122() : (string) $row['id'],
            'issueDate' => $row['issueDate'],
        ], $qb->getQuery()->getArrayResult());
    }

    /**
     * @param list<string> $ids
     *
     * @return Invoice[] in the export order
     */
    private function loadWithLines(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->createQueryBuilder('i')
            ->leftJoin('i.lines', 'il')->addSelect('il')
            ->leftJoin('i.client', 'c')->addSelect('c')
            ->where('i.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('i.issueDate', 'DESC')
            ->addOrderBy('i.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The filters shared by findByCompanyFiltered(), iterateByCompanyFiltered() and the VAT
     * summary. The root alias is `i` and the client is left-joined as `c` (not selected).
     *
     * Besides the list filters it takes `excludeCancelled` (bool) and `excludeStatuses`
     * (list of DocumentStatus or their values).
     */
    private function filteredQueryBuilder(Company $company, array $filters): \Doctrine\ORM\QueryBuilder
    {
        $qb = $this->createQueryBuilder('i')
            ->leftJoin('i.client', 'c')
            ->where('i.company = :company')
            ->setParameter('company', $company)
            ->orderBy('i.issueDate', 'DESC')
            ->addOrderBy('i.id', 'DESC');

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

        return $qb;
    }

    /**
     * Find invoices by CIF, optionally filtered by direction.
     * OUTGOING → match receiverCif (client = buyer), INCOMING → match senderCif (supplier = seller).
     */
    public function findByCifPaginated(Company $company, string $cif, int $page = 1, int $limit = 50, ?InvoiceDirection $direction = null): array
    {
        $qb = $this->createQueryBuilder('i')
            ->leftJoin('i.client', 'c')->addSelect('c')
            ->where('i.company = :company')
            ->setParameter('company', $company)
            ->orderBy('i.issueDate', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        if ($direction === InvoiceDirection::OUTGOING) {
            $qb->andWhere('i.receiverCif = :cif')
                ->andWhere('i.direction = :direction')
                ->setParameter('cif', $cif)
                ->setParameter('direction', $direction);
        } elseif ($direction === InvoiceDirection::INCOMING) {
            $qb->andWhere('i.senderCif = :cif')
                ->andWhere('i.direction = :direction')
                ->setParameter('cif', $cif)
                ->setParameter('direction', $direction);
        } else {
            $qb->andWhere('i.senderCif = :cif OR i.receiverCif = :cif')
                ->setParameter('cif', $cif);
        }

        $paginator = new Paginator($qb);

        return [
            'data' => iterator_to_array($paginator),
            'total' => count($paginator),
            'page' => $page,
            'limit' => $limit,
        ];
    }

    /**
     * Count invoices where senderCif or receiverCif matches the given CIF.
     * Cancelled invoices are excluded — they don't count toward a client's
     * transactional activity.
     */
    public function countByCif(Company $company, string $cif): int
    {
        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->where('i.company = :company')
            ->andWhere('i.senderCif = :cif OR i.receiverCif = :cif')
            ->andWhere('i.status != :cancelledStatus')
            ->setParameter('company', $company)
            ->setParameter('cif', $cif)
            ->setParameter('cancelledStatus', DocumentStatus::CANCELLED)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Get invoice counts and totals grouped by CIF for a list of CIFs.
     * Uses native SQL with UNION to leverage the new CIF indexes.
     * Returns [cif => ['invoiceCount' => int, 'invoiceTotal' => float]]
     */
    public function getStatsGroupedByCif(Company $company, array $cifs): array
    {
        if (empty($cifs)) {
            return [];
        }

        $conn = $this->getEntityManager()->getConnection();

        $placeholders = implode(',', array_fill(0, count($cifs), '?'));
        $companyId = $company->getId()->toRfc4122();

        // Cancelled invoices are fiscally void — exclude them from per-CIF counts
        // and totals shown on client/supplier list pages.
        $sql = "
            SELECT cif, COUNT(*) AS invoiceCount, COALESCE(SUM(total), 0) AS invoiceTotal
            FROM (
                SELECT sender_cif AS cif, total FROM invoice
                WHERE company_id = ? AND status != 'cancelled' AND sender_cif IN ($placeholders)
                UNION ALL
                SELECT receiver_cif AS cif, total FROM invoice
                WHERE company_id = ? AND status != 'cancelled' AND receiver_cif IN ($placeholders)
            ) AS combined
            GROUP BY cif
        ";

        $params = array_merge([$companyId], $cifs, [$companyId], $cifs);
        $rows = $conn->fetchAllAssociative($sql, $params);

        $stats = [];
        foreach ($rows as $row) {
            $stats[$row['cif']] = [
                'invoiceCount' => (int) $row['invoiceCount'],
                'invoiceTotal' => round((float) $row['invoiceTotal'], 2),
            ];
        }

        return $stats;
    }

    /**
     * @param string[] $ids
     * @return Invoice[]
     */
    public function findByIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        return $this->createQueryBuilder('i')
            ->where('i.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Invoice[]
     */
    public function findScheduledForSubmission(\DateTimeImmutable $now, int $limit = 100): array
    {
        // Exclude invoices that already have a non-failed e-invoice submission
        $subQuery = $this->getEntityManager()->createQueryBuilder()
            ->select('1')
            ->from(EInvoiceSubmission::class, 's')
            ->where('s.invoice = i')
            ->andWhere('s.status IN (:activeStatuses)')
            ->getDQL();

        return $this->createQueryBuilder('i')
            ->join('i.company', 'c')
            ->where('i.status = :status')
            ->andWhere('i.scheduledSendAt IS NOT NULL')
            ->andWhere('i.scheduledSendAt <= :now')
            ->andWhere('i.direction = :direction')
            ->andWhere('i.deletedAt IS NULL')
            ->andWhere('c.deletedAt IS NULL')
            ->andWhere(sprintf('NOT EXISTS (%s)', $subQuery))
            ->setParameter('status', DocumentStatus::ISSUED)
            ->setParameter('now', $now)
            ->setParameter('direction', InvoiceDirection::OUTGOING)
            ->setParameter('activeStatuses', [EInvoiceSubmissionStatus::PENDING, EInvoiceSubmissionStatus::SUBMITTED, EInvoiceSubmissionStatus::ACCEPTED])
            ->orderBy('i.scheduledSendAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Invoice[]
     */
    public function findExpiredArchived(Company $company, \DateTimeImmutable $cutoffDate): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.company = :company')
            ->andWhere('i.xmlPath IS NOT NULL')
            ->andWhere('i.issueDate < :cutoff')
            ->setParameter('company', $company)
            ->setParameter('cutoff', $cutoffDate)
            ->getQuery()
            ->getResult();
    }

    public function countActiveArchived(Company $company, \DateTimeImmutable $cutoffDate): int
    {
        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->where('i.company = :company')
            ->andWhere('i.xmlPath IS NOT NULL')
            ->andWhere('i.issueDate >= :cutoff')
            ->setParameter('company', $company)
            ->setParameter('cutoff', $cutoffDate)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findOverdueInvoices(Company $company): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.company = :company')
            ->andWhere('i.dueDate < :now')
            ->andWhere('i.paidAt IS NULL')
            ->andWhere('i.status IN (:statuses)')
            ->setParameter('company', $company)
            ->setParameter('now', new \DateTime())
            ->setParameter('statuses', [DocumentStatus::ISSUED, DocumentStatus::VALIDATED, DocumentStatus::SYNCED, DocumentStatus::SENT_TO_PROVIDER])
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Invoice[]
     */
    public function findDueInDays(int $days): array
    {
        $targetDate = new \DateTime(sprintf('+%d days', $days));

        return $this->createQueryBuilder('i')
            ->leftJoin('i.company', 'c')->addSelect('c')
            ->leftJoin('i.client', 'cl')->addSelect('cl')
            ->where('i.dueDate >= :start')
            ->andWhere('i.dueDate < :end')
            ->andWhere('i.paidAt IS NULL')
            ->andWhere('i.direction = :direction')
            ->andWhere('i.status IN (:statuses)')
            ->andWhere('c.syncEnabled = true')
            ->setParameter('start', $targetDate->format('Y-m-d') . ' 00:00:00')
            ->setParameter('end', $targetDate->format('Y-m-d') . ' 23:59:59')
            ->setParameter('direction', InvoiceDirection::OUTGOING)
            ->setParameter('statuses', [DocumentStatus::ISSUED, DocumentStatus::VALIDATED, DocumentStatus::SYNCED, DocumentStatus::SENT_TO_PROVIDER])
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Invoice[]
     */
    public function findOverdueByDays(int $days): array
    {
        $targetDate = new \DateTime(sprintf('-%d days', $days));

        return $this->createQueryBuilder('i')
            ->leftJoin('i.company', 'c')->addSelect('c')
            ->leftJoin('i.client', 'cl')->addSelect('cl')
            ->where('i.dueDate >= :start')
            ->andWhere('i.dueDate < :end')
            ->andWhere('i.paidAt IS NULL')
            ->andWhere('i.direction = :direction')
            ->andWhere('i.status IN (:statuses)')
            ->andWhere('c.syncEnabled = true')
            ->setParameter('start', $targetDate->format('Y-m-d') . ' 00:00:00')
            ->setParameter('end', $targetDate->format('Y-m-d') . ' 23:59:59')
            ->setParameter('direction', InvoiceDirection::OUTGOING)
            ->setParameter('statuses', [DocumentStatus::ISSUED, DocumentStatus::VALIDATED, DocumentStatus::SYNCED, DocumentStatus::SENT_TO_PROVIDER])
            ->getQuery()
            ->getResult();
    }

    /**
     * Count outgoing invoices created this calendar month for the given company.
     */
    /**
     * Find invoices scheduled for auto-email delivery.
     * ANAF gate: only send if not submitted to ANAF, or if ANAF validated.
     *
     * @return Invoice[]
     */
    public function findScheduledForEmail(\DateTimeImmutable $now, int $limit = 100): array
    {
        return $this->createQueryBuilder('i')
            ->join('i.company', 'c')->addSelect('c')
            ->leftJoin('i.client', 'cl')->addSelect('cl')
            ->where('i.scheduledEmailAt <= :now')
            ->andWhere('i.deletedAt IS NULL')
            ->andWhere('(i.anafUploadId IS NULL OR i.status = :validated)')
            ->setParameter('now', $now)
            ->setParameter('validated', DocumentStatus::VALIDATED)
            ->orderBy('i.scheduledEmailAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Outgoing invoices of the company with something left to collect, whose due date falls in
     * one of the given windows (one window per reminder step). The opt-outs on the invoice and
     * on its client are applied here.
     *
     * @param list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}> $windows [from, to], both inclusive
     * @return Invoice[]
     */
    public function findOverdueForReminders(Company $company, array $windows): array
    {
        if ($windows === []) {
            return [];
        }

        $qb = $this->createQueryBuilder('i')
            ->join('i.client', 'cl')->addSelect('cl')
            ->where('i.company = :company')
            ->andWhere('i.deletedAt IS NULL')
            ->andWhere('i.direction = :direction')
            ->andWhere('i.status IN (:statuses)')
            ->andWhere('i.paidAt IS NULL')
            ->andWhere('i.total > i.amountPaid')
            ->andWhere('i.overdueRemindersDisabled = false')
            ->andWhere('cl.overdueRemindersDisabled = false')
            ->andWhere('cl.email IS NOT NULL')
            ->setParameter('company', $company)
            ->setParameter('direction', InvoiceDirection::OUTGOING)
            ->setParameter('statuses', [
                DocumentStatus::ISSUED,
                DocumentStatus::SENT_TO_PROVIDER,
                DocumentStatus::VALIDATED,
                DocumentStatus::SYNCED,
                DocumentStatus::PARTIALLY_PAID,
                DocumentStatus::OVERDUE,
            ])
            ->orderBy('i.dueDate', 'ASC');

        $ranges = [];
        foreach ($windows as $n => [$from, $to]) {
            $ranges[] = sprintf('(i.dueDate >= :from%1$d AND i.dueDate <= :to%1$d)', $n);
            $qb->setParameter('from' . $n, $from->format('Y-m-d'));
            $qb->setParameter('to' . $n, $to->format('Y-m-d'));
        }
        $qb->andWhere(implode(' OR ', $ranges));

        return $qb->getQuery()->getResult();
    }

    /**
     * Unpaid issued invoices due on or before $dueBefore, the candidates for the reminder backlog.
     *
     * @return Invoice[]
     */
    public function findOverdueBacklogForReminders(Company $company, \DateTimeImmutable $dueBefore): array
    {
        return $this->createQueryBuilder('i')
            ->join('i.client', 'cl')->addSelect('cl')
            ->where('i.company = :company')
            ->andWhere('i.deletedAt IS NULL')
            ->andWhere('cl.deletedAt IS NULL')
            ->andWhere('i.direction = :direction')
            ->andWhere('i.status IN (:statuses)')
            ->andWhere('i.paidAt IS NULL')
            ->andWhere('i.total > i.amountPaid')
            ->andWhere('i.dueDate < :dueBefore')
            ->setParameter('company', $company)
            ->setParameter('direction', InvoiceDirection::OUTGOING)
            ->setParameter('statuses', [
                DocumentStatus::ISSUED,
                DocumentStatus::SENT_TO_PROVIDER,
                DocumentStatus::VALIDATED,
                DocumentStatus::SYNCED,
                DocumentStatus::PARTIALLY_PAID,
                DocumentStatus::OVERDUE,
            ])
            ->setParameter('dueBefore', $dueBefore->format('Y-m-d'))
            ->orderBy('i.dueDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countThisMonth(Company $company): int
    {
        $firstOfMonth = new \DateTimeImmutable('first day of this month midnight');

        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->where('i.company = :company')
            ->andWhere('i.direction = :direction')
            ->andWhere('i.createdAt >= :since')
            ->setParameter('company', $company)
            ->setParameter('direction', InvoiceDirection::OUTGOING)
            ->setParameter('since', $firstOfMonth)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Find outgoing invoices not yet submitted to ANAF whose issue date
     * is exactly $days days ago (approaching the 5-day submission deadline).
     *
     * @return Invoice[]
     */
    /**
     * Find all editable invoices linked to a specific client.
     * Editable = not cancelled, not sent_to_provider, and either rejected or not yet uploaded to ANAF.
     *
     * By default only returns invoices with issueDate in the current calendar month — past
     * months are considered closed. Pass $currentMonthOnly = false to include older unsent
     * invoices too (explicit client-data resync).
     *
     * @return Invoice[]
     */
    public function findEditableByClient(Company $company, \App\Entity\Client $client, bool $currentMonthOnly = true): array
    {
        $qb = $this->createQueryBuilder('i')
            ->where('i.company = :company')
            ->andWhere('i.client = :client')
            ->andWhere('i.deletedAt IS NULL')
            ->andWhere('i.status NOT IN (:blocked)')
            ->andWhere('i.status = :rejected OR i.anafUploadId IS NULL')
            ->setParameter('company', $company)
            ->setParameter('client', $client)
            ->setParameter('blocked', [DocumentStatus::CANCELLED, DocumentStatus::SENT_TO_PROVIDER])
            ->setParameter('rejected', DocumentStatus::REJECTED);

        if ($currentMonthOnly) {
            $qb->andWhere('i.issueDate >= :monthStart')
                ->setParameter('monthStart', new \DateTime('first day of this month 00:00:00'));
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Net outstanding balance a client owes to this company, as of a given date,
     * in a single currency. Sums (total - amountPaid) across all outgoing invoices
     * with issueDate <= asOfDate, excluding drafts and cancelled documents.
     *
     * Advance payments on future invoices naturally contribute 0 (total == amountPaid),
     * so unpaid older invoices are not offset by prepaid ones — which is the intended
     * "how much is still owed right now" view.
     */
    public function getClientOutstandingBalance(
        Company $company,
        \App\Entity\Client $client,
        string $currency,
        \DateTimeInterface $asOfDate,
    ): string {
        $result = $this->createQueryBuilder('i')
            ->select('COALESCE(SUM(i.total - i.amountPaid), 0) AS outstanding')
            ->where('i.company = :company')
            ->andWhere('i.client = :client')
            ->andWhere('i.currency = :currency')
            ->andWhere('i.direction = :direction')
            ->andWhere('i.deletedAt IS NULL')
            ->andWhere('i.issueDate <= :asOfDate')
            ->andWhere('i.status NOT IN (:excluded)')
            ->setParameter('company', $company)
            ->setParameter('client', $client)
            ->setParameter('currency', $currency)
            ->setParameter('direction', InvoiceDirection::OUTGOING)
            ->setParameter('asOfDate', $asOfDate)
            ->setParameter('excluded', [DocumentStatus::DRAFT, DocumentStatus::CANCELLED])
            ->getQuery()
            ->getSingleScalarResult();

        return number_format((float) $result, 2, '.', '');
    }

    /**
     * Find billing invoices issued by the billing company to a specific org (matched by receiverCif).
     */
    public function findBillingInvoicesForOrg(Company $billingCompany, string $receiverCif, int $page = 1, int $limit = 20): array
    {
        $qb = $this->createQueryBuilder('i')
            ->where('i.company = :company')
            ->andWhere('i.receiverCif = :receiverCif')
            ->andWhere('i.deletedAt IS NULL')
            ->setParameter('company', $billingCompany)
            ->setParameter('receiverCif', $receiverCif)
            ->orderBy('i.issueDate', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        $paginator = new Paginator($qb);

        return [
            'data' => iterator_to_array($paginator),
            'total' => count($paginator),
            'page' => $page,
            'limit' => $limit,
        ];
    }

    /**
     * Find the most recent live DRAFT for the given fingerprint, created in
     * the last $withinSeconds seconds. Used by InvoiceManager::create() to
     * fold retry storms from API clients that don't send an idempotency key.
     */
    public function findRecentDraftFingerprint(
        Company $company,
        string $clientId,
        string $currency,
        string $total,
        int $withinSeconds,
    ): ?Invoice {
        $threshold = (new \DateTimeImmutable())->modify(sprintf('-%d seconds', $withinSeconds));

        return $this->createQueryBuilder('i')
            ->where('i.company = :company')
            ->andWhere('IDENTITY(i.client) = :client')
            ->andWhere('i.currency = :currency')
            ->andWhere('i.total = :total')
            ->andWhere('i.status = :draft')
            ->andWhere('i.deletedAt IS NULL')
            ->andWhere('i.createdAt >= :threshold')
            ->setParameter('company', $company)
            ->setParameter('client', $clientId)
            ->setParameter('currency', $currency)
            ->setParameter('total', $total)
            ->setParameter('draft', DocumentStatus::DRAFT)
            ->setParameter('threshold', $threshold)
            ->orderBy('i.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findApproachingAnafDeadline(int $days): array
    {
        $targetDate = new \DateTime(sprintf('-%d days', $days));

        return $this->createQueryBuilder('i')
            ->leftJoin('i.company', 'c')->addSelect('c')
            ->leftJoin('i.client', 'cl')->addSelect('cl')
            ->where('i.issueDate >= :start')
            ->andWhere('i.issueDate < :end')
            ->andWhere('i.direction = :direction')
            ->andWhere('i.status IN (:statuses)')
            ->andWhere('c.syncEnabled = true')
            ->andWhere('cl.country = :ro OR cl.country IS NULL')
            ->setParameter('start', $targetDate->format('Y-m-d') . ' 00:00:00')
            ->setParameter('end', $targetDate->format('Y-m-d') . ' 23:59:59')
            ->setParameter('direction', InvoiceDirection::OUTGOING)
            ->setParameter('statuses', [DocumentStatus::ISSUED, DocumentStatus::REJECTED])
            ->setParameter('ro', 'RO')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find outgoing RO-targeted invoices whose ANAF submission deadline is approaching,
     * but whose company has NO ANAF token configured (no user in the organization
     * has authorized SPV access). These invoices cannot be auto-submitted.
     *
     * @return Invoice[]
     */
    public function findApproachingDeadlineWithoutToken(int $days): array
    {
        $targetDate = new \DateTime(sprintf('-%d days', $days));

        $tokenSubQuery = $this->getEntityManager()->createQueryBuilder()
            ->select('1')
            ->from(AnafToken::class, 't')
            ->innerJoin('t.user', 'tu')
            ->innerJoin('tu.organizationMemberships', 'tom')
            ->where('tom.organization = c.organization')
            ->andWhere('t.token IS NOT NULL')
            ->getDQL();

        return $this->createQueryBuilder('i')
            ->innerJoin('i.company', 'c')->addSelect('c')
            ->leftJoin('i.client', 'cl')->addSelect('cl')
            ->where('i.issueDate >= :start')
            ->andWhere('i.issueDate < :end')
            ->andWhere('i.direction = :direction')
            ->andWhere('i.status IN (:statuses)')
            ->andWhere('i.anafUploadId IS NULL')
            ->andWhere('i.deletedAt IS NULL')
            ->andWhere('c.deletedAt IS NULL')
            ->andWhere('c.country = :ro')
            ->andWhere('cl.country = :ro OR cl.country IS NULL')
            ->andWhere(sprintf('NOT EXISTS (%s)', $tokenSubQuery))
            ->setParameter('start', $targetDate->format('Y-m-d') . ' 00:00:00')
            ->setParameter('end', $targetDate->format('Y-m-d') . ' 23:59:59')
            ->setParameter('direction', InvoiceDirection::OUTGOING)
            ->setParameter('statuses', [DocumentStatus::ISSUED, DocumentStatus::REJECTED])
            ->setParameter('ro', 'RO')
            ->getQuery()
            ->getResult();
    }
}
