<?php

declare(strict_types=1);

namespace App\Command\Declaration;

use App\Entity\Company;
use App\Enum\DeclarationType;
use App\Manager\TaxDeclarationManager;
use App\Repository\OrganizationMembershipRepository;
use App\Repository\TaxDeclarationRepository;
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
 * Daily: once a quarter has closed, prepare the special VAT return (D398, OSS — the
 * Union scheme) as a draft for every company that has the OSS scheme switched on and
 * has no D398 for that quarter yet, then tell the company's users what it says: the
 * VAT due in euro, the payment reference and the due date.
 *
 * Nothing is filed: the draft waits for the accountant (or the user) to review it,
 * validate it and file it — by hand on the ANAF portal or through the Storno agent.
 * The draft is created on the day after the quarter ends, when the ECB rate of the
 * last day of the quarter is already published; the window stays open until the
 * filing deadline (the end of the next month), so a company switched to OSS mid-month
 * still gets its draft. With `--period 2026-Q3` a given quarter is prepared instead.
 */
#[AsCommand(name: 'app:declarations:oss-draft', description: 'Prepare the quarterly OSS VAT return (D398) as a draft for companies under the OSS scheme and notify their users of the amount due')]
final class DraftOssReturnCommand extends Command
{
    public const NOTIFICATION_TYPE = 'declaration.draft_ready';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TaxDeclarationRepository $declarations,
        private readonly TaxDeclarationManager $manager,
        private readonly OrganizationMembershipRepository $memberships,
        private readonly NotificationService $notifications,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be created and sent')
            ->addOption('period', null, InputOption::VALUE_REQUIRED, 'Quarter to prepare, e.g. 2026-Q3 (default: the last closed quarter, while its filing window is open)')
            ->addOption('company', null, InputOption::VALUE_REQUIRED, 'Only this company (UUID)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $period = $input->getOption('period') !== null
            ? self::parsePeriod((string) $input->getOption('period'))
            : self::openQuarter(new \DateTimeImmutable('today'));
        if ($period === null) {
            $io->text('No quarter with an open filing window; nothing to do.');
            return Command::SUCCESS;
        }
        [$year, $quarter] = $period;
        $month = $quarter * 3;
        $dueDate = (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->modify('last day of next month');
        $io->text(sprintf('Quarter %d/%d — filing due %s', $quarter, $year, $dueDate->format('d.m.Y')));

        $criteria = ['oss' => true, 'deletedAt' => null];
        if ($input->getOption('company')) {
            $criteria['id'] = $input->getOption('company');
        }
        /** @var Company[] $companies */
        $companies = $this->em->getRepository(Company::class)->findBy($criteria);

        $created = 0;
        foreach ($companies as $company) {
            if ($this->declarations->findByPeriod($company, DeclarationType::D398, $year, $month) !== []) {
                continue; // already prepared, filed or recorded for this quarter
            }
            $users = $this->memberships->findActiveUsersByCompany($company);
            if ($users === []) {
                $io->text(sprintf('  %s: no active user, skipped', $company->getName() ?? $company->getId()));
                continue;
            }
            if ($dryRun) {
                $io->text(sprintf('  [DRY RUN] would prepare D398 %d/%d for %s and notify %d user(s)', $quarter, $year, $company->getName() ?? $company->getId(), count($users)));
                $created++;
                continue;
            }

            $declaration = $this->manager->create($company, [
                'type' => DeclarationType::D398->value,
                'year' => $year,
                'month' => $month,
                'periodType' => 'quarterly',
            ], $users[0]);
            $created++;

            $data = $declaration->getData() ?? [];
            $vatDue = (string) ($data['totals']['vatDue'] ?? '0.00');
            $nil = ($data['rows']['nil_vat_return'] ?? '0') === '1';
            $reference = sprintf('RO/%s/Q%d.%04d', $data['rows']['vat_id_no'] ?? ('RO' . $company->getCif()), $quarter, $year);
            $id = $declaration->getId()?->toRfc4122();

            foreach ($users as $user) {
                $locale = $user->getLocale() ?? 'ro';
                $params = [
                    '%company%' => $company->getName() ?? '—',
                    '%quarter%' => sprintf('%d/%d', $quarter, $year),
                    '%amount%' => number_format((float) $vatDue, 2, ',', '.'),
                    '%date%' => $dueDate->format('d.m.Y'),
                    '%reference%' => $reference,
                ];
                $key = $nil ? 'notification.declaration.oss_draft_nil' : 'notification.declaration.oss_draft';
                $title = $this->translator->trans($key . '.title', $params, 'notifications', $locale);
                $message = $this->translator->trans($key . '.message', $params, 'notifications', $locale);
                $this->notifications->createNotification($user, self::NOTIFICATION_TYPE, $title, $message, [
                    'declarationId' => $id,
                    'declarationType' => 'd398',
                    'companyId' => $company->getId()?->toRfc4122(),
                    'companyName' => $company->getName(),
                    'period' => sprintf('%04d-Q%d', $year, $quarter),
                    'periodLabel' => sprintf('T%d %d', $quarter, $year),
                    'vatDue' => $vatDue,
                    'currency' => 'EUR',
                    'nil' => $nil,
                    'dueDate' => $dueDate->format('Y-m-d'),
                    'dueDateLabel' => $dueDate->format('d.m.Y'),
                    'reference' => $reference,
                    'url' => '/declarations/' . $id,
                ]);
            }
            $io->text(sprintf('  %s: D398 %d/%d prepared — %s EUR due by %s (%d user(s) notified)', $company->getName() ?? $company->getId(), $quarter, $year, $vatDue, $dueDate->format('d.m.Y'), count($users)));
        }

        $io->success(sprintf('%d OSS return(s) prepared for %d OSS compan%s.', $created, count($companies), count($companies) === 1 ? 'y' : 'ies'));

        return Command::SUCCESS;
    }

    /**
     * The last closed quarter while its filing window is open: from the first day after the
     * quarter to the end of the following month (the D398 deadline). Null outside that window.
     *
     * @return array{int, int}|null [year, quarter]
     */
    public static function openQuarter(\DateTimeImmutable $today): ?array
    {
        $month = (int) $today->format('n');
        $year = (int) $today->format('Y');
        // The quarter that ended most recently
        $quarter = intdiv($month - 1, 3); // 0 → last quarter of the previous year
        if ($quarter === 0) {
            $quarter = 4;
            $year--;
        }
        $quarterEnd = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $quarter * 3));
        $deadline = $quarterEnd->modify('last day of next month');
        if ($today > $deadline) {
            return null; // the window closed; the next quarter is not over yet
        }

        return [$year, $quarter];
    }

    /** @return array{int, int}|null */
    public static function parsePeriod(string $period): ?array
    {
        if (!preg_match('/^(\d{4})-?Q([1-4])$/i', trim($period), $m)) {
            throw new \InvalidArgumentException(sprintf('Period "%s" is not of the form YYYY-Qn.', $period));
        }

        return [(int) $m[1], (int) $m[2]];
    }
}
