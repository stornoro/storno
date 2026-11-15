<?php

declare(strict_types=1);

namespace App\Command\Notification;

use App\Entity\Company;
use App\Entity\TaxDeclaration;
use App\Entity\User;
use App\Enum\DeclarationStatus;
use App\Enum\DeclarationType;
use App\Repository\NotificationRepository;
use App\Repository\OrganizationMembershipRepository;
use App\Repository\TaxDeclarationRepository;
use App\Service\Calendar\RomanianHolidays;
use App\Service\NotificationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A declaration that was filed is not a tax that was paid. The fiscal-deadline digest stops
 * the moment a declaration is filed, so this command takes over for the payment: for every
 * D100, D112, D300, D301 and D398 that is filed (submitted or accepted) and whose payment
 * falls due tomorrow or today, each user of the company gets one notification naming the
 * declaration, its period, the day it was filed, the due date and — when Storno computed
 * the return — the amount. Declarations ANAF synced carry no amount; the reminder still goes
 * out, because the missing step is the payment itself. A VAT return is reminded only when it
 * shows VAT to pay.
 */
#[AsCommand(
    name: 'app:notifications:payment-due',
    description: 'Remind users, the day before and on the due date, to pay the tax of a declaration that is already filed (D100, D112, D300, D301, D398)',
)]
final class DeclarationPaymentDueCommand extends Command
{
    public const TYPE = 'declaration.payment_due';
    /** Days before the due date on which the reminder goes out (0 = the due date itself). */
    public const REMIND_DAYS_BEFORE = [1, 0];

    private const TYPES = [DeclarationType::D100, DeclarationType::D112, DeclarationType::D300, DeclarationType::D301, DeclarationType::D398];
    private const FILED = [DeclarationStatus::SUBMITTED, DeclarationStatus::PROCESSING, DeclarationStatus::ACCEPTED];

    private const LABELS = [
        'd100' => ['ro' => 'Impozitul pe profit / obligatiile la bugetul de stat', 'en' => 'Profit tax / state budget obligations'],
        'd112' => ['ro' => 'Contributiile sociale si impozitul pe salarii', 'en' => 'Social contributions and payroll tax'],
        'd300' => ['ro' => 'TVA de plata', 'en' => 'VAT due'],
        'd301' => ['ro' => 'TVA de plata (decont special)', 'en' => 'VAT due (special return)'],
        'd398' => ['ro' => 'TVA OSS de plata', 'en' => 'OSS VAT due'],
    ];

    public function __construct(
        private readonly TaxDeclarationRepository $declarations,
        private readonly NotificationRepository $notificationRepository,
        private readonly OrganizationMembershipRepository $membershipRepository,
        private readonly NotificationService $notificationService,
        private readonly RomanianHolidays $holidays,
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
        $skipped = 0;
        foreach ($this->candidates($today) as $declaration) {
            $company = $declaration->getCompany();
            if ($company === null || $company->getDeletedAt() !== null || !$company->getOrganization()?->isActive()) {
                continue;
            }
            $due = $this->paymentDueDate($declaration);
            $daysLeft = (int) $today->diff($due)->format('%r%a');
            if (!in_array($daysLeft, self::REMIND_DAYS_BEFORE, true)) {
                continue;
            }
            $amount = $this->amountDue($declaration);
            if (in_array($declaration->getType(), [DeclarationType::D300, DeclarationType::D301, DeclarationType::D398], true) && ($amount === null || $amount['value'] <= 0)) {
                $skipped++;
                continue; // nothing to pay, or a refund position
            }

            $key = sprintf('payment_due:%s:%d', $declaration->getId(), $daysLeft);
            $companyId = $company->getId()->toRfc4122();
            $companyName = $company->getName() ?? '—';
            $code = strtoupper($declaration->getType()->value);
            $periodLabel = $this->periodLabel($declaration);
            $filedAt = $declaration->getAcceptedAt() ?? $declaration->getSubmittedAt() ?? $declaration->getCreatedAt();

            foreach ($this->membershipRepository->findActiveUsersByCompany($company) as $user) {
                if ($this->alreadySent($user, $key)) {
                    continue;
                }
                $locale = $user->getLocale() ?? 'ro';
                $lang = $locale === 'en' ? 'en' : 'ro';
                $amountText = $amount !== null && $amount['value'] > 0
                    ? sprintf(' %s %s %s', $lang === 'en' ? 'of' : 'de', number_format($amount['value'], 2, ',', '.'), $amount['currency'])
                    : '';
                $params = [
                    'company' => $companyName,
                    'code' => $code,
                    'label' => self::LABELS[$declaration->getType()->value][$lang],
                    'period' => $periodLabel,
                    'filed' => $filedAt?->format('d.m.Y') ?? '—',
                    'date' => $due->format('d.m.Y'),
                    'amount' => $amountText,
                ];
                $transParams = [];
                foreach ($params as $name => $value) {
                    $transParams['%' . $name . '%'] = $value;
                }
                $messageKey = $daysLeft === 0 ? 'notification.declaration.payment_due.message_today' : 'notification.declaration.payment_due.message_tomorrow';
                $title = $this->translator->trans('notification.declaration.payment_due.title', $transParams, 'notifications', $locale);
                $message = $this->translator->trans($messageKey, $transParams, 'notifications', $locale);

                if ($dryRun) {
                    $io->text(sprintf('  [DRY RUN] → %s [%s]: %s — %s', $user->getEmail(), $locale, $title, $message));
                    $sent++;
                    continue;
                }
                $this->notificationService->createNotification($user, self::TYPE, $title, $message, [
                    'key' => $key,
                    'declarationId' => (string) $declaration->getId(),
                    'declarationType' => $declaration->getType()->value,
                    'companyId' => $companyId,
                    'companyName' => $companyName,
                    'period' => sprintf('%04d-%02d', $declaration->getYear(), $declaration->getMonth()),
                    'periodLabel' => $periodLabel,
                    'filedAt' => $filedAt?->format('Y-m-d'),
                    'filedAtLabel' => $filedAt?->format('d.m.Y'),
                    'dueDate' => $due->format('Y-m-d'),
                    'dueDateLabel' => $due->format('d.m.Y'),
                    'daysLeft' => $daysLeft,
                    'amount' => $amount !== null ? number_format($amount['value'], 2, '.', '') : null,
                    'currency' => $amount['currency'] ?? 'RON',
                    'url' => '/declarations/' . $declaration->getId(),
                    'titleKey' => 'notification.declaration.payment_due.title',
                    'titleParams' => $params,
                    'messageKey' => $messageKey,
                    'messageParams' => $params,
                ]);
                $sent++;
            }
        }

        if ($dryRun) {
            $io->note(sprintf('Dry run: %d payment reminders would be sent (%d VAT returns without VAT to pay skipped).', $sent, $skipped));
        } else {
            $io->success(sprintf('Sent %d payment reminders (%d VAT returns without VAT to pay skipped).', $sent, $skipped));
        }

        return Command::SUCCESS;
    }

    /** Filed declarations of the reminded types whose period ended in the last four months. */
    private function candidates(\DateTimeImmutable $today): array
    {
        $from = $today->modify('first day of this month')->modify('-4 months');

        return $this->declarations->createQueryBuilder('d')
            ->join('d.company', 'c')
            ->where('d.type IN (:types)')
            ->andWhere('d.status IN (:statuses)')
            ->andWhere('(d.year > :fromYear) OR (d.year = :fromYear AND d.month >= :fromMonth)')
            ->setParameter('types', array_map(static fn (DeclarationType $t) => $t->value, self::TYPES))
            ->setParameter('statuses', array_map(static fn (DeclarationStatus $s) => $s->value, self::FILED))
            ->setParameter('fromYear', (int) $from->format('Y'))
            ->setParameter('fromMonth', (int) $from->format('n'))
            ->getQuery()
            ->getResult();
    }

    /**
     * When the tax of the declared period has to be paid: the 25th of the month after the period
     * (the last month of the quarter for a quarterly return), the end of the month after the
     * quarter for the OSS return — moved to the next working day when it falls on a weekend or a
     * legal holiday.
     */
    public function paymentDueDate(TaxDeclaration $declaration): \DateTimeImmutable
    {
        $periodEnd = new \DateTimeImmutable(sprintf('%04d-%02d-01', $declaration->getYear(), $declaration->getMonth()));
        $nominal = $declaration->getType() === DeclarationType::D398
            ? $periodEnd->modify('last day of next month')
            : $periodEnd->modify('first day of next month')->setDate((int) $periodEnd->modify('+1 month')->format('Y'), (int) $periodEnd->modify('+1 month')->format('n'), 25);

        return $this->holidays->nextWorkingDay($nominal);
    }

    /** @return array{value: float, currency: string}|null the amount the return itself shows as due, when Storno computed it */
    private function amountDue(TaxDeclaration $declaration): ?array
    {
        $totals = $declaration->getData()['totals'] ?? null;
        if (!is_array($totals)) {
            return null;
        }

        return match ($declaration->getType()) {
            DeclarationType::D300, DeclarationType::D301 => isset($totals['toPay']) ? ['value' => (float) $totals['toPay'], 'currency' => 'RON'] : null,
            DeclarationType::D398 => isset($totals['vatDue']) ? ['value' => (float) $totals['vatDue'], 'currency' => (string) ($totals['currency'] ?? 'EUR')] : null,
            default => isset($totals['toPay']) ? ['value' => (float) $totals['toPay'], 'currency' => 'RON'] : null,
        };
    }

    private function periodLabel(TaxDeclaration $declaration): string
    {
        $quarterly = $declaration->getPeriodType() === 'quarterly'
            || $declaration->getType() === DeclarationType::D398
            || ($declaration->getType() === DeclarationType::D100 && $declaration->getCompany()?->getIncomeTaxPeriod() === Company::PERIOD_QUARTERLY && $declaration->getMonth() % 3 === 0);
        if ($quarterly) {
            return sprintf('T%d %04d', intdiv($declaration->getMonth() - 1, 3) + 1, $declaration->getYear());
        }

        return sprintf('%02d.%04d', $declaration->getMonth(), $declaration->getYear());
    }

    private function alreadySent(User $user, string $key): bool
    {
        $existing = $this->notificationRepository->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.user = :user')
            ->andWhere('n.type = :type')
            ->andWhere('n.data LIKE :key')
            ->setParameter('user', $user)
            ->setParameter('type', self::TYPE)
            ->setParameter('key', '%"key":"' . $key . '"%')
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $existing > 0;
    }
}
