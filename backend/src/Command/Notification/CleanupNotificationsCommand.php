<?php

namespace App\Command\Notification;

use App\Repository\NotificationRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Trim the `notification` table so it doesn't grow unbounded.
 *
 * Defaults: drop read notifications after 30 days, drop anything after 90 days,
 * and keep at most 500 rows per user. Run daily from cron.
 */
#[AsCommand(
    name: 'app:notifications:cleanup',
    description: 'Delete old notifications and enforce a per-user retention cap',
)]
class CleanupNotificationsCommand extends Command
{
    public function __construct(
        private readonly NotificationRepository $notificationRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('read-max-age-days', null, InputOption::VALUE_REQUIRED, 'Delete read notifications older than this many days', 30)
            ->addOption('unread-max-age-days', null, InputOption::VALUE_REQUIRED, 'Delete any notification older than this many days', 90)
            ->addOption('max-per-user', null, InputOption::VALUE_REQUIRED, 'Hard cap on notifications kept per user', 500);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $readMax = max(1, (int) $input->getOption('read-max-age-days'));
        $unreadMax = max(1, (int) $input->getOption('unread-max-age-days'));
        $perUserMax = max(1, (int) $input->getOption('max-per-user'));

        $byAge = $this->notificationRepository->deleteOlderThan($readMax, $unreadMax);
        $byCap = $this->notificationRepository->deletePerUserOverflow($perUserMax);

        $io->success(sprintf(
            'Cleaned up notifications: %d removed by age (read>%dd or any>%dd), %d removed by per-user cap (>%d/user).',
            $byAge,
            $readMax,
            $unreadMax,
            $byCap,
            $perUserMax,
        ));

        return Command::SUCCESS;
    }
}
