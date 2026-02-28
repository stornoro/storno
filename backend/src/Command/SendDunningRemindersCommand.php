<?php

namespace App\Command;

use App\Entity\Organization;
use App\Message\SendDunningEmailMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Dispatches dunning reminder emails for organizations with past-due subscriptions.
 *
 * Attempt 2 is sent at 3 days, attempt 3 at 7 days since the subscription became past_due.
 * Attempt 1 is dispatched immediately by the Stripe webhook handler on payment failure.
 *
 * Should be run daily via cron:
 *   0 9 * * * php bin/console app:billing:dunning-reminders
 */
#[AsCommand(
    name: 'app:billing:dunning-reminders',
    description: 'Send dunning reminder emails for past-due subscriptions (attempt 2 at 3d, attempt 3 at 7d)',
)]
class SendDunningRemindersCommand extends Command
{
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

        // Query all past_due organizations that have a settings-tracked payment failure date.
        // We use the settings JSON field to store the date the subscription became past_due
        // and to track which attempts have already been sent, avoiding duplicates.
        $organizations = $this->entityManager->getRepository(Organization::class)->findBy([
            'subscriptionStatus' => 'past_due',
        ]);

        foreach ($organizations as $org) {
            $settings = $org->getSettings();
            $failedAt = isset($settings['dunning_failed_at'])
                ? new \DateTimeImmutable($settings['dunning_failed_at'])
                : null;

            if (!$failedAt) {
                // First time we see this org as past_due: record the failure date now.
                // Attempt 1 was already sent by the webhook. Record today as the baseline.
                $settings['dunning_failed_at'] = $now->format('Y-m-d\TH:i:sP');
                $settings['dunning_attempt_1_sent'] = true;
                $org->setSettings($settings);
                $this->entityManager->flush();
                continue;
            }

            $daysSinceFailure = (int) $now->diff($failedAt)->days;

            // Attempt 2: send between day 3 and day 6 (inclusive), once
            if ($daysSinceFailure >= 3 && $daysSinceFailure < 7 && empty($settings['dunning_attempt_2_sent'])) {
                if ($dryRun) {
                    $io->text(sprintf('[DRY RUN] Would dispatch attempt 2 for org %s (day %d)', (string) $org->getId(), $daysSinceFailure));
                } else {
                    $this->bus->dispatch(new SendDunningEmailMessage((string) $org->getId(), 2));
                    $settings['dunning_attempt_2_sent'] = true;
                    $org->setSettings($settings);
                    $this->entityManager->flush();
                    $io->text(sprintf('Dispatched attempt 2 for org %s (day %d since failure)', (string) $org->getId(), $daysSinceFailure));
                }
                $dispatched++;
            }

            // Attempt 3: send on day 7+, once
            if ($daysSinceFailure >= 7 && empty($settings['dunning_attempt_3_sent'])) {
                if ($dryRun) {
                    $io->text(sprintf('[DRY RUN] Would dispatch attempt 3 for org %s (day %d)', (string) $org->getId(), $daysSinceFailure));
                } else {
                    $this->bus->dispatch(new SendDunningEmailMessage((string) $org->getId(), 3));
                    $settings['dunning_attempt_3_sent'] = true;
                    $org->setSettings($settings);
                    $this->entityManager->flush();
                    $io->text(sprintf('Dispatched attempt 3 for org %s (day %d since failure)', (string) $org->getId(), $daysSinceFailure));
                }
                $dispatched++;
            }
        }

        if ($dryRun) {
            $io->note(sprintf('Dry run: %d dunning messages would be dispatched.', $dispatched));
        } else {
            $io->success(sprintf('Dispatched %d dunning reminder(s).', $dispatched));
        }

        return Command::SUCCESS;
    }
}
