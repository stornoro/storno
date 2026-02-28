<?php

namespace App\Command\Invoice;

use App\Service\RecurringInvoiceProcessor;
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
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of recurring invoices to process', 100)
            ->addOption('date', null, InputOption::VALUE_REQUIRED, 'Process as if today is this date (Y-m-d)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simulate processing without creating invoices');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $limit = (int) $input->getOption('limit');
        $dryRun = $input->getOption('dry-run');

        $dateStr = $input->getOption('date');
        $date = $dateStr ? new \DateTime($dateStr) : new \DateTime();

        if ($dryRun) {
            $io->note('Running in dry-run mode — no invoices will be created.');
        }

        $io->info(sprintf('Processing recurring invoices due on or before %s...', $date->format('Y-m-d')));

        $result = $this->processor->processRecurringInvoices($date, $limit, $dryRun);

        if (!empty($result['invoices'])) {
            $io->listing($result['invoices']);
        }

        $io->success(sprintf(
            'Done. Processed: %d, Errors: %d',
            $result['processed'],
            $result['errors'],
        ));

        return $result['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
