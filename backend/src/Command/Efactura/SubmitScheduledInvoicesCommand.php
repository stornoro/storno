<?php

namespace App\Command\Efactura;

use App\Entity\Invoice;
use App\Enum\EInvoiceProvider;
use App\Message\EInvoice\SubmitEInvoiceMessage;
use App\Repository\CompanyEInvoiceConfigRepository;
use App\Repository\InvoiceRepository;
use App\Service\Anaf\AnafTokenResolver;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:efactura:submit-scheduled',
    description: 'Submit scheduled invoices to their configured e-invoice provider when the delay period has elapsed',
)]
class SubmitScheduledInvoicesCommand extends Command
{
    public function __construct(
        private readonly InvoiceRepository $invoiceRepository,
        private readonly CompanyEInvoiceConfigRepository $configRepository,
        private readonly AnafTokenResolver $anafTokenResolver,
        private readonly MessageBusInterface $messageBus,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max invoices to process per run', 100)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be dispatched without actually dispatching');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = (int) $input->getOption('limit');
        $dryRun = $input->getOption('dry-run');

        $now = new \DateTimeImmutable();
        $invoices = $this->invoiceRepository->findScheduledForSubmission($now, $limit);

        if (empty($invoices)) {
            $io->info('No scheduled invoices ready for submission.');
            return Command::SUCCESS;
        }

        $io->text(sprintf('Found %d invoice(s) ready for submission.', count($invoices)));

        $dispatched = 0;
        $skipped = 0;

        foreach ($invoices as $invoice) {
            $invoiceId = (string) $invoice->getId();
            $provider = $this->resolveProvider($invoice);

            if ($provider === null) {
                $this->logger->debug('SubmitScheduledInvoices: No provider configured, skipping.', [
                    'invoiceId' => $invoiceId,
                    'number' => $invoice->getNumber(),
                ]);
                $skipped++;
                continue;
            }

            if ($dryRun) {
                $io->text(sprintf(
                    '  [DRY RUN] Would dispatch: %s (number: %s, provider: %s, scheduled: %s)',
                    $invoiceId,
                    $invoice->getNumber(),
                    $provider->value,
                    $invoice->getScheduledSendAt()?->format('c'),
                ));
                continue;
            }

            $this->messageBus->dispatch(new SubmitEInvoiceMessage(
                invoiceId: $invoiceId,
                provider: $provider->value,
            ));
            $dispatched++;

            $this->logger->info('SubmitScheduledInvoices: Dispatched invoice for submission.', [
                'invoiceId' => $invoiceId,
                'number' => $invoice->getNumber(),
                'provider' => $provider->value,
                'scheduledSendAt' => $invoice->getScheduledSendAt()?->format('c'),
            ]);

            $io->text(sprintf('  Dispatched: %s (number: %s, provider: %s)', $invoiceId, $invoice->getNumber(), $provider->value));

            // Flush in batches of 50
            if ($dispatched % 50 === 0) {
                $this->entityManager->clear();
            }
        }

        if ($dryRun) {
            $io->success(sprintf('Dry run complete. %d invoice(s) would be dispatched, %d skipped (no provider).', count($invoices) - $skipped, $skipped));
        } else {
            $io->success(sprintf('Dispatched %d invoice(s) for e-invoice submission. %d skipped (no provider).', $dispatched, $skipped));
        }

        return Command::SUCCESS;
    }

    /**
     * Resolve the e-invoice provider for a given invoice based on its company configuration.
     *
     * For Romanian companies (country=RO): use ANAF if a valid token exists.
     * For others: use the first enabled CompanyEInvoiceConfig.
     */
    private function resolveProvider(Invoice $invoice): ?EInvoiceProvider
    {
        $company = $invoice->getCompany();
        if ($company === null) {
            return null;
        }

        // Romanian companies default to ANAF
        if ($company->getCountry() === 'RO') {
            $token = $this->anafTokenResolver->resolve($company);
            if ($token !== null) {
                return EInvoiceProvider::ANAF;
            }
        }

        // Check for any enabled e-invoice config
        $configs = $this->configRepository->findByCompany($company);
        foreach ($configs as $config) {
            if ($config->isEnabled()) {
                return $config->getProvider();
            }
        }

        return null;
    }
}
