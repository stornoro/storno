<?php

declare(strict_types=1);

namespace App\Command\Notification;

use App\Command\Support\CommandProgress;
use App\Repository\CompanyRepository;
use App\Repository\OrganizationMembershipRepository;
use App\Service\Balance\BalanceAnalysisService;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Daily: 21 and 7 days before a quarter closes, tell the users of every company that
 * issued invoices in the quarter what the profit tax estimated from those invoices is,
 * how much equipment put into service before the quarter ends would be covered by the
 * reinvested-profit exemption, and how much zero-cost sponsorship is still available in
 * the year. Information only: the decision and the filing stay with the accountant.
 * Sent as the `report.monthly_summary` notification (in-app, email, Telegram, push per
 * the user's preference).
 */
#[AsCommand(name: 'app:notifications:quarter-planning', description: 'Remind users 21 and 7 days before a quarter closes of the estimated profit tax and what can still be done about it')]
final class QuarterPlanningReminderCommand extends Command
{
    private const DAYS_BEFORE = [21, 7];
    private const BATCH_SIZE = 200;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CompanyRepository $companyRepository,
        private readonly BalanceAnalysisService $balanceAnalysis,
        private readonly OrganizationMembershipRepository $memberships,
        private readonly NotificationService $notifications,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be sent')
            ->addOption('date', null, InputOption::VALUE_REQUIRED, 'Pretend today is this date (YYYY-MM-DD)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Send even when today is not one of the reminder days');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $today = new \DateTimeImmutable((string) ($input->getOption('date') ?: 'today'));

        $month = (int) $today->format('n');
        $quarter = intdiv($month - 1, 3) + 1;
        $year = (int) $today->format('Y');
        $quarterEnd = (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $quarter * 3)))->modify('last day of this month');
        $daysLeft = (int) $today->diff($quarterEnd)->format('%r%a');
        if (!in_array($daysLeft, self::DAYS_BEFORE, true) && !$input->getOption('force')) {
            $io->text(sprintf('%d days to the end of Q%d; reminders go out at %s days. Nothing to do.', $daysLeft, $quarter, implode(' and ', self::DAYS_BEFORE)));

            return Command::SUCCESS;
        }

        $sent = 0;
        // Natural persons do not pay profit tax: only VAT payers and legal entities are read, in
        // id-ordered batches so memory stays flat.
        $candidates = static fn ($qb) => $qb->andWhere("c.vatPayer = true OR c.type = 'company'");
        $total = (int) $candidates($this->companyRepository->createQueryBuilder('c')->select('COUNT(c.id)'))->getQuery()->getSingleScalarResult();
        $progress = new CommandProgress($output, 'quarter-planning', $total);
        $progress->start(sprintf('%d companies, Q%d %d, %d days to the quarter end', $total, $quarter, $year, $daysLeft));

        $afterId = null;
        do {
            $batch = $candidates($this->companyRepository->createBatchQueryBuilder($afterId, self::BATCH_SIZE))->getQuery()->getResult();
            if ($batch === []) {
                break;
            }
            $afterId = end($batch)->getId();

            foreach ($batch as $company) {
                $progress->advance();
                $estimate = $this->balanceAnalysis->profitTaxEstimate($company, $year);
                if (empty($estimate['hasData'])) {
                    continue;
                }
                $current = null;
                foreach ($estimate['quarters'] as $q) {
                    if ((int) $q['quarter'] === $quarter) {
                        $current = $q;
                    }
                }
                if ($current === null || (float) $current['revenue'] <= 0) {
                    continue;
                }
                $sponsorship = '0.00';
                $maxInvestment = '0.00';
                foreach ($estimate['tips'] as $tip) {
                    if ($tip['code'] === 'sponsorship') {
                        $sponsorship = $tip['params']['headroom'];
                    }
                    if ($tip['code'] === 'reinvested_profit') {
                        $maxInvestment = $tip['params']['maxInvestment'];
                    }
                }
                $fmt = static fn (string $v): string => number_format((float) $v, 0, ',', '.');
                $users = $this->memberships->findActiveUsersByCompany($company);
                foreach ($users as $user) {
                    $locale = $user->getLocale() ?? 'ro';
                    $params = [
                        '%company%' => $company->getName() ?? '—',
                        '%days%' => (string) $daysLeft,
                        '%quarter%' => sprintf('T%d %d', $quarter, $year),
                        '%tax%' => $fmt($current['estimatedProfitTax']),
                        '%date%' => $quarterEnd->format('d.m.Y'),
                        '%maxInvestment%' => $fmt($maxInvestment),
                        '%sponsorship%' => $fmt($sponsorship),
                        '%year%' => (string) $year,
                    ];
                    $title = $this->translator->trans('notification.report.quarter_planning.title', $params, 'notifications', $locale);
                    $message = $this->translator->trans('notification.report.quarter_planning.message', $params, 'notifications', $locale);
                    if ($dryRun) {
                        $io->text(sprintf('  [DRY RUN] → %s: %s — %s', $user->getEmail(), $title, $message));
                    } else {
                        $this->notifications->createNotification($user, 'report.monthly_summary', $title, $message, [
                            'companyId' => $company->getId()?->toRfc4122(),
                            'companyName' => $company->getName(),
                            'quarter' => sprintf('%04d-Q%d', $year, $quarter),
                            'daysLeft' => $daysLeft,
                            'estimatedProfitTax' => $current['estimatedProfitTax'],
                            'maxInvestment' => $maxInvestment,
                            'sponsorshipHeadroom' => $sponsorship,
                            'url' => '/reports/balances',
                        ]);
                    }
                    $sent++;
                }

            }
            $this->em->clear();
        } while (count($batch) === self::BATCH_SIZE);
        $progress->count('sent', $sent);
        $progress->finish();

        $io->success(sprintf('%d reminder(s) %s for Q%d %d (%d days left).', $sent, $dryRun ? 'would be sent' : 'sent', $quarter, $year, $daysLeft));

        return Command::SUCCESS;
    }
}
