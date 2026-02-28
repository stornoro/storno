<?php

namespace App\Command;

use App\Entity\Organization;
use App\Entity\OrganizationMembership;
use App\Entity\User;
use App\Enum\OrganizationRole;
use App\Message\SendReEngagementEmailMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Dispatches re-engagement emails to users who haven't logged in for 14+ days.
 *
 * Tracks sent emails in the organization settings JSON field to send at most once per user.
 * Only targets organization owners to avoid spamming team members.
 *
 * Should be run weekly via cron:
 *   0 10 * * 1 php bin/console app:lifecycle:re-engagement-emails
 */
#[AsCommand(
    name: 'app:lifecycle:re-engagement-emails',
    description: 'Send re-engagement emails to users inactive for 14+ days',
)]
class SendReEngagementEmailsCommand extends Command
{
    private const INACTIVE_DAYS_THRESHOLD = 14;
    private const SETTING_KEY = 're_engagement_sent';

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
        $cutoff = new \DateTimeImmutable(sprintf('-%d days', self::INACTIVE_DAYS_THRESHOLD));
        $dispatched = 0;

        // Find active users who haven't logged in since the cutoff date
        // and whose lastConnectedAt is not null (i.e. have logged in at least once)
        $users = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where('u.active = true')
            ->andWhere('u.lastConnectedAt IS NOT NULL')
            ->andWhere('u.lastConnectedAt < :cutoff')
            ->andWhere('u.deletedAt IS NULL')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getResult();

        foreach ($users as $user) {
            // Only target organization owners to avoid overwhelming team members
            $ownerMembership = $this->entityManager->getRepository(OrganizationMembership::class)->findOneBy([
                'user' => $user,
                'role' => OrganizationRole::OWNER,
                'isActive' => true,
            ]);

            if (!$ownerMembership) {
                continue;
            }

            $org = $ownerMembership->getOrganization();
            $settings = $org->getSettings();

            // Check if re-engagement email was already sent for this user
            $sentKey = sprintf('%s_%s', self::SETTING_KEY, (string) $user->getId());
            if (!empty($settings[$sentKey])) {
                continue;
            }

            if ($dryRun) {
                $io->text(sprintf(
                    '[DRY RUN] Would dispatch re-engagement email for user %s (last login: %s)',
                    $user->getEmail(),
                    $user->getLastConnectedAt()->format('Y-m-d'),
                ));
            } else {
                $this->bus->dispatch(new SendReEngagementEmailMessage((string) $user->getId()));
                $settings[$sentKey] = (new \DateTimeImmutable())->format('Y-m-d');
                $org->setSettings($settings);
                $this->entityManager->flush();
                $io->text(sprintf('Dispatched re-engagement email for user %s', $user->getEmail()));
            }

            $dispatched++;
        }

        if ($dryRun) {
            $io->note(sprintf('Dry run: %d re-engagement emails would be dispatched.', $dispatched));
        } else {
            $io->success(sprintf('Dispatched %d re-engagement email(s).', $dispatched));
        }

        return Command::SUCCESS;
    }
}
