<?php

namespace App\Command\Invoice;

use App\Command\Support\CommandProgress;
use App\Service\RecurringInvoiceProcessor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:invoice:process-recurring',
    description: 'Process recurring invoices and generate draft invoices for due items',
)]
class ProcessRecurringInvoicesCommand extends Command
{
    public function __construct(
        private readonly RecurringInvoiceProcessor $processor,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Stop after this many recurring invoices (default: process everything that is due)')
            ->addOption('date', null, InputOption::VALUE_REQUIRED, 'Process as if today is this date (Y-m-d)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simulate processing without creating invoices');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $limitOption = $input->getOption('limit');
        $cap = $limitOption !== null && (int) $limitOption > 0 ? (int) $limitOption : null;
        $dryRun = (bool) $input->getOption('dry-run');

        $dateStr = $input->getOption('date');
        $date = $dateStr ? new \DateTime($dateStr) : new \DateTime();

        if ($dryRun) {
            $io->note('Running in dry-run mode — no invoices will be created.');
        }

        $io->info(sprintf('Processing recurring invoices due on or before %s...', $date->format('Y-m-d')));

        $progress = null;
        $result = $this->processor->processRecurringInvoices(
            $date,
            $cap,
            $dryRun,
            afterBatch: function (int $processed, int $errors) use (&$progress): void {
                $progress?->count('issued', $processed);
                $progress?->count('errors', $errors);
                $progress?->advance(1);
                // Issued invoices, their lines and the templates of the batch are not needed again.
                $this->entityManager->clear();
            },
            onStart: function (int $due) use (&$progress, $output, $cap): void {
                $batches = (int) ceil($due / 50);
                $progress = new CommandProgress($output, 'recurring batches', $batches);
                $progress->start(sprintf('%d templates due%s, each issued at most once this run', $due, $cap !== null ? " (capped at {$cap})" : ''));
            },
        );
        $progress?->finish();

        if ($result['invoices'] !== []) {
            $io->listing($result['invoices']);
        }

        $io->success(sprintf('Done. Processed: %d, Errors: %d', $result['processed'], $result['errors']));

        return $result['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
