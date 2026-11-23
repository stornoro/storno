<?php

namespace App\Service\Client;

use App\Entity\BankAccount;
use App\Entity\Client;
use App\Entity\Company;
use App\Entity\Invoice;
use App\Enum\DocumentStatus;
use App\Enum\InvoiceDirection;
use App\Repository\BankAccountRepository;
use App\Repository\Export\ClientStatementQueries;
use App\Repository\InvoiceRepository;

/**
 * Customer statement (situație clienți): the unpaid outgoing invoices of a
 * client as of a date, with totals, the client's balance and the outstanding
 * amount split into aging bands by days overdue.
 *
 * Amounts are computed from the invoice's own paid/due helpers
 * (Invoice::getAmountPaid / getBalance). Storno / credit documents with a
 * negative balance are listed as credits and reduce the balance; the aging
 * bands cover positive outstanding amounts only.
 *
 * The statement is expressed in the company's default currency; invoices in
 * other currencies are listed with their own currency and summarised in
 * `otherCurrencies` but do not enter the balance or the aging bands.
 *
 * The company-wide list does not hydrate invoices: the totals, the aging and the
 * order of the clients come from SQL aggregates (ClientStatementQueries), and the
 * statements are built 500 clients at a time from plain invoice rows.
 */
class ClientStatementService
{
    /** @var array<string, array{min:int, max:int|null}> */
    public const AGING_BANDS = [
        'current' => ['min' => PHP_INT_MIN, 'max' => 0],
        'days1_30' => ['min' => 1, 'max' => 30],
        'days31_60' => ['min' => 31, 'max' => 60],
        'days61_90' => ['min' => 61, 'max' => 90],
        'days91_120' => ['min' => 91, 'max' => 120],
        'days121_180' => ['min' => 121, 'max' => 180],
        'over180' => ['min' => 181, 'max' => null],
    ];

    private const CLIENT_BATCH = 500;

    public function __construct(
        private readonly InvoiceRepository $invoiceRepository,
        private readonly BankAccountRepository $bankAccountRepository,
        private readonly ?ClientStatementQueries $statementQueries = null,
    ) {}

    public function statement(Client $client, \DateTimeImmutable $asOf): array
    {
        $company = $client->getCompany();
        if (!$company) {
            throw new \InvalidArgumentException('Client has no company.');
        }

        return $this->statementFromInvoices($client, $this->findOpenInvoices($company, $asOf, $client), $asOf);
    }

    /**
     * Build a statement from an already loaded list of the client's invoices
     * (drafts, cancelled and fully paid documents are skipped).
     *
     * @param Invoice[] $invoices
     */
    public function statementFromInvoices(Client $client, array $invoices, \DateTimeImmutable $asOf): array
    {
        $company = $client->getCompany();
        if (!$company) {
            throw new \InvalidArgumentException('Client has no company.');
        }

        $invoices = array_filter($invoices, static fn (Invoice $i) => $i->getDirection() === InvoiceDirection::OUTGOING
            && $i->getDeletedAt() === null
            && !\in_array($i->getStatus(), [DocumentStatus::DRAFT, DocumentStatus::CANCELLED, DocumentStatus::CONVERTED], true)
            && ($i->getIssueDate() === null || $i->getIssueDate() <= $asOf->setTime(23, 59, 59)));

        return $this->buildStatement($company, $this->clientRow($client), array_map($this->invoiceRow(...), array_values($invoices)), $asOf);
    }

    /**
     * One statement per client with a positive balance, sorted by balance
     * descending, plus company-wide totals and aging.
     */
    public function statementsForCompany(Company $company, \DateTimeImmutable $asOf): array
    {
        $overview = $this->streamStatementsForCompany($company, $asOf);
        $overview['statements'] = iterator_to_array($overview['statements'], false);

        return $overview;
    }

    /**
     * Same keys and values as statementsForCompany(), with `statements` as a generator for a
     * streamed JSON response. The totals, aging and client count are computed by SQL before
     * the first statement is built.
     *
     * @return array{company: array, asOf: string, currency: string, clientCount: int, totals: array, aging: array, statements: \Generator<int, array>}
     */
    public function streamStatementsForCompany(Company $company, \DateTimeImmutable $asOf): array
    {
        if ($this->statementQueries === null) {
            throw new \LogicException('ClientStatementQueries is required for the company-wide statements.');
        }

        $currency = $company->getDefaultCurrency() ?: 'RON';
        $summary = $this->statementQueries->companyTotals($company, $asOf, $currency);
        $clientIds = $this->statementQueries->orderedClientIds($company, $asOf, $currency);

        return [
            'company' => ['id' => (string) $company->getId(), 'name' => $company->getName()],
            'asOf' => $asOf->format('Y-m-d'),
            'currency' => $currency,
            'clientCount' => $summary['clientCount'],
            'totals' => $summary['totals'],
            'aging' => $summary['aging'],
            'statements' => $this->statementsInOrder($company, $asOf, $clientIds),
        ];
    }

    /**
     * @param string $packedIds client ids, 36 bytes each, in output order
     *
     * @return \Generator<int, array>
     */
    private function statementsInOrder(Company $company, \DateTimeImmutable $asOf, string $packedIds): \Generator
    {
        $chunk = self::CLIENT_BATCH * 36;
        for ($offset = 0, $length = \strlen($packedIds); $offset < $length; $offset += $chunk) {
            $ids = str_split(substr($packedIds, $offset, $chunk), 36);
            $clients = $this->statementQueries->clients($ids);
            $invoices = $this->statementQueries->openInvoicesByClient($company, $asOf, $ids);

            foreach ($ids as $id) {
                if (!isset($clients[$id])) {
                    continue;
                }
                yield $this->buildStatement($company, $clients[$id], $invoices[$id] ?? [], $asOf, includeBankAccounts: false);
            }
        }
    }

    /**
     * Number of days an invoice is overdue at $asOf (0 or negative when not yet due).
     * Invoices without a due date are considered due on their issue date.
     */
    public function daysOverdue(Invoice $invoice, \DateTimeImmutable $asOf): int
    {
        return $this->daysOverdueFrom($invoice->getDueDate() ?? $invoice->getIssueDate(), $asOf);
    }

    private function daysOverdueFrom(?\DateTimeInterface $due, \DateTimeImmutable $asOf): int
    {
        if (!$due) {
            return 0;
        }
        $dueDay = \DateTimeImmutable::createFromInterface($due)->setTime(0, 0);
        $asOfDay = $asOf->setTime(0, 0);

        $diff = $dueDay->diff($asOfDay);

        return $diff->invert ? -$diff->days : $diff->days;
    }

    public function bandFor(int $daysOverdue): string
    {
        foreach (self::AGING_BANDS as $key => $range) {
            if ($daysOverdue >= $range['min'] && ($range['max'] === null || $daysOverdue <= $range['max'])) {
                return $key;
            }
        }

        return 'over180';
    }

    /**
     * @param array<string, mixed>       $client   see clientRow()
     * @param list<array<string, mixed>> $invoices see invoiceRow()
     */
    private function buildStatement(Company $company, array $client, array $invoices, \DateTimeImmutable $asOf, bool $includeBankAccounts = true): array
    {
        $currency = $company->getDefaultCurrency() ?: 'RON';
        $aging = $this->emptyAging();
        $rows = [];
        $otherCurrencies = [];
        $invoiced = '0.00';
        $paid = '0.00';
        $outstanding = '0.00';
        $credits = '0.00';
        $count = 0;

        $date = static fn (?string $d): ?\DateTimeImmutable => $d === null || $d === '' ? null : new \DateTimeImmutable($d);
        foreach ($invoices as $k => $invoice) {
            $invoices[$k]['_issue'] = $date($invoice['issueDate']);
            $invoices[$k]['_due'] = $date($invoice['dueDate']);
        }

        usort($invoices, static function (array $a, array $b): int {
            $da = $a['_due'] ?? $a['_issue'];
            $db = $b['_due'] ?? $b['_issue'];

            return ($da?->getTimestamp() ?? 0) <=> ($db?->getTimestamp() ?? 0) ?: strcmp((string) $a['number'], (string) $b['number']);
        });

        foreach ($invoices as $invoice) {
            $balance = bcsub($invoice['total'], $invoice['amountPaid'], 2);
            if (bccomp($balance, '0.00', 2) === 0) {
                continue;
            }

            $days = $this->daysOverdueFrom($invoice['_due'] ?? $invoice['_issue'], $asOf);
            $band = bccomp($balance, '0.00', 2) > 0 ? $this->bandFor($days) : null;
            $invoiceCurrency = $invoice['currency'] ?: 'RON';

            $rows[] = [
                'id' => $invoice['id'],
                'number' => $invoice['number'],
                'status' => $invoice['status'],
                'issueDate' => $invoice['_issue']?->format('Y-m-d'),
                'dueDate' => $invoice['_due']?->format('Y-m-d'),
                'currency' => $invoiceCurrency,
                'total' => $invoice['total'],
                'paid' => $invoice['amountPaid'],
                'outstanding' => $balance,
                'daysOverdue' => max(0, $days),
                'band' => $invoiceCurrency === $currency ? $band : null,
            ];

            if ($invoiceCurrency !== $currency) {
                $otherCurrencies[$invoiceCurrency] ??= ['outstanding' => '0.00', 'count' => 0];
                $otherCurrencies[$invoiceCurrency]['outstanding'] = bcadd($otherCurrencies[$invoiceCurrency]['outstanding'], $balance, 2);
                $otherCurrencies[$invoiceCurrency]['count']++;
                continue;
            }

            $count++;
            $invoiced = bcadd($invoiced, $invoice['total'], 2);
            $paid = bcadd($paid, $invoice['amountPaid'], 2);
            if ($band !== null) {
                $outstanding = bcadd($outstanding, $balance, 2);
                $aging[$band]['amount'] = bcadd($aging[$band]['amount'], $balance, 2);
                $aging[$band]['count']++;
            } else {
                $credits = bcadd($credits, $balance, 2);
            }
        }

        $balance = bcadd($outstanding, $credits, 2);
        $overdue = '0.00';
        foreach ($aging as $key => $bandTotals) {
            if ($key !== 'current') {
                $overdue = bcadd($overdue, $bandTotals['amount'], 2);
            }
        }

        $statement = [
            'client' => [
                'id' => $client['id'],
                'name' => $client['name'],
                'type' => $client['type'],
                'cui' => $client['cui'],
                'cnp' => $client['cnp'],
                'email' => $client['email'],
                'address' => $client['address'],
                'city' => $client['city'],
                'county' => $client['county'],
                'country' => $client['country'],
            ],
            'company' => ['id' => (string) $company->getId(), 'name' => $company->getName()],
            'asOf' => $asOf->format('Y-m-d'),
            'currency' => $currency,
            'invoices' => $rows,
            'totals' => [
                'count' => $count,
                'invoiced' => $invoiced,
                'paid' => $paid,
                'outstanding' => $outstanding,
                'overdue' => $overdue,
                'credits' => $credits,
                'balance' => $balance,
            ],
            'aging' => $aging,
            'balance' => $balance,
            'otherCurrencies' => $otherCurrencies,
        ];

        if ($includeBankAccounts) {
            $statement['bankAccounts'] = array_map(static fn (BankAccount $account) => [
                'iban' => $account->getIban(),
                'bankName' => $account->getBankName(),
                'currency' => $account->getCurrency(),
                'isDefault' => $account->isDefault(),
            ], $this->bankAccounts($company));
        }

        return $statement;
    }

    /**
     * The client fields a statement shows, in the shape of a client table row.
     *
     * @return array<string, mixed>
     */
    private function clientRow(Client $client): array
    {
        return [
            'id' => (string) $client->getId(),
            'name' => $client->getName(),
            'type' => $client->getType(),
            'cui' => $client->getCui(),
            'cnp' => $client->getCnp(),
            'email' => $client->getEmail(),
            'address' => $client->getAddress(),
            'city' => $client->getCity(),
            'county' => $client->getCounty(),
            'country' => $client->getCountry(),
        ];
    }

    /**
     * The invoice fields a statement uses, in the shape ClientStatementQueries returns.
     *
     * @return array{id: string, number: ?string, status: string, issueDate: ?string, dueDate: ?string, currency: ?string, total: string, amountPaid: string}
     */
    private function invoiceRow(Invoice $invoice): array
    {
        return [
            'id' => (string) $invoice->getId(),
            'number' => $invoice->getNumber(),
            'status' => $invoice->getStatus()->value,
            'issueDate' => $invoice->getIssueDate()?->format('Y-m-d'),
            'dueDate' => $invoice->getDueDate()?->format('Y-m-d'),
            'currency' => $invoice->getCurrency(),
            'total' => $invoice->getTotal(),
            'amountPaid' => $invoice->getAmountPaid(),
        ];
    }

    /**
     * @return BankAccount[]
     */
    public function bankAccounts(Company $company): array
    {
        $accounts = array_values(array_filter(
            $this->bankAccountRepository->findByCompany($company),
            static fn (BankAccount $a) => $a->getIban() !== null && $a->getIban() !== '',
        ));
        usort($accounts, static fn (BankAccount $a, BankAccount $b) => (int) $b->isDefault() <=> (int) $a->isDefault());

        return $accounts;
    }

    /**
     * Outgoing, issued (not draft / cancelled / converted), not deleted, issued on
     * or before $asOf, with something still open (total != amount paid).
     *
     * @return Invoice[]
     */
    private function findOpenInvoices(Company $company, \DateTimeImmutable $asOf, ?Client $client = null): array
    {
        $qb = $this->invoiceRepository->createQueryBuilder('i')
            ->leftJoin('i.client', 'c')->addSelect('c')
            ->where('i.company = :company')
            ->andWhere('i.direction = :direction')
            ->andWhere('i.deletedAt IS NULL')
            ->andWhere('i.issueDate <= :asOf')
            ->andWhere('i.status NOT IN (:excluded)')
            ->andWhere('i.total <> i.amountPaid')
            ->setParameter('company', $company)
            ->setParameter('direction', InvoiceDirection::OUTGOING)
            ->setParameter('asOf', $asOf->setTime(23, 59, 59))
            ->setParameter('excluded', [DocumentStatus::DRAFT, DocumentStatus::CANCELLED, DocumentStatus::CONVERTED])
            ->orderBy('i.dueDate', 'ASC')
            ->addOrderBy('i.issueDate', 'ASC');

        if ($client) {
            $qb->andWhere('i.client = :client')->setParameter('client', $client);
        } else {
            $qb->andWhere('i.client IS NOT NULL');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return array<string, array{amount:string, count:int}>
     */
    private function emptyAging(): array
    {
        $aging = [];
        foreach (self::AGING_BANDS as $key => $_) {
            $aging[$key] = ['amount' => '0.00', 'count' => 0];
        }

        return $aging;
    }
}
