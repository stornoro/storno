<?php

namespace App\Command\Invoice;

use App\Repository\EmailTemplateRepository;
use App\Repository\InvoiceRepository;
use App\Service\InvoiceEmailService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:invoice:send-scheduled-emails',
    description: 'Send scheduled auto-emails for recurring invoices',
)]
class SendScheduledEmailsCommand extends Command
{
    public function __construct(
        private readonly InvoiceRepository $invoiceRepository,
        private readonly InvoiceEmailService $emailService,
        private readonly EmailTemplateRepository $emailTemplateRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max invoices to process per run', 100)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be sent without actually sending');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = (int) $input->getOption('limit');
        $dryRun = $input->getOption('dry-run');

        $now = new \DateTimeImmutable();
        $invoices = $this->invoiceRepository->findScheduledForEmail($now, $limit);

        if (empty($invoices)) {
            $io->info('No scheduled emails ready for sending.');
            return Command::SUCCESS;
        }

        $io->text(sprintf('Found %d invoice(s) with scheduled emails.', count($invoices)));

        $sent = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($invoices as $invoice) {
            $invoiceId = (string) $invoice->getId();
            $recipient = $this->emailService->getDefaultRecipient($invoice);

            if (!$recipient) {
                $io->text(sprintf('  Skipped: %s (number: %s) — no recipient email', $invoiceId, $invoice->getNumber()));
                $invoice->setScheduledEmailAt(null);
                $this->entityManager->flush();
                $skipped++;
                continue;
            }

            if ($dryRun) {
                $io->text(sprintf(
                    '  [DRY RUN] Would send: %s (number: %s, to: %s, scheduled: %s)',
                    $invoiceId,
                    $invoice->getNumber(),
                    $recipient,
                    $invoice->getScheduledEmailAt()?->format('c'),
                ));
                continue;
            }

            try {
                $company = $invoice->getCompany();
                $template = $company ? $this->emailTemplateRepository->findDefaultForCompany($company) : null;

                $this->emailService->send(
                    invoice: $invoice,
                    to: $recipient,
                    template: $template,
                );

                $invoice->setScheduledEmailAt(null);
                $this->entityManager->flush();
                $sent++;

                $io->text(sprintf('  Sent: %s (number: %s, to: %s)', $invoiceId, $invoice->getNumber(), $recipient));
            } catch (\Throwable $e) {
                $errors++;
                $this->logger->error('SendScheduledEmails: Failed to send email for invoice {id}: {error}', [
                    'id' => $invoiceId,
                    'number' => $invoice->getNumber(),
                    'error' => $e->getMessage(),
                ]);
                $io->text(sprintf('  Error: %s (number: %s) — %s', $invoiceId, $invoice->getNumber(), $e->getMessage()));
            }
        }

        if ($dryRun) {
            $io->success(sprintf('Dry run complete. %d email(s) would be sent, %d skipped (no recipient).', count($invoices) - $skipped, $skipped));
        } else {
            $io->success(sprintf('Sent %d email(s). %d skipped, %d errors.', $sent, $skipped, $errors));
        }

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
