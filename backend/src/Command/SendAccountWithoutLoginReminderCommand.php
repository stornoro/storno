<?php

namespace App\Command;

use App\Entity\OrganizationMembership;
use App\Entity\User;
use App\Enum\OrganizationRole;
use App\Message\SendAccountWithoutLoginReminderMessage;
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
    name: 'app:lifecycle:account-without-login-reminder',
    description: 'Send a reminder to users who verified their email but never logged in (3+ days old)',
)]
class SendAccountWithoutLoginReminderCommand extends Command
{
    private const SETTING_KEY = 'account_without_login_sent';
    private const DAYS_THRESHOLD = 3;

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
        $cutoff = new \DateTimeImmutable(sprintf('-%d days', self::DAYS_THRESHOLD));
        $dispatched = 0;

        $users = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where('u.emailVerified = true')
            ->andWhere('u.lastConnectedAt IS NULL')
            ->andWhere('u.createdAt < :cutoff')
            ->andWhere('u.deletedAt IS NULL')
            ->andWhere('u.active = true')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getResult();

        foreach ($users as $user) {
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
            $sentKey = sprintf('%s_%s', self::SETTING_KEY, (string) $user->getId());

            if (!empty($settings[$sentKey])) {
                continue;
            }

            if ($dryRun) {
                $io->text(sprintf(
                    '[DRY RUN] Would dispatch account-without-login reminder for user %s (created: %s)',
                    $user->getEmail(),
                    $user->getCreatedAt()?->format('Y-m-d') ?? 'n/a',
                ));
            } else {
                $this->bus->dispatch(new SendAccountWithoutLoginReminderMessage((string) $user->getId()));
                $settings[$sentKey] = (new \DateTimeImmutable())->format('Y-m-d');
                $org->setSettings($settings);
                $this->entityManager->flush();
                $io->text(sprintf('Dispatched account-without-login reminder for user %s', $user->getEmail()));
            }

            $dispatched++;
        }

        if ($dryRun) {
            $io->note(sprintf('Dry run: %d account-without-login reminders would be dispatched.', $dispatched));
        } else {
            $io->success(sprintf('Dispatched %d account-without-login reminder(s).', $dispatched));
        }

        return Command::SUCCESS;
    }
}
