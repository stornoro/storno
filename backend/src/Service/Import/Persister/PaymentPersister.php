<?php

namespace App\Service\Import\Persister;

use App\Entity\Company;
use App\Entity\ImportJob;
use App\Entity\Invoice;
use App\Entity\Payment;
use App\Enum\DocumentStatus;
use App\Service\Import\ImportResult;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Collections of issued invoices, one row per payment, with their real dates.
 *
 * An invoice history import records what was collected as one payment per invoice, dated on
 * the invoice date ("Import automat"), because an invoice report knows no better. The first
 * payment this import brings for such an invoice replaces that placeholder; what it replaced is
 * kept in the job summary, so reverting the job puts it back.
 */
class PaymentPersister implements EntityPersisterInterface, SummaryProviderInterface
{
    public const PLACEHOLDER_REFERENCE = 'Import automat';
    private const BATCH_SIZE = 200;

    /** @var array<string, string>|null lower-cased invoice number → invoice id */
    private ?array $invoiceIds = null;
    private ?string $companyId = null;

    /**
     * Invoices this job has touched.
     *
     * @var array<string, array{paid: float, latest: ?string, method: ?string, existing: array<string, true>}>
     */
    private array $touched = [];

    /** @var list<array{invoiceId: string, amount: string, date: string, method: string, jobId: ?string}> */
    private array $replaced = [];

    private int $created = 0;
    private int $batchCount = 0;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function supports(string $importType): bool
    {
        return $importType === 'payments';
    }

    public function persist(array $mappedData, Company $company, ImportResult $result): void
    {
        $this->initialize($company);

        $number = trim((string) ($mappedData['invoiceNumber'] ?? ''));
        $invoiceId = $this->invoiceIds[mb_strtolower($number)] ?? null;
        if ($invoiceId === null) {
            throw new \RuntimeException(sprintf('Factura %s nu exista printre facturile emise ale firmei.', $number));
        }

        $rawAmount = (string) ($mappedData['amount'] ?? '');
        if (!is_numeric($rawAmount) || (float) $rawAmount <= 0) {
            throw new \RuntimeException(sprintf('Suma "%s" nu este o suma incasata valida (factura %s).', $rawAmount, $number));
        }
        $amount = round((float) $rawAmount, 2);

        $date = null;
        if (!empty($mappedData['paymentDate'])) {
            $date = \DateTime::createFromFormat('!Y-m-d', (string) $mappedData['paymentDate']) ?: null;
            if ($date === null) {
                throw new \RuntimeException(sprintf('Data incasarii "%s" nu poate fi citita (factura %s).', $mappedData['paymentDate'], $number));
            }
        }

        /** @var Invoice|null $invoice */
        $invoice = $this->entityManager->find(Invoice::class, Uuid::fromString($invoiceId));
        if ($invoice === null) {
            throw new \RuntimeException(sprintf('Factura %s nu a putut fi incarcata.', $number));
        }
        $total = (float) ($invoice->getTotal() ?? '0');
        if ($total <= 0 || $invoice->getStatus() === DocumentStatus::CANCELLED) {
            throw new \RuntimeException(sprintf('Factura %s este anulata sau are total zero ori negativ: nu primeste incasari.', $number));
        }

        $job = $mappedData['_importJob'] ?? null;
        $this->touch($invoice, $job instanceof ImportJob ? $job : null);
        $state = &$this->touched[$invoiceId];

        $date ??= \DateTime::createFromInterface($invoice->getIssueDate() ?? new \DateTime('today'));
        $method = (string) ($mappedData['paymentMethod'] ?? $invoice->getPaymentMethod() ?? 'bank_transfer');
        $reference = isset($mappedData['reference']) ? (string) $mappedData['reference'] : null;

        // The same file imported twice: a payment the invoice already had before this job is not added again
        $signature = self::signature($date->format('Y-m-d'), $amount, $reference);
        if (isset($state['existing'][$signature])) {
            $result->incrementSkipped();

            return;
        }

        if ($state['paid'] + $amount > $total + 0.004) {
            throw new \RuntimeException(sprintf(
                'Incasarea de %s depaseste restul de plata al facturii %s (%s).',
                number_format($amount, 2, '.', ''),
                $number,
                number_format(max($total - $state['paid'], 0), 2, '.', ''),
            ));
        }

        $payment = new Payment();
        $payment->setCompany($company);
        $payment->setInvoice($invoice);
        $payment->setAmount(number_format($amount, 2, '.', ''));
        $payment->setCurrency($invoice->getCurrency() ?? 'RON');
        $payment->setPaymentDate($date);
        $payment->setPaymentMethod($method);
        $payment->setReference($reference ?? 'Import incasari');
        if (!empty($mappedData['notes'])) {
            $payment->setNotes((string) $mappedData['notes']);
        }
        $payment->setIsReconciled(true);
        if ($job instanceof ImportJob) {
            $payment->setImportJob($job);
        }
        $this->entityManager->persist($payment);

        $state['paid'] = round($state['paid'] + $amount, 2);
        if ($state['latest'] === null || $date->format('Y-m-d') >= $state['latest']) {
            $state['latest'] = $date->format('Y-m-d');
            $state['method'] = $method;
        }
        unset($state);

        self::applyTotals($invoice, $this->touched[$invoiceId]['paid'], $this->touched[$invoiceId]['latest'], $this->touched[$invoiceId]['method']);

        $this->created++;
        $result->incrementCreated();

        if (++$this->batchCount >= self::BATCH_SIZE) {
            $this->flush();
        }
    }

    /**
     * First payment of this job for the invoice: read what it already has, and drop the
     * placeholder payments of its history import when they are all it has.
     */
    private function touch(Invoice $invoice, ?ImportJob $job): void
    {
        $invoiceId = $invoice->getId()->toRfc4122();
        if (isset($this->touched[$invoiceId])) {
            return;
        }

        /** @var Payment[] $payments */
        $payments = $this->entityManager->getRepository(Payment::class)->findBy(['invoice' => $invoice]);

        $onlyPlaceholders = $payments !== [];
        foreach ($payments as $payment) {
            if ($payment->getReference() !== self::PLACEHOLDER_REFERENCE || $payment->getImportJob() === null) {
                $onlyPlaceholders = false;
            }
        }

        $state = ['paid' => 0.0, 'latest' => null, 'method' => null, 'existing' => []];
        foreach ($payments as $payment) {
            $date = $payment->getPaymentDate()?->format('Y-m-d') ?? '';
            if ($onlyPlaceholders) {
                $this->replaced[] = [
                    'invoiceId' => $invoiceId,
                    'amount' => $payment->getAmount(),
                    'date' => $date,
                    'method' => $payment->getPaymentMethod(),
                    'jobId' => $payment->getImportJob()?->getId()?->toRfc4122(),
                ];
                $this->entityManager->remove($payment);
                continue;
            }
            $state['paid'] = round($state['paid'] + (float) $payment->getAmount(), 2);
            $state['existing'][self::signature($date, (float) $payment->getAmount(), $payment->getReference())] = true;
            if ($state['latest'] === null || $date >= $state['latest']) {
                $state['latest'] = $date;
                $state['method'] = $payment->getPaymentMethod();
            }
        }

        $this->touched[$invoiceId] = $state;
    }

    private static function signature(string $date, float $amount, ?string $reference): string
    {
        return $date . '|' . number_format($amount, 2, '.', '') . '|' . mb_strtolower(trim((string) $reference));
    }

    /**
     * What the invoice shows once its payments add up to $paid. Only an imported record takes
     * its status from the collection; an invoice issued here keeps its own.
     */
    public static function applyTotals(Invoice $invoice, float $paid, ?string $latestDate, ?string $method): void
    {
        $total = (float) ($invoice->getTotal() ?? '0');
        $invoice->setAmountPaid(number_format($paid, 2, '.', ''));

        $fullyPaid = $total > 0 && $paid >= $total - 0.004;
        if ($fullyPaid) {
            $invoice->setPaidAt($latestDate ? new \DateTimeImmutable($latestDate) : new \DateTimeImmutable());
            if ($method !== null) {
                $invoice->setPaymentMethod($method);
            }
        } else {
            $invoice->setPaidAt(null);
        }

        $collectionStatuses = [DocumentStatus::SYNCED, DocumentStatus::PAID, DocumentStatus::PARTIALLY_PAID];
        if ($invoice->getImportJob() !== null && in_array($invoice->getStatus(), $collectionStatuses, true)) {
            $invoice->setStatus(match (true) {
                $fullyPaid => DocumentStatus::PAID,
                $paid > 0.004 => DocumentStatus::PARTIALLY_PAID,
                default => DocumentStatus::SYNCED,
            });
        }
    }

    /**
     * Undo a payments job: its payments go, the placeholders it replaced come back, and every
     * invoice it touched shows the collection its remaining payments add up to.
     */
    public function revertJob(ImportJob $job): void
    {
        $jobId = $job->getId()->toRfc4122();
        $conn = $this->entityManager->getConnection();

        $invoiceIds = $conn->fetchFirstColumn(
            'SELECT DISTINCT invoice_id FROM payment WHERE import_job_id = :jobId AND invoice_id IS NOT NULL',
            ['jobId' => $jobId],
        );
        $conn->executeStatement('DELETE FROM payment WHERE import_job_id = :jobId', ['jobId' => $jobId]);

        $company = $job->getCompany();
        foreach (($job->getSummary()['replacedPayments'] ?? []) as $old) {
            $invoice = isset($old['invoiceId']) ? $this->entityManager->find(Invoice::class, Uuid::fromString((string) $old['invoiceId'])) : null;
            if ($invoice === null || $company === null || $invoice->getCompany() !== $company) {
                continue;
            }
            $originJob = !empty($old['jobId']) ? $this->entityManager->find(ImportJob::class, Uuid::fromString((string) $old['jobId'])) : null;

            $payment = new Payment();
            $payment->setCompany($company);
            $payment->setInvoice($invoice);
            $payment->setAmount((string) $old['amount']);
            $payment->setCurrency($invoice->getCurrency() ?? 'RON');
            $payment->setPaymentDate(\DateTime::createFromFormat('!Y-m-d', (string) $old['date']) ?: new \DateTime('today'));
            $payment->setPaymentMethod((string) ($old['method'] ?? 'bank_transfer'));
            $payment->setReference(self::PLACEHOLDER_REFERENCE);
            $payment->setIsReconciled(true);
            $payment->setImportJob($originJob ?? $invoice->getImportJob());
            $this->entityManager->persist($payment);

            $invoiceIds[] = $invoice->getId()->toRfc4122();
        }
        $this->entityManager->flush();

        foreach (array_unique($invoiceIds) as $invoiceId) {
            $invoice = $this->entityManager->find(Invoice::class, Uuid::fromString((string) $invoiceId));
            if ($invoice === null) {
                continue;
            }
            $row = $conn->fetchAssociative(
                'SELECT COALESCE(SUM(amount), 0) AS paid, MAX(payment_date) AS latest FROM payment WHERE invoice_id = :id',
                ['id' => $invoiceId],
            );
            $latest = !empty($row['latest']) ? substr((string) $row['latest'], 0, 10) : null;
            self::applyTotals($invoice, round((float) ($row['paid'] ?? 0), 2), $latest, null);
        }
        $this->entityManager->flush();
    }

    private function initialize(Company $company): void
    {
        $companyId = $company->getId()->toRfc4122();
        if ($this->invoiceIds !== null && $this->companyId === $companyId) {
            return;
        }

        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            "SELECT id, LOWER(number) AS number FROM invoice
             WHERE company_id = :companyId AND deleted_at IS NULL AND direction = 'outgoing' AND number IS NOT NULL
             ORDER BY issue_date ASC",
            ['companyId' => $companyId],
        );
        $this->invoiceIds = [];
        foreach ($rows as $row) {
            // a number reused over the years names its latest invoice
            $this->invoiceIds[(string) $row['number']] = (string) $row['id'];
        }
        $this->companyId = $companyId;
    }

    public function flush(): void
    {
        $this->entityManager->flush();
        $this->entityManager->clear();
        $this->batchCount = 0;
    }

    public function reset(): void
    {
        $this->invoiceIds = null;
        $this->companyId = null;
        $this->touched = [];
        $this->replaced = [];
        $this->created = 0;
        $this->batchCount = 0;
    }

    public function getSummary(): array
    {
        return [
            'paymentsCreated' => $this->created,
            'invoicesUpdated' => count($this->touched),
            'replacedPayments' => $this->replaced,
        ];
    }
}
