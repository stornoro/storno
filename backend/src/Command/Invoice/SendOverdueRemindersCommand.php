<?php

namespace App\Command\Invoice;

use App\Entity\Company;
use App\Exception\EmailSendBlockedException;
use App\Repository\CompanyRepository;
use App\Repository\InvoiceRepository;
use App\Service\OverdueReminderService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

#[AsCommand(
    name: 'app:invoice:send-overdue-reminders',
    description: 'Email clients about overdue invoices, on the reminder steps their supplier configured',
)]
class SendOverdueRemindersCommand extends Command
{
    public function __construct(
        private readonly CompanyRepository $companyRepository,
        private readonly InvoiceRepository $invoiceRepository,
        private readonly OverdueReminderService $reminderService,
        private readonly LoggerInterface $logger,
        private readonly LockFactory $lockFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would be sent, send nothing')
            ->addOption('company', null, InputOption::VALUE_REQUIRED, 'Only this company (UUID)')
            ->addOption('date', null, InputOption::VALUE_REQUIRED, 'Run as if today were this date (YYYY-MM-DD); dry-run only');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $today = new \DateTimeImmutable('today');
        if ($input->getOption('date')) {
            if (!$dryRun) {
                $io->error('--date only works together with --dry-run.');

                return Command::INVALID;
            }
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $input->getOption('date'));
            if ($parsed === false) {
                $io->error('--date expects YYYY-MM-DD.');

                return Command::INVALID;
            }
            $today = $parsed;
        }

        $lock = $this->lockFactory->createLock('send-overdue-reminders', 1800);
        if (!$lock->acquire()) {
            $io->warning('Another instance is already running. Skipping.');

            return Command::SUCCESS;
        }

        try {
            return $this->run_($io, $today, $dryRun, $input->getOption('company'));
        } finally {
            $lock->release();
        }
    }

    private function run_(SymfonyStyle $io, \DateTimeImmutable $today, bool $dryRun, ?string $companyId): int
    {
        $criteria = ['overdueRemindersEnabled' => true];
        if ($companyId) {
            $criteria['id'] = $companyId;
        }
        /** @var Company[] $companies */
        $companies = $this->companyRepository->findBy($criteria);

        $io->text(sprintf(
            '%s: overdue reminders for %d compan%s, as of %s.',
            $dryRun ? 'Dry run' : 'Sending',
            count($companies),
            count($companies) === 1 ? 'y' : 'ies',
            $today->format('Y-m-d'),
        ));

        $sent = 0;
        $errors = 0;
        $skipped = [];

        foreach ($companies as $company) {
            if ($company->isDeleted() || $company->isReadOnly()) {
                continue;
            }

            $invoices = $this->invoiceRepository->findOverdueForReminders(
                $company,
                OverdueReminderService::windowsFor($company, $today),
            );

            foreach ($invoices as $invoice) {
                $step = OverdueReminderService::dueStep($invoice, $today);
                if ($step === null) {
                    continue; // this step was already sent
                }
                $reason = $this->reminderService->skipReason($invoice);
                if ($reason !== null) {
                    $skipped[$reason] = ($skipped[$reason] ?? 0) + 1;
                    continue;
                }

                $label = sprintf('%s, invoice %s, step %d days, to %s', $company->getName(), $invoice->getNumber(), $step['days'], $invoice->getClient()?->getEmail());
                if ($dryRun) {
                    $io->text('  [DRY RUN] ' . $label);
                    $sent++;
                    continue;
                }

                try {
                    $this->reminderService->send($invoice, $step);
                    $sent++;
                    $io->text('  Sent: ' . $label);
                } catch (EmailSendBlockedException $e) {
                    $errors++;
                    $this->logger->warning('Overdue reminder blocked for invoice {id}: {error}', [
                        'id' => (string) $invoice->getId(),
                        'code' => $e->errorCode,
                        'error' => $e->getMessage(),
                    ]);
                    $io->text(sprintf('  Blocked: %s (%s)', $label, $e->errorCode));
                    // The plan or the daily cap stops every other email of this company too.
                    if (in_array($e->errorCode, [EmailSendBlockedException::CODE_PLAN_LIMIT, EmailSendBlockedException::CODE_DAILY_LIMIT], true)) {
                        break;
                    }
                } catch (\Throwable $e) {
                    $errors++;
                    $this->logger->error('Overdue reminder failed for invoice {id}: {error}', [
                        'id' => (string) $invoice->getId(),
                        'error' => $e->getMessage(),
                    ]);
                    $io->text(sprintf('  Error: %s (%s)', $label, $e->getMessage()));
                }
            }
        }

        $skippedText = $skipped === [] ? 'none' : implode(', ', array_map(
            static fn (string $reason, int $count) => $reason . ' ' . $count,
            array_keys($skipped),
            $skipped,
        ));
        $io->success(sprintf(
            '%s %d reminder(s). Errors: %d. Skipped: %s.',
            $dryRun ? 'Would send' : 'Sent',
            $sent,
            $errors,
            $skippedText,
        ));

        // A blocked or failed email is retried by tomorrow's run; the scheduler has nothing to act on.
        return Command::SUCCESS;
    }
}
