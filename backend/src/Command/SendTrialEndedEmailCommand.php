<?php

namespace App\Command;

use App\Entity\Organization;
use App\Message\SendTrialEndedEmailMessage;
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
    name: 'app:lifecycle:trial-ended-email',
    description: 'Send post-trial emails at 1d, 7d, and 30d after trial end for orgs that did not subscribe',
)]
class SendTrialEndedEmailCommand extends Command
{
    private const VARIANTS = [
        '1d' => 1,
        '7d' => 7,
        '30d' => 30,
    ];

    private const ACTIVE_STATUSES = ['active', 'trialing', 'past_due'];

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
            ->andWhere('o.trialEndsAt < :now')
            ->andWhere('o.subscriptionStatus IS NULL OR o.subscriptionStatus NOT IN (:activeStatuses)')
            ->andWhere('o.deletedAt IS NULL')
            ->setParameter('now', $now)
            ->setParameter('activeStatuses', self::ACTIVE_STATUSES)
            ->getQuery()
            ->getResult();

        foreach ($orgs as $org) {
            $settings = $org->getSettings();
            $settingsChanged = false;
            $trialEndsAt = $org->getTrialEndsAt();
            $daysSinceEnd = (int) $now->diff($trialEndsAt)->days;

            foreach (self::VARIANTS as $variant => $days) {
                $settingKey = sprintf('trial_ended_%s_sent', $variant);

                if (!empty($settings[$settingKey])) {
                    continue;
                }

                if ($daysSinceEnd < $days) {
                    continue;
                }

                if ($daysSinceEnd >= $days && ($variant === '30d' || $daysSinceEnd < $days + 7)) {
                    if ($dryRun) {
                        $io->text(sprintf(
                            '[DRY RUN] Would dispatch trial-ended-%s email for org %s (day %d since end)',
                            $variant,
                            (string) $org->getId(),
                            $daysSinceEnd,
                        ));
                    } else {
                        $this->bus->dispatch(new SendTrialEndedEmailMessage((string) $org->getId(), $variant));
                        $settings[$settingKey] = (new \DateTimeImmutable())->format('Y-m-d');
                        $settingsChanged = true;
                        $io->text(sprintf(
                            'Dispatched trial-ended-%s email for org %s (day %d since end)',
                            $variant,
                            (string) $org->getId(),
                            $daysSinceEnd,
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
            $io->note(sprintf('Dry run: %d trial-ended emails would be dispatched.', $dispatched));
        } else {
            $io->success(sprintf('Dispatched %d trial-ended email(s).', $dispatched));
        }

        return Command::SUCCESS;
    }
}
