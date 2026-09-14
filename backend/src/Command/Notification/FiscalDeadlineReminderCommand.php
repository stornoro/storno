<?php

namespace App\Command\Notification;

use App\Entity\Company;
use App\Enum\MessageKey;
use App\Repository\CompanyRepository;
use App\Repository\NotificationRepository;
use App\Repository\OrganizationMembershipRepository;
use App\Service\Calendar\FiscalCalendarService;
use App\Service\NotificationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Reminds every member of a company of the declarations still to file, 7, 3 and 1 days before
 * the deadline (weekend and holiday shifted). One notification per user, company, deadline and day.
 */
#[AsCommand(
    name: 'app:notifications:fiscal-deadlines',
    description: 'Remind company members of unfiled fiscal deadlines 7, 3 and 1 days ahead',
)]
class FiscalDeadlineReminderCommand extends Command
{
    public const TYPE = 'fiscal.deadline';
    public const REMIND_DAYS_BEFORE = [7, 3, 1];

    public function __construct(
        private readonly CompanyRepository $companyRepository,
        private readonly FiscalCalendarService $calendar,
        private readonly NotificationRepository $notificationRepository,
        private readonly OrganizationMembershipRepository $membershipRepository,
        private readonly NotificationService $notificationService,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only show what would be sent without sending')
            ->addOption('date', null, InputOption::VALUE_REQUIRED, 'Run as if today were this date (YYYY-MM-DD)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $today = new \DateTimeImmutable(($input->getOption('date') ?: 'today') . ' 00:00:00');
        $sent = 0;

        foreach ($this->companyRepository->findAll() as $company) {
            if ($company->getDeletedAt() !== null || !$company->getOrganization()?->isActive()) {
                continue;
            }
            $items = array_filter(
                $this->calendar->upcoming($company, $today, max(self::REMIND_DAYS_BEFORE)),
                static fn (array $item) => $item['status'] === FiscalCalendarService::STATUS_DUE && in_array($item['daysLeft'], self::REMIND_DAYS_BEFORE, true),
            );
            if ($items === []) {
                continue;
            }

            $users = $this->membershipRepository->findActiveUsersByCompany($company);
            foreach ($items as $item) {
                $key = sprintf('%s:%s:%s', $company->getId()->toRfc4122(), $item['code'], $item['dueDate']);
                foreach ($users as $user) {
                    if ($this->alreadySentToday($user, $key, $today)) {
                        continue;
                    }
                    $sent++;
                    $locale = $user->getLocale() ?? 'ro';
                    $companyName = $company->getName() ?? '—';
                    $dueDate = new \DateTimeImmutable($item['dueDate']);
                    $params = [
                        'company' => $companyName,
                        'code' => $item['code'],
                        'label' => $item['label'],
                        'period' => $this->periodLabel($item['period']),
                        'date' => $dueDate->format('d.m.Y'),
                        'count' => $item['daysLeft'],
                    ];
                    $transParams = [];
                    foreach ($params as $name => $value) {
                        $transParams['%' . $name . '%'] = $value;
                    }
                    $title = $this->translator->trans(MessageKey::TITLE_FISCAL_DEADLINE, $transParams, 'notifications', $locale);
                    $message = $this->translator->trans(MessageKey::MSG_FISCAL_DEADLINE, $transParams, 'notifications', $locale);

                    if ($dryRun) {
                        $io->text(sprintf('  [DRY RUN] → %s [%s]: %s', $user->getEmail(), $locale, $message));
                        continue;
                    }
                    $this->notificationService->createNotification($user, self::TYPE, $title, $message, [
                        'key' => $key,
                        'code' => $item['code'],
                        'dueDate' => $item['dueDate'],
                        'daysLeft' => $item['daysLeft'],
                        'declarationType' => $item['declarationType'],
                        'period' => $item['period'],
                        'companyId' => $company->getId()->toRfc4122(),
                        'companyName' => $companyName,
                        'titleKey' => MessageKey::TITLE_FISCAL_DEADLINE,
                        'titleParams' => $params,
                        'messageKey' => MessageKey::MSG_FISCAL_DEADLINE,
                        'messageParams' => $params,
                    ]);
                }
            }
        }

        if ($dryRun) {
            $io->note(sprintf('Dry run: %d fiscal deadline reminders would be sent.', $sent));
        } else {
            $io->success(sprintf('Sent %d fiscal deadline reminders.', $sent));
        }

        return Command::SUCCESS;
    }

    private function alreadySentToday(\App\Entity\User $user, string $key, \DateTimeImmutable $today): bool
    {
        $existing = $this->notificationRepository->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.user = :user')
            ->andWhere('n.type = :type')
            ->andWhere('n.sentAt >= :todayStart')
            ->andWhere('n.sentAt < :todayEnd')
            ->andWhere('n.data LIKE :key')
            ->setParameter('user', $user)
            ->setParameter('type', self::TYPE)
            ->setParameter('todayStart', $today)
            ->setParameter('todayEnd', $today->modify('+1 day'))
            ->setParameter('key', '%"key":"' . $key . '"%')
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $existing > 0;
    }

    /** @param array{year: int, month?: int, quarter?: int} $period */
    private function periodLabel(array $period): string
    {
        if (isset($period['month'])) {
            return sprintf('%02d.%04d', $period['month'], $period['year']);
        }
        if (isset($period['quarter'])) {
            return sprintf('T%d %04d', $period['quarter'], $period['year']);
        }

        return (string) $period['year'];
    }
}
