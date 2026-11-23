<?php

namespace App\Repository\Export;

use App\Entity\Company;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * SQL behind the company-wide customer statements (GET /clients/statements), written so that
 * no invoice or client entity is hydrated.
 *
 * "Open invoice" means what ClientStatementService::findOpenInvoices() selects: outgoing, not
 * deleted, issued on or before the date, not draft / cancelled / converted, total different
 * from the amount paid, linked to a client that is not deleted. The SQL filters are spelled
 * out because this is plain SQL: the soft-delete Doctrine filter does not apply to it.
 * Measured on 1M invoices (300k open, 90k clients with a balance): about 3 s per aggregate.
 *
 * The per-client aggregates follow ClientStatementService::buildStatement(): only invoices in
 * the statement currency (a missing currency counts as RON) enter the totals, the balance is
 * the sum of their open amounts, positive amounts go to the aging bands by days overdue at the
 * date (due date, else issue date) and negative ones are credits.
 */
class ClientStatementQueries
{
    private const OPEN_INVOICE_SQL = <<<'SQL'
        i.company_id = :company
        AND i.direction = 'outgoing'
        AND i.deleted_at IS NULL
        AND i.client_id IS NOT NULL
        AND i.issue_date <= :asOf
        AND i.status NOT IN ('draft', 'cancelled', 'converted')
        AND i.total <> i.amount_paid
        SQL;

    private const IN_CURRENCY_SQL = "BINARY COALESCE(NULLIF(i.currency, ''), 'RON') = BINARY :currency";

    private const DAYS_SQL = 'COALESCE(DATEDIFF(:asOf, COALESCE(i.due_date, i.issue_date)), 0)';

    /** Band key => [min, max] days overdue, same as ClientStatementService::AGING_BANDS. */
    private const BANDS = [
        'current' => [null, 0],
        'days1_30' => [1, 30],
        'days31_60' => [31, 60],
        'days61_90' => [61, 90],
        'days91_120' => [91, 120],
        'days121_180' => [121, 180],
        'over180' => [181, null],
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * Company totals and aging over the clients whose balance is positive.
     *
     * @return array{clientCount: int, totals: array<string, string|int>, aging: array<string, array{amount: string, count: int}>}
     */
    public function companyTotals(Company $company, \DateTimeImmutable $asOf, string $currency): array
    {
        $outer = [
            'COUNT(*) AS client_count',
            'CAST(COALESCE(SUM(g.invoiced), 0) AS DECIMAL(20,2)) AS invoiced',
            'CAST(COALESCE(SUM(g.paid), 0) AS DECIMAL(20,2)) AS paid',
            'CAST(COALESCE(SUM(g.outstanding), 0) AS DECIMAL(20,2)) AS outstanding',
            'CAST(COALESCE(SUM(g.credits), 0) AS DECIMAL(20,2)) AS credits',
            'CAST(COALESCE(SUM(g.balance), 0) AS DECIMAL(20,2)) AS balance',
            'COALESCE(SUM(g.cnt), 0) AS cnt',
        ];
        foreach (array_keys(self::BANDS) as $band) {
            $outer[] = sprintf('CAST(COALESCE(SUM(g.%1$s_amount), 0) AS DECIMAL(20,2)) AS %1$s_amount', $band);
            $outer[] = sprintf('COALESCE(SUM(g.%1$s_count), 0) AS %1$s_count', $band);
        }

        $sql = sprintf('SELECT %s FROM (%s) g', implode(', ', $outer), $this->perClientSql(withBands: true));
        $row = $this->connection()->fetchAssociative($sql, $this->params($company, $asOf, $currency)) ?: [];

        $aging = [];
        foreach (array_keys(self::BANDS) as $band) {
            $aging[$band] = ['amount' => (string) $row[$band . '_amount'], 'count' => (int) $row[$band . '_count']];
        }

        return [
            'clientCount' => (int) $row['client_count'],
            'totals' => [
                'invoiced' => (string) $row['invoiced'],
                'paid' => (string) $row['paid'],
                'outstanding' => (string) $row['outstanding'],
                'credits' => (string) $row['credits'],
                'balance' => (string) $row['balance'],
                'count' => (int) $row['cnt'],
            ],
            'aging' => $aging,
        ];
    }

    /**
     * Ids of the clients with a positive balance, highest balance first. Ties keep the order
     * in which the former implementation met the clients: by their earliest open invoice
     * (due date, then issue date, missing dates first), then by id.
     *
     * The ids come back packed in one string, 36 bytes each, so 150k clients take about 5 MB
     * instead of an array of strings several times that size.
     */
    public function orderedClientIds(Company $company, \DateTimeImmutable $asOf, string $currency): string
    {
        $sql = sprintf(
            'SELECT g.client_id FROM (%s) g ORDER BY g.balance DESC, g.first_key ASC, g.client_id ASC',
            $this->perClientSql(withBands: false),
        );

        $packed = '';
        foreach ($this->connection()->executeQuery($sql, $this->params($company, $asOf, $currency))->iterateColumn() as $id) {
            if (\strlen((string) $id) !== 36) {
                throw new \UnexpectedValueException('Unexpected client id format.');
            }
            $packed .= strtolower((string) $id);
        }

        return $packed;
    }

    /**
     * @param list<string> $clientIds
     *
     * @return array<string, array<string, mixed>> client rows keyed by id
     */
    public function clients(array $clientIds): array
    {
        if ($clientIds === []) {
            return [];
        }

        $rows = $this->connection()->fetchAllAssociative(
            'SELECT id, name, type, cui, cnp, email, address, city, county, country FROM client WHERE id IN (:ids)',
            ['ids' => $clientIds],
            ['ids' => ArrayParameterType::STRING],
        );

        $byId = [];
        foreach ($rows as $row) {
            // Ids are returned in the canonical lower-case form Uuid::toRfc4122() gives.
            $row['id'] = strtolower($row['id']);
            $byId[$row['id']] = $row;
        }

        return $byId;
    }

    /**
     * Open invoices of the given clients, as plain rows grouped by client id.
     *
     * @param list<string> $clientIds
     *
     * @return array<string, list<array{id: string, number: ?string, status: string, issueDate: ?string, dueDate: ?string, currency: ?string, total: string, amountPaid: string}>>
     */
    public function openInvoicesByClient(Company $company, \DateTimeImmutable $asOf, array $clientIds): array
    {
        if ($clientIds === []) {
            return [];
        }

        $sql = 'SELECT i.id, i.client_id, i.number, i.status, i.issue_date, i.due_date, i.currency, i.total, i.amount_paid
                FROM invoice i
                WHERE ' . self::OPEN_INVOICE_SQL . ' AND i.client_id IN (:ids)';

        $result = $this->connection()->executeQuery(
            $sql,
            ['company' => (string) $company->getId(), 'asOf' => $asOf->format('Y-m-d'), 'ids' => $clientIds],
            ['ids' => ArrayParameterType::STRING],
        );

        $byClient = [];
        while (($row = $result->fetchAssociative()) !== false) {
            $byClient[strtolower($row['client_id'])][] = [
                'id' => strtolower($row['id']),
                'number' => $row['number'],
                'status' => $row['status'],
                'issueDate' => $row['issue_date'],
                'dueDate' => $row['due_date'],
                'currency' => $row['currency'],
                'total' => $row['total'],
                'amountPaid' => $row['amount_paid'],
            ];
        }

        return $byClient;
    }

    private function perClientSql(bool $withBands): string
    {
        $cur = self::IN_CURRENCY_SQL;
        $open = 'i.total - i.amount_paid';

        // Grouped on the binary form of the id: the utf8mb4 CHAR(36) key is four times wider and
        // compared by collation, which pushed the 300k-row grouping into an on-disk temporary
        // table (11 s against 3 s measured on MariaDB 11.7). The ids are ASCII, so the bytes
        // read back are the same string.
        $columns = [
            'BINARY i.client_id AS client_id',
            "SUM(CASE WHEN $cur THEN $open ELSE 0 END) AS balance",
        ];

        if ($withBands) {
            $columns[] = "SUM(CASE WHEN $cur THEN 1 ELSE 0 END) AS cnt";
            $columns[] = "SUM(CASE WHEN $cur THEN i.total ELSE 0 END) AS invoiced";
            $columns[] = "SUM(CASE WHEN $cur THEN i.amount_paid ELSE 0 END) AS paid";
            $columns[] = "SUM(CASE WHEN $cur AND $open > 0 THEN $open ELSE 0 END) AS outstanding";
            $columns[] = "SUM(CASE WHEN $cur AND $open < 0 THEN $open ELSE 0 END) AS credits";
            foreach (self::BANDS as $band => [$min, $max]) {
                $range = [];
                if ($min !== null) {
                    $range[] = self::DAYS_SQL . ' >= ' . $min;
                }
                if ($max !== null) {
                    $range[] = self::DAYS_SQL . ' <= ' . $max;
                }
                $when = "$cur AND $open > 0 AND " . implode(' AND ', $range);
                $columns[] = "SUM(CASE WHEN $when THEN $open ELSE 0 END) AS {$band}_amount";
                $columns[] = "SUM(CASE WHEN $when THEN 1 ELSE 0 END) AS {$band}_count";
            }
        } else {
            // Position of the client's first open invoice in the old (due date, issue date) order.
            $columns[] = "MIN(CONCAT(IF(i.due_date IS NULL, '0', CONCAT('1', i.due_date)), COALESCE(i.issue_date, ''))) AS first_key";
        }

        return sprintf(
            // Deleted clients are left out with NOT IN over the company's deleted clients (an
            // index range on client.company_id) instead of a join per invoice row, which cost
            // about 12 s at 300k open invoices.
            'SELECT %s FROM invoice i WHERE %s AND i.client_id NOT IN (SELECT cd.id FROM client cd WHERE cd.company_id = :company AND cd.deleted_at IS NOT NULL) GROUP BY BINARY i.client_id HAVING balance > 0',
            implode(', ', $columns),
            self::OPEN_INVOICE_SQL,
        );
    }

    /**
     * @return array<string, string>
     */
    private function params(Company $company, \DateTimeImmutable $asOf, string $currency): array
    {
        return [
            'company' => (string) $company->getId(),
            'asOf' => $asOf->format('Y-m-d'),
            'currency' => $currency,
        ];
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }
}
