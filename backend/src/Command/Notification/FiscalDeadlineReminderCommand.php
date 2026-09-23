<?php

namespace App\Command\Notification;

use App\Entity\Company;
use App\Entity\User;
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
 * Reminds each user, in one notification a day, of the declarations still to file across the
 * companies they belong to. The digest goes out on a day that is 7, 3 or 1 days before one of
 * those deadlines (weekend and holiday shifted) and lists every unfiled deadline of the next
 * 7 days, grouped by company. Companies without activity in the period of a deadline (no invoice
 * issued or received) are left out: they still owe the declaration, but are not reminded of it.
 */
#[AsCommand(
    name: 'app:notifications:fiscal-deadlines',
    description: 'Send each user one digest of the unfiled fiscal deadlines of the companies with activity, 7, 3 and 1 days ahead',
)]
class FiscalDeadlineReminderCommand extends Command
{
    public const TYPE = 'fiscal.deadline';
    public const REMIND_DAYS_BEFORE = [7, 3, 1];
    /** Every unfiled deadline this many days ahead is listed in the digest, not only the ones on a reminder day. */
    public const WINDOW_DAYS = 7;

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

        /** @var array<string, array{user: User, trigger: bool, companies: array<string, array{companyId: string, companyName: string, items: list<array<string, mixed>>}>}> $digests */
        $digests = [];
        $skippedCompanies = 0;

        foreach ($this->companyRepository->findAll() as $company) {
            if ($company->getDeletedAt() !== null || !$company->getOrganization()?->isActive()) {
                continue;
            }
            $items = $this->dueItems($company, $today);
            if ($items === []) {
                continue;
            }
            $active = array_values(array_filter($items, fn (array $item) => $this->calendar->hasActivity($company, $item)));
            if ($active === []) {
                $skippedCompanies++;
                continue;
            }
            $trigger = array_filter($active, static fn (array $item) => in_array($item['daysLeft'], self::REMIND_DAYS_BEFORE, true)) !== [];
            $companyId = $company->getId()->toRfc4122();
            $companyName = $company->getName() ?? '—';
            $entries = array_map(fn (array $item) => $this->entry($item), $active);

            foreach ($this->membershipRepository->findActiveUsersByCompany($company) as $user) {
                $userId = (string) $user->getId();
                $digests[$userId] ??= ['user' => $user, 'trigger' => false, 'companies' => []];
                $digests[$userId]['trigger'] = $digests[$userId]['trigger'] || $trigger;
                $digests[$userId]['companies'][$companyId] = ['companyId' => $companyId, 'companyName' => $companyName, 'items' => $entries];
            }
        }

        $sent = 0;
        $key = 'digest:' . $today->format('Y-m-d');
        foreach ($digests as $digest) {
            if (!$digest['trigger']) {
                continue;
            }
            $user = $digest['user'];
            if ($this->alreadySentToday($user, $key, $today)) {
                continue;
            }
            $sent++;
            $locale = $user->getLocale() ?? 'ro';
            $companies = array_values($digest['companies']);
            usort($companies, static fn (array $a, array $b) => strcasecmp($a['companyName'], $b['companyName']));
            $count = array_sum(array_map(static fn (array $c) => count($c['items']), $companies));
            $params = [
                'count' => $count,
                'companies' => count($companies),
                'summary' => $this->summary($companies, $locale),
            ];
            $transParams = [];
            foreach ($params as $name => $value) {
                $transParams['%' . $name . '%'] = $value;
            }
            $title = $this->translator->trans(MessageKey::TITLE_FISCAL_DEADLINE_DIGEST, $transParams, 'notifications', $locale);
            $message = $this->translator->trans(MessageKey::MSG_FISCAL_DEADLINE_DIGEST, $transParams, 'notifications', $locale);

            if ($dryRun) {
                $io->text(sprintf('  [DRY RUN] → %s [%s]: %s — %s', $user->getEmail(), $locale, $title, $message));
                continue;
            }
            $this->notificationService->createNotification($user, self::TYPE, $title, $message, [
                'key' => $key,
                'date' => $today->format('Y-m-d'),
                'count' => $count,
                'companyCount' => count($companies),
                'companies' => $companies,
                // the first company, so a client that opens one calendar has one to open
                'companyId' => $companies[0]['companyId'],
                'titleKey' => MessageKey::TITLE_FISCAL_DEADLINE_DIGEST,
                'titleParams' => $params,
                'messageKey' => MessageKey::MSG_FISCAL_DEADLINE_DIGEST,
                'messageParams' => $params,
            ]);
        }

        if ($dryRun) {
            $io->note(sprintf('Dry run: %d fiscal deadline digests would be sent (%d companies without activity skipped).', $sent, $skippedCompanies));
        } else {
            $io->success(sprintf('Sent %d fiscal deadline digests (%d companies without activity skipped).', $sent, $skippedCompanies));
        }

        return Command::SUCCESS;
    }

    /** @return list<array<string, mixed>> the unfiled deadlines of the next WINDOW_DAYS days */
    private function dueItems(Company $company, \DateTimeImmutable $today): array
    {
        return array_values(array_filter(
            $this->calendar->upcoming($company, $today, self::WINDOW_DAYS),
            // C168 deadlines of a dosar are reminded by app:dosare:remind (30/7/1/0 days); skip them here.
            static fn (array $item) => $item['status'] === FiscalCalendarService::STATUS_DUE
                && $item['daysLeft'] >= 0 && $item['daysLeft'] <= self::WINDOW_DAYS
                && !($item['code'] === 'C168' && isset($item['dosarId'])),
        ));
    }

    /** @param array<string, mixed> $item @return array<string, mixed> */
    private function entry(array $item): array
    {
        $dueDate = new \DateTimeImmutable($item['dueDate']);

        return [
            'code' => $item['code'],
            'label' => $item['label'],
            'period' => $item['period'],
            'periodLabel' => $this->periodLabel($item['period']),
            'dueDate' => $item['dueDate'],
            'dueDateLabel' => $dueDate->format('d.m.Y'),
            'daysLeft' => $item['daysLeft'],
            'declarationType' => $item['declarationType'],
        ];
    }

    /**
     * "Firma A: D300 (25.09.2026), D112 (25.09.2026) · Firma B: D300 (25.09.2026)"
     *
     * @param list<array{companyName: string, items: list<array<string, mixed>>}> $companies
     */
    private function summary(array $companies, string $locale): string
    {
        $parts = [];
        foreach ($companies as $company) {
            $codes = array_map(static fn (array $item) => sprintf('%s (%s)', $item['code'], $item['dueDateLabel']), $company['items']);
            $parts[] = sprintf('%s: %s', $company['companyName'], implode(', ', $codes));
        }

        return implode(' · ', $parts);
    }

    private function alreadySentToday(User $user, string $key, \DateTimeImmutable $today): bool
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
