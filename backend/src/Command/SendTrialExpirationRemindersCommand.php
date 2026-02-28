<?php

namespace App\Command;

use App\Entity\Organization;
use App\Message\SendTrialExpirationMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Dispatches trial expiration warning emails to organization owners.
 *
 * Sends warnings at 7, 3, and 1 days before trial ends.
 * Tracks sent reminders in the organization settings JSON field to avoid duplicates.
 *
 * Should be run daily via cron:
 *   0 9 * * * php bin/console app:billing:trial-expiration-reminders
 */
#[AsCommand(
    name: 'app:billing:trial-expiration-reminders',
    description: 'Send trial expiration warning emails at 7, 3, and 1 days before trial ends',
)]
class SendTrialExpirationRemindersCommand extends Command
{
    private const REMINDER_THRESHOLDS = [7, 3, 1];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $bus,
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
        $dryRun = $input->getOption('dry-run');
        $now = new \DateTimeImmutable();
        $dispatched = 0;

        // Load all organizations with an active trial
        $organizations = $this->entityManager->createQueryBuilder()
            ->select('o')
            ->from(Organization::class, 'o')
            ->where('o.trialEndsAt IS NOT NULL')
            ->andWhere('o.trialEndsAt > :now')
            ->andWhere('o.subscriptionStatus IS NULL OR o.subscriptionStatus NOT IN (:activeStatuses)')
            ->setParameter('now', $now)
            ->setParameter('activeStatuses', ['active', 'trialing'])
            ->getQuery()
            ->getResult();

        foreach ($organizations as $org) {
            $trialEndsAt = $org->getTrialEndsAt();
            $daysLeft = (int) $now->diff($trialEndsAt)->days;

            $settings = $org->getSettings();
            $settingsChanged = false;

            foreach (self::REMINDER_THRESHOLDS as $threshold) {
                // Only send when daysLeft is within the threshold window (same day or slightly past)
                if ($daysLeft > $threshold) {
                    continue;
                }

                $settingKey = sprintf('trial_reminder_%dd_sent', $threshold);
                if (!empty($settings[$settingKey])) {
                    continue; // Already sent this reminder
                }

                if ($dryRun) {
                    $io->text(sprintf(
                        '[DRY RUN] Would dispatch trial expiration reminder (%dd) for org %s (expires %s)',
                        $threshold,
                        (string) $org->getId(),
                        $trialEndsAt->format('Y-m-d'),
                    ));
                } else {
                    $this->bus->dispatch(new SendTrialExpirationMessage((string) $org->getId(), $daysLeft));
                    $settings[$settingKey] = true;
                    $settingsChanged = true;
                    $io->text(sprintf(
                        'Dispatched trial reminder (%dd threshold, %d days left) for org %s',
                        $threshold,
                        $daysLeft,
                        (string) $org->getId(),
                    ));
                }

                $dispatched++;
                break; // Only one reminder per run per org (lowest applicable threshold)
            }

            if ($settingsChanged) {
                $org->setSettings($settings);
                $this->entityManager->flush();
            }
        }

        if ($dryRun) {
            $io->note(sprintf('Dry run: %d trial expiration reminders would be dispatched.', $dispatched));
        } else {
            $io->success(sprintf('Dispatched %d trial expiration reminder(s).', $dispatched));
        }

        return Command::SUCCESS;
    }
}
