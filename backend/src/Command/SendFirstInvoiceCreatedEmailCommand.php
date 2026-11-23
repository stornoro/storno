<?php

namespace App\Command;

use App\Command\Support\CommandProgress;
use App\Entity\Invoice;
use App\Entity\Organization;
use App\Message\SendFirstInvoiceCreatedEmailMessage;
use App\Service\EditionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:lifecycle:first-invoice-created-email',
    description: 'Send a congratulatory email when an organization issues their first invoice (run hourly)',
)]
class SendFirstInvoiceCreatedEmailCommand extends Command
{
    private const SETTING_KEY = 'first_invoice_created_sent';
    private const BATCH_SIZE = 200;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $bus,
        private readonly EditionService $editionService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be dispatched without sending');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->editionService->isSaas()) {
            $io->note('Skipping — not SaaS edition.');
            return Command::SUCCESS;
        }

        $dryRun = $input->getOption('dry-run');
        $window = new \DateTimeImmutable('-24 hours');
        $dispatched = 0;

        // Only an organization whose invoice was created inside the window can qualify: the email
        // goes out for the first and only invoice, and that invoice must be less than 24 hours old.
        // This lists those organizations in SQL instead of counting invoices for every organization
        // that has not been flagged yet.
        $orgIds = $this->entityManager->createQueryBuilder()
            ->select('DISTINCT o.id')
            ->from(Invoice::class, 'i')
            ->join('i.company', 'c')
            ->join('c.organization', 'o')
            ->where('i.deletedAt IS NULL')
            ->andWhere('i.createdAt >= :window')
            ->setParameter('window', $window)
            ->getQuery()
            ->getSingleColumnResult();

        $progress = new CommandProgress($output, 'first-invoice-email', count($orgIds));
        $progress->start(sprintf('%d organizations with an invoice created since %s', count($orgIds), $window->format('Y-m-d H:i')));

        foreach (array_chunk($orgIds, self::BATCH_SIZE) as $chunk) {
            $orgs = $this->entityManager->getRepository(Organization::class)->findBy(['id' => $chunk]);
            $this->processOrganizations($orgs, $window, $dryRun, $io, $progress, $dispatched);
            $this->entityManager->clear();
        }
        $progress->finish();

        if ($dryRun) {
            $io->note(sprintf('Dry run: %d first-invoice-created emails would be dispatched.', $dispatched));
        } else {
            $io->success(sprintf('Dispatched %d first-invoice-created email(s).', $dispatched));
        }

        return Command::SUCCESS;
    }

    /** @param Organization[] $orgs */
    private function processOrganizations(array $orgs, \DateTimeImmutable $window, bool $dryRun, SymfonyStyle $io, CommandProgress $progress, int &$dispatched): void
    {
        foreach ($orgs as $org) {
            $progress->advance();
            $settings = $org->getSettings();

            if (!empty($settings[self::SETTING_KEY])) {
                continue;
            }

            $count = (int) $this->entityManager->createQueryBuilder()
                ->select('COUNT(i.id)')
                ->from(Invoice::class, 'i')
                ->join('i.company', 'c')
                ->where('c.organization = :org')
                ->andWhere('i.deletedAt IS NULL')
                ->setParameter('org', $org)
                ->getQuery()
                ->getSingleScalarResult();

            if ($count !== 1) {
                continue;
            }

            $firstInvoice = $this->entityManager->createQueryBuilder()
                ->select('i')
                ->from(Invoice::class, 'i')
                ->join('i.company', 'c')
                ->where('c.organization = :org')
                ->andWhere('i.deletedAt IS NULL')
                ->setParameter('org', $org)
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();

            if ($firstInvoice === null || $firstInvoice->getCreatedAt() === null || $firstInvoice->getCreatedAt() < $window) {
                continue;
            }

            if ($dryRun) {
                $io->text(sprintf(
                    '[DRY RUN] Would dispatch first-invoice-created email for org %s',
                    (string) $org->getId(),
                ));
            } else {
                $this->bus->dispatch(new SendFirstInvoiceCreatedEmailMessage((string) $org->getId()));
                $settings[self::SETTING_KEY] = (new \DateTimeImmutable())->format('Y-m-d');
                $org->setSettings($settings);
                $this->entityManager->flush();
                $io->text(sprintf('Dispatched first-invoice-created email for org %s', (string) $org->getId()));
            }

            $dispatched++;
            $progress->count('dispatched');
        }
    }
}
