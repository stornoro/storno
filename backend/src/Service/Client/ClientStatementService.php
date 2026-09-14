<?php

namespace App\Service\Client;

use App\Entity\BankAccount;
use App\Entity\Client;
use App\Entity\Company;
use App\Entity\Invoice;
use App\Enum\DocumentStatus;
use App\Enum\InvoiceDirection;
use App\Repository\BankAccountRepository;
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

    public function __construct(
        private readonly InvoiceRepository $invoiceRepository,
        private readonly BankAccountRepository $bankAccountRepository,
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

        return $this->buildStatement($company, $client, array_values($invoices), $asOf);
    }

    /**
     * One statement per client with a positive balance, sorted by balance
     * descending, plus company-wide totals and aging.
     */
    public function statementsForCompany(Company $company, \DateTimeImmutable $asOf): array
    {
        $byClient = [];
        $clients = [];
        foreach ($this->findOpenInvoices($company, $asOf) as $invoice) {
            $client = $invoice->getClient();
            if (!$client || $client->getDeletedAt() !== null) {
                continue;
            }
            $key = (string) $client->getId();
            $clients[$key] = $client;
            $byClient[$key][] = $invoice;
        }

        $currency = $company->getDefaultCurrency() ?: 'RON';
        $statements = [];
        $aging = $this->emptyAging();
        $totals = ['invoiced' => '0.00', 'paid' => '0.00', 'outstanding' => '0.00', 'credits' => '0.00', 'balance' => '0.00', 'count' => 0];

        foreach ($byClient as $key => $invoices) {
            $statement = $this->buildStatement($company, $clients[$key], $invoices, $asOf, includeBankAccounts: false);
            if (bccomp($statement['balance'], '0.00', 2) <= 0) {
                continue;
            }
            $statements[] = $statement;

            foreach (self::AGING_BANDS as $band => $_) {
                $aging[$band]['amount'] = bcadd($aging[$band]['amount'], $statement['aging'][$band]['amount'], 2);
                $aging[$band]['count'] += $statement['aging'][$band]['count'];
            }
            foreach (['invoiced', 'paid', 'outstanding', 'credits', 'balance'] as $field) {
                $totals[$field] = bcadd($totals[$field], $statement['totals'][$field] ?? $statement[$field], 2);
            }
            $totals['count'] += $statement['totals']['count'];
        }

        usort($statements, static fn (array $a, array $b) => bccomp($b['balance'], $a['balance'], 2));

        return [
            'company' => ['id' => (string) $company->getId(), 'name' => $company->getName()],
            'asOf' => $asOf->format('Y-m-d'),
            'currency' => $currency,
            'clientCount' => \count($statements),
            'totals' => $totals,
            'aging' => $aging,
            'statements' => $statements,
        ];
    }

    /**
     * Number of days an invoice is overdue at $asOf (0 or negative when not yet due).
     * Invoices without a due date are considered due on their issue date.
     */
    public function daysOverdue(Invoice $invoice, \DateTimeImmutable $asOf): int
    {
        $due = $invoice->getDueDate() ?? $invoice->getIssueDate();
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
     * @param Invoice[] $invoices
     */
    private function buildStatement(Company $company, Client $client, array $invoices, \DateTimeImmutable $asOf, bool $includeBankAccounts = true): array
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

        usort($invoices, static function (Invoice $a, Invoice $b): int {
            $da = $a->getDueDate() ?? $a->getIssueDate();
            $db = $b->getDueDate() ?? $b->getIssueDate();

            return ($da?->getTimestamp() ?? 0) <=> ($db?->getTimestamp() ?? 0) ?: strcmp((string) $a->getNumber(), (string) $b->getNumber());
        });

        foreach ($invoices as $invoice) {
            $balance = $invoice->getBalance();
            if (bccomp($balance, '0.00', 2) === 0) {
                continue;
            }

            $days = $this->daysOverdue($invoice, $asOf);
            $band = bccomp($balance, '0.00', 2) > 0 ? $this->bandFor($days) : null;
            $invoiceCurrency = $invoice->getCurrency() ?: 'RON';

            $rows[] = [
                'id' => (string) $invoice->getId(),
                'number' => $invoice->getNumber(),
                'status' => $invoice->getStatus()->value,
                'issueDate' => $invoice->getIssueDate()?->format('Y-m-d'),
                'dueDate' => $invoice->getDueDate()?->format('Y-m-d'),
                'currency' => $invoiceCurrency,
                'total' => $invoice->getTotal(),
                'paid' => $invoice->getAmountPaid(),
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
            $invoiced = bcadd($invoiced, $invoice->getTotal(), 2);
            $paid = bcadd($paid, $invoice->getAmountPaid(), 2);
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
