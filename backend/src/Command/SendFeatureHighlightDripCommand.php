<?php

namespace App\Command;

use App\Entity\Organization;
use App\Message\SendFeatureHighlightDripMessage;
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
    name: 'app:lifecycle:feature-highlight-drip',
    description: 'Send feature highlight drip emails to trial-active orgs at days 3, 7, 10, and 14 of trial',
)]
class SendFeatureHighlightDripCommand extends Command
{
    private const DRIP_SCHEDULE = [
        3  => 'efactura',
        7  => 'anaf_lookup',
        10 => 'contabil_user',
        14 => 'mobile_app',
    ];

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
        $now = new \DateTimeImmutable();
        $dispatched = 0;

        $orgs = $this->entityManager->createQueryBuilder()
            ->select('o')
            ->from(Organization::class, 'o')
            ->where('o.trialEndsAt IS NOT NULL')
            ->andWhere('o.trialEndsAt > :now')
            ->andWhere('o.deletedAt IS NULL')
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();

        foreach ($orgs as $org) {
            $settings = $org->getSettings();
            $settingsChanged = false;

            $trialEndsAt = $org->getTrialEndsAt();
            $trialDuration = 14;
            $trialStartedAt = $trialEndsAt->modify(sprintf('-%d days', $trialDuration));
            $daysSinceStart = (int) $now->diff($trialStartedAt)->days;

            foreach (self::DRIP_SCHEDULE as $day => $feature) {
                $settingKey = sprintf('drip_%s_sent', $feature);

                if (!empty($settings[$settingKey])) {
                    continue;
                }

                if ($daysSinceStart < $day) {
                    continue;
                }

                if ($daysSinceStart >= $day && $daysSinceStart < $day + 2) {
                    if ($dryRun) {
                        $io->text(sprintf(
                            '[DRY RUN] Would dispatch feature-drip/%s (day %d) for org %s',
                            $feature,
                            $day,
                            (string) $org->getId(),
                        ));
                    } else {
                        $this->bus->dispatch(new SendFeatureHighlightDripMessage((string) $org->getId(), $feature, $day));
                        $settings[$settingKey] = (new \DateTimeImmutable())->format('Y-m-d');
                        $settingsChanged = true;
                        $io->text(sprintf(
                            'Dispatched feature-drip/%s (day %d) for org %s',
                            $feature,
                            $day,
                            (string) $org->getId(),
                        ));
                    }

                    $dispatched++;
                }
            }

            if ($settingsChanged) {
                $org->setSettings($settings);
                $this->entityManager->flush();
            }
        }

        if ($dryRun) {
            $io->note(sprintf('Dry run: %d feature drip emails would be dispatched.', $dispatched));
        } else {
            $io->success(sprintf('Dispatched %d feature drip email(s).', $dispatched));
        }

        return Command::SUCCESS;
    }
}
