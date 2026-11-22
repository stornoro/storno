<?php

namespace App\Service;

use App\Entity\Client;
use App\Entity\Company;
use App\Entity\EmailLog;
use App\Entity\Invoice;
use App\Entity\User;
use App\Exception\EmailSendBlockedException;
use App\Repository\EmailUnsubscribeRepository;
use App\Repository\InvoiceRepository;
use App\Service\Client\ClientStatementEmailService;
use App\Service\Client\ClientStatementService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Automatic reminders for overdue issued invoices. A company turns them on and sets the steps
 * (days after the due date); each step is one email to the client, sent once per invoice.
 *
 * A step is sent only in the days right after it falls due (GRACE_DAYS). Turning the feature on,
 * or importing years of history, therefore never mails clients about invoices that passed their
 * steps long ago. Those older invoices are the backlog: sendBacklog() mails them on request,
 * one statement per client.
 */
class OverdueReminderService
{
    public const CATEGORY = 'payment_reminder';

    public const DEFAULT_STEPS = [['days' => 3], ['days' => 7], ['days' => 14]];
    public const MAX_STEPS = 5;
    public const MAX_DAYS = 365;

    /** A step missed by the daily run (worker down, send error) is still sent this many days late. */
    public const GRACE_DAYS = 2;

    public const DEFAULT_SUBJECT = 'Reamintire de plata: factura [[invoice_number]]';
    public const DEFAULT_BODY = "Buna ziua,\n\n"
        . "Factura [[invoice_number]] din [[issue_date]], cu scadenta la [[due_date]], figureaza neachitata. "
        . "Restul de plata este [[balance]] [[currency]], la [[days_overdue]] zile de la scadenta.\n\n"
        . "Daca ati efectuat deja plata, va rugam sa ignorati acest mesaj.\n\n"
        . "Cu stima,\n[[company_name]]";

    public function __construct(
        private readonly InvoiceEmailService $invoiceEmailService,
        private readonly EmailUnsubscribeRepository $emailUnsubscribeRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly InvoiceRepository $invoiceRepository,
        private readonly ClientStatementService $statementService,
        private readonly ClientStatementEmailService $statementEmailService,
    ) {}

    /**
     * Validates the steps sent by a client of the API and returns them sorted by days.
     *
     * @return list<array{days: int, subject: ?string, body: ?string}>|null null = the default steps
     *
     * @throws \InvalidArgumentException with a message fit for the API response
     */
    public static function normalizeSteps(mixed $raw): ?array
    {
        if ($raw === null) {
            return null;
        }
        if (!is_array($raw) || !array_is_list($raw)) {
            throw new \InvalidArgumentException('overdueReminderSteps must be a list of {days, subject, body}.');
        }
        if ($raw === []) {
            throw new \InvalidArgumentException('overdueReminderSteps needs at least one step; send null for the default steps.');
        }
        if (count($raw) > self::MAX_STEPS) {
            throw new \InvalidArgumentException(sprintf('overdueReminderSteps accepts at most %d steps.', self::MAX_STEPS));
        }

        $steps = [];
        foreach ($raw as $step) {
            if (!is_array($step) || !isset($step['days']) || !is_numeric($step['days']) || (int) $step['days'] != $step['days']) {
                throw new \InvalidArgumentException('Each reminder step needs "days" as a whole number.');
            }
            $days = (int) $step['days'];
            if ($days < 1 || $days > self::MAX_DAYS) {
                throw new \InvalidArgumentException(sprintf('Reminder step days must be between 1 and %d.', self::MAX_DAYS));
            }
            if (isset($steps[$days])) {
                throw new \InvalidArgumentException(sprintf('Two reminder steps share the same day (%d).', $days));
            }
            $steps[$days] = [
                'days' => $days,
                'subject' => self::text($step['subject'] ?? null, 200, 'subject'),
                'body' => self::text($step['body'] ?? null, 5000, 'body'),
            ];
        }
        ksort($steps);

        return array_values($steps);
    }

    private static function text(mixed $value, int $max, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException(sprintf('Reminder step %s must be text.', $field));
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $max) {
            throw new \InvalidArgumentException(sprintf('Reminder step %s must be at most %d characters.', $field, $max));
        }
        if ($field === 'subject' && preg_match('/[<>\r\n]/', $value)) {
            throw new \InvalidArgumentException('Reminder step subject must be a single line without < or >.');
        }

        return $value;
    }

    /**
     * @return list<array{days: int, subject: ?string, body: ?string}> sorted by days
     */
    public static function stepsFor(Company $company): array
    {
        try {
            $steps = self::normalizeSteps($company->getOverdueReminderSteps());
        } catch (\InvalidArgumentException) {
            $steps = null; // a stored value this code no longer accepts falls back to the defaults
        }

        return $steps ?? self::normalizeSteps(self::DEFAULT_STEPS);
    }

    /**
     * The due-date windows in which an invoice of the company has a step to send today.
     *
     * @return list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}> [from, to], both inclusive
     */
    public static function windowsFor(Company $company, \DateTimeImmutable $today): array
    {
        $today = $today->setTime(0, 0);
        $windows = [];
        foreach (self::stepsFor($company) as $step) {
            $windows[] = [
                $today->modify(sprintf('-%d days', $step['days'] + self::GRACE_DAYS)),
                $today->modify(sprintf('-%d days', $step['days'])),
            ];
        }

        return $windows;
    }

    /**
     * The step to send today for this invoice, or null. Says nothing about the opt-outs or the
     * recipient: those are skipReason()'s.
     *
     * @return array{days: int, subject: ?string, body: ?string}|null
     */
    public static function dueStep(Invoice $invoice, ?\DateTimeImmutable $today = null): ?array
    {
        $company = $invoice->getCompany();
        if ($company === null) {
            return null;
        }
        $overdue = InvoiceEmailService::daysOverdue($invoice, $today);
        if ($overdue < 1) {
            return null;
        }

        $candidate = null;
        foreach (self::stepsFor($company) as $step) {
            if ($step['days'] <= $overdue) {
                $candidate = $step;
            }
        }
        if ($candidate === null || $overdue - $candidate['days'] > self::GRACE_DAYS) {
            return null;
        }
        $last = $invoice->getLastOverdueReminderDays();
        if ($last !== null && $last >= $candidate['days']) {
            return null;
        }

        return $candidate;
    }

    /**
     * The step this invoice passed without a reminder and can no longer get from the daily run
     * (it is beyond the grace days), or null. Later steps still come from the daily run.
     *
     * @return array{days: int, subject: ?string, body: ?string}|null
     */
    public static function missedStep(Invoice $invoice, ?\DateTimeImmutable $today = null): ?array
    {
        $company = $invoice->getCompany();
        if ($company === null) {
            return null;
        }
        $overdue = InvoiceEmailService::daysOverdue($invoice, $today);

        $candidate = null;
        foreach (self::stepsFor($company) as $step) {
            if ($step['days'] <= $overdue) {
                $candidate = $step;
            }
        }
        if ($candidate === null || $overdue - $candidate['days'] <= self::GRACE_DAYS) {
            return null;
        }
        $last = $invoice->getLastOverdueReminderDays();
        if ($last !== null && $last >= $candidate['days']) {
            return null;
        }

        return $candidate;
    }

    /**
     * Mails the backlog: overdue invoices that passed a step before the daily run could send it
     * (the feature was off, or the invoice was imported). One statement email per client, listing
     * that client's backlog invoices. Each invoice is then marked with the step it missed, so a
     * second call sends nothing and the daily run carries on with the later steps.
     *
     * @return array{dryRun: bool, clients: int, invoices: int, sent: int, failed: int, skipped: array<string, int>, stoppedReason: ?string, results: list<array<string, mixed>>}
     */
    public function sendBacklog(Company $company, ?User $sentBy = null, bool $dryRun = false, ?\DateTimeImmutable $today = null): array
    {
        $today = ($today ?? new \DateTimeImmutable('today'))->setTime(0, 0);
        $firstStep = self::stepsFor($company)[0]['days'];
        $candidates = $this->invoiceRepository->findOverdueBacklogForReminders(
            $company,
            $today->modify(sprintf('-%d days', $firstStep + self::GRACE_DAYS)),
        );

        /** @var array<string, array{client: Client, items: list<array{0: Invoice, 1: array}>}> $byClient */
        $byClient = [];
        $skipped = [];
        foreach ($candidates as $invoice) {
            $step = self::missedStep($invoice, $today);
            if ($step === null) {
                continue;
            }
            $reason = $this->skipReason($invoice);
            if ($reason !== null) {
                $skipped[$reason] = ($skipped[$reason] ?? 0) + 1;
                continue;
            }
            $client = $invoice->getClient();
            $key = (string) $client->getId();
            $byClient[$key] ??= ['client' => $client, 'items' => []];
            $byClient[$key]['items'][] = [$invoice, $step];
        }

        $results = [];
        $sent = 0;
        $failed = 0;
        $invoiceCount = 0;
        $stopped = null;

        foreach ($byClient as $group) {
            $client = $group['client'];
            $invoices = array_column($group['items'], 0);
            $invoiceCount += count($invoices);
            $statement = $this->statementService->statementFromInvoices($client, $invoices, $today);
            $entry = [
                'clientId' => (string) $client->getId(),
                'clientName' => $client->getName(),
                'email' => $client->getEmail(),
                'invoices' => count($invoices),
                'balance' => $statement['balance'],
                'currency' => $statement['currency'],
                'status' => $dryRun ? 'would_send' : 'sent',
                'reason' => null,
            ];

            if ($dryRun) {
                $sent++;
                $results[] = $entry;
                continue;
            }
            if ($stopped !== null) {
                $entry['status'] = 'skipped';
                $entry['reason'] = $stopped;
                $results[] = $entry;
                continue;
            }

            try {
                $log = $this->statementEmailService->send($client, $today, (string) $client->getEmail(), null, $sentBy, $statement);
                $entry['emailLogId'] = (string) $log->getId();
                $now = new \DateTimeImmutable();
                foreach ($group['items'] as [$invoice, $step]) {
                    $invoice->setLastOverdueReminderDays($step['days']);
                    $invoice->setLastOverdueReminderAt($now);
                }
                $this->entityManager->flush();
                $sent++;
            } catch (EmailSendBlockedException $e) {
                $entry['status'] = 'failed';
                $entry['reason'] = $e->errorCode;
                $failed++;
                if (in_array($e->errorCode, [EmailSendBlockedException::CODE_DAILY_LIMIT, EmailSendBlockedException::CODE_RATE_LIMIT, EmailSendBlockedException::CODE_PLAN_LIMIT], true)) {
                    $stopped = $e->errorCode;
                }
            } catch (\Throwable $e) {
                $entry['status'] = 'failed';
                $entry['reason'] = 'send_failed';
                $entry['error'] = mb_substr($e->getMessage(), 0, 255);
                $failed++;
            }
            $results[] = $entry;
        }

        return [
            'dryRun' => $dryRun,
            'clients' => count($byClient),
            'invoices' => $invoiceCount,
            'sent' => $sent,
            'failed' => $failed,
            'skipped' => $skipped,
            'stoppedReason' => $stopped,
            'results' => $results,
        ];
    }

    /** Why this invoice gets no reminder at all, or null when it may get one. */
    public function skipReason(Invoice $invoice): ?string
    {
        $company = $invoice->getCompany();
        if ($company === null || !$company->isOverdueRemindersEnabled()) {
            return 'reminders_off';
        }
        if ($company->isReadOnly() || $company->isDeleted()) {
            return 'company_inactive';
        }
        if ($invoice->isOverdueRemindersDisabled()) {
            return 'invoice_excluded';
        }
        if ($invoice->getClient()?->isOverdueRemindersDisabled()) {
            return 'client_excluded';
        }
        if (bccomp($invoice->getBalance(), '0', 2) <= 0) {
            return 'paid';
        }
        $to = $invoice->getClient()?->getEmail();
        if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return 'no_email';
        }
        if ($this->emailUnsubscribeRepository->isUnsubscribed($to, $company)) {
            return 'unsubscribed';
        }

        return null;
    }

    /**
     * Sends one step and records it on the invoice. The invoice is marked only after a
     * successful send, so a failed one is retried by the next run inside the grace window.
     *
     * @param array{days: int, subject: ?string, body: ?string} $step
     */
    public function send(Invoice $invoice, array $step): EmailLog
    {
        $log = $this->invoiceEmailService->send(
            invoice: $invoice,
            to: (string) $invoice->getClient()?->getEmail(),
            subject: $step['subject'] ?? self::DEFAULT_SUBJECT,
            body: $step['body'] ?? self::DEFAULT_BODY,
            category: self::CATEGORY,
            templateName: sprintf('Reamintire la %d zile', $step['days']),
        );

        $invoice->setLastOverdueReminderDays($step['days']);
        $invoice->setLastOverdueReminderAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        return $log;
    }
}
