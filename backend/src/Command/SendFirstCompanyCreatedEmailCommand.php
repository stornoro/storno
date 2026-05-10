<?php

namespace App\Command;

use App\Entity\Company;
use App\Entity\Organization;
use App\Message\SendFirstCompanyCreatedEmailMessage;
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
    name: 'app:lifecycle:first-company-created-email',
    description: 'Send a congratulatory email when an organization creates their first company (run hourly)',
)]
class SendFirstCompanyCreatedEmailCommand extends Command
{
    private const SETTING_KEY = 'first_company_created_sent';

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

        $orgs = $this->entityManager->createQueryBuilder()
            ->select('o')
            ->from(Organization::class, 'o')
            ->join('o.companies', 'c')
            ->where('o.deletedAt IS NULL')
            ->getQuery()
            ->getResult();

        foreach ($orgs as $org) {
            $settings = $org->getSettings();

            if (!empty($settings[self::SETTING_KEY])) {
                continue;
            }

            $companies = $org->getCompanies()->filter(
                fn (Company $c) => $c->getDeletedAt() === null
            );

            if ($companies->count() !== 1) {
                continue;
            }

            $firstCompany = $companies->first();
            if ($firstCompany->getCreatedAt() === null || $firstCompany->getCreatedAt() < $window) {
                continue;
            }

            if ($dryRun) {
                $io->text(sprintf(
                    '[DRY RUN] Would dispatch first-company-created email for org %s',
                    (string) $org->getId(),
                ));
            } else {
                $this->bus->dispatch(new SendFirstCompanyCreatedEmailMessage((string) $org->getId()));
                $settings[self::SETTING_KEY] = (new \DateTimeImmutable())->format('Y-m-d');
                $org->setSettings($settings);
                $this->entityManager->flush();
                $io->text(sprintf('Dispatched first-company-created email for org %s', (string) $org->getId()));
            }

            $dispatched++;
        }

        if ($dryRun) {
            $io->note(sprintf('Dry run: %d first-company-created emails would be dispatched.', $dispatched));
        } else {
            $io->success(sprintf('Dispatched %d first-company-created email(s).', $dispatched));
        }

        return Command::SUCCESS;
    }
}
