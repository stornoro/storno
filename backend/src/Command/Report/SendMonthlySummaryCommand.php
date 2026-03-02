<?php

namespace App\Command\Report;

use App\Entity\Company;
use App\Entity\EmailLog;
use App\Entity\User;
use App\Enum\EmailStatus;
use App\Repository\CompanyRepository;
use App\Repository\EmailLogRepository;
use App\Repository\OrganizationMembershipRepository;
use App\Service\EmailUnsubscribeService;
use App\Service\MonthlySummaryService;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Twig\Environment;

#[AsCommand(
    name: 'app:report:monthly-summary',
    description: 'Send monthly summary emails to all active users',
)]
class SendMonthlySummaryCommand extends Command
{
    private const ROMANIAN_MONTHS = [
        1 => 'Ianuarie', 2 => 'Februarie', 3 => 'Martie', 4 => 'Aprilie',
        5 => 'Mai', 6 => 'Iunie', 7 => 'Iulie', 8 => 'August',
        9 => 'Septembrie', 10 => 'Octombrie', 11 => 'Noiembrie', 12 => 'Decembrie',
    ];

    public function __construct(
        private readonly MonthlySummaryService $summaryService,
        private readonly OrganizationMembershipRepository $membershipRepository,
        private readonly CompanyRepository $companyRepository,
        private readonly EmailLogRepository $emailLogRepository,
        private readonly NotificationService $notificationService,
        private readonly EmailUnsubscribeService $emailUnsubscribeService,
        private readonly EntityManagerInterface $entityManager,
        private readonly Environment $twig,
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        private readonly string $mailFrom,
        private readonly string $frontendUrl,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only show what would be sent without sending')
            ->addOption('month', null, InputOption::VALUE_REQUIRED, 'Target month in YYYY-MM format (defaults to previous month)')
            ->addOption('company', null, InputOption::VALUE_REQUIRED, 'Only send for a specific company UUID')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'Only send to a specific user email');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');

        // Determine target month
        $monthStr = $input->getOption('month');
        if ($monthStr) {
            if (!preg_match('/^\d{4}-\d{2}$/', $monthStr)) {
                $io->error('Invalid month format. Use YYYY-MM.');
                return Command::FAILURE;
            }
            $monthStart = new \DateTimeImmutable($monthStr . '-01');
        } else {
            $monthStart = new \DateTimeImmutable('first day of last month');
        }
        $monthEnd = $monthStart->modify('last day of this month');
        $monthNumber = (int) $monthStart->format('n');
        $monthYear = (int) $monthStart->format('Y');
        $monthLabel = self::ROMANIAN_MONTHS[$monthNumber] . ' ' . $monthYear;
        $dateRange = $monthStart->format('j') . ' ' . mb_substr(self::ROMANIAN_MONTHS[$monthNumber], 0, 3) . ' — ' . $monthEnd->format('j') . ' ' . mb_substr(self::ROMANIAN_MONTHS[$monthNumber], 0, 3);

        $io->title(sprintf('Monthly Summary — %s', $monthLabel));

        $filterCompanyId = $input->getOption('company');
        $filterUserEmail = $input->getOption('user');

        // Get all active memberships
        $memberships = $this->entityManager->createQuery(
            'SELECT om FROM App\Entity\OrganizationMembership om
             JOIN om.user u
             JOIN om.organization o
             WHERE om.isActive = true'
        )->getResult();

        $sent = 0;
        $skipped = 0;
        $processed = 0;

        foreach ($memberships as $membership) {
            $user = $membership->getUser();
            $organization = $membership->getOrganization();

            if ($filterUserEmail && $user->getEmail() !== $filterUserEmail) {
                continue;
            }

            // Get companies this user has access to
            $companies = $this->companyRepository->findByOrganizationAndMembership($organization, $membership);

            foreach ($companies as $company) {
                if ($filterCompanyId && $company->getId()->toRfc4122() !== $filterCompanyId) {
                    continue;
                }

                // Check email preference
                $preference = $this->notificationService->getUserPreference($user, 'report.monthly_summary');
                if (!$preference->isEmailEnabled()) {
                    $skipped++;
                    continue;
                }

                // Dedup via EmailLog
                $existing = $this->emailLogRepository->createQueryBuilder('el')
                    ->select('COUNT(el.id)')
                    ->where('el.category = :category')
                    ->andWhere('el.company = :company')
                    ->andWhere('el.toEmail = :email')
                    ->andWhere('el.subject LIKE :monthLabel')
                    ->setParameter('category', 'monthly_summary')
                    ->setParameter('company', $company)
                    ->setParameter('email', $user->getEmail())
                    ->setParameter('monthLabel', '%' . $monthLabel . '%')
                    ->getQuery()
                    ->getSingleScalarResult();

                if ((int) $existing > 0) {
                    $io->text(sprintf('  [SKIP] Already sent to %s for %s', $user->getEmail(), $company->getName()));
                    $skipped++;
                    continue;
                }

                // Get summary data
                $summary = $this->summaryService->getCompanySummary($company, $monthStart, $monthEnd);
                if ($summary === null) {
                    $io->text(sprintf('  [SKIP] No activity for %s (%s)', $company->getName(), $user->getEmail()));
                    $skipped++;
                    continue;
                }

                $userName = $user->getFirstName() ?: explode('@', $user->getEmail())[0];
                $companyName = $company->getName();
                $dashboardUrl = rtrim($this->frontendUrl, '/') . '/dashboard?company=' . $company->getId()->toRfc4122();
                $unsubscribeUrl = $this->emailUnsubscribeService->generateUrl(
                    $user->getEmail(),
                    'report.monthly_summary',
                    (string) $user->getId(),
                );

                if ($dryRun) {
                    $io->text(sprintf(
                        '  [DRY RUN] %s → %s (%s): %s invoiced, %s collected',
                        $companyName,
                        $user->getEmail(),
                        $monthLabel,
                        $summary['totalInvoicedFormatted'] . ' ' . $summary['currency'],
                        $summary['totalCollectedFormatted'] . ' ' . $summary['currency'],
                    ));
                    $sent++;
                    continue;
                }

                try {
                    $subject = sprintf('Raport lunar %s — %s', $monthLabel, $companyName);

                    $html = $this->twig->render('emails/monthly_summary.html.twig', [
                        'monthLabel' => $monthLabel,
                        'dateRange' => $dateRange,
                        'userName' => $userName,
                        'companyName' => $companyName,
                        'totalInvoicedFormatted' => $summary['totalInvoicedFormatted'],
                        'invoiceCount' => $summary['invoiceCount'],
                        'totalCollectedFormatted' => $summary['totalCollectedFormatted'],
                        'paymentCount' => $summary['paymentCount'],
                        'collectionRate' => $summary['collectionRate'],
                        'currency' => $summary['currency'],
                        'receivables' => $summary['receivables'],
                        'topClients' => $summary['topClients'],
                        'comparison' => $summary['comparison'],
                        'dashboardUrl' => $dashboardUrl,
                        'unsubscribeUrl' => $unsubscribeUrl,
                    ]);

                    $email = (new Email())
                        ->from(new Address($this->mailFrom, 'Storno.ro'))
                        ->to($user->getEmail())
                        ->subject($subject)
                        ->html($html);

                    $email->getHeaders()->addTextHeader('X-Storno-Email-Category', 'monthly_summary');
                    $email->getHeaders()->addTextHeader('List-Unsubscribe', sprintf('<%s>', $unsubscribeUrl));
                    $email->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

                    $this->mailer->send($email);

                    // Create EmailLog entry
                    $emailLog = new EmailLog();
                    $emailLog->setCompany($company);
                    $emailLog->setToEmail($user->getEmail());
                    $emailLog->setSubject($subject);
                    $emailLog->setCategory('monthly_summary');
                    $emailLog->setTemplateUsed('emails/monthly_summary.html.twig');
                    $emailLog->setFromEmail($this->mailFrom);
                    $emailLog->setFromName('Storno.ro');
                    $emailLog->setStatus(EmailStatus::SENT);

                    $this->entityManager->persist($emailLog);
                    $this->entityManager->flush();

                    $sent++;
                    $io->text(sprintf('  [SENT] %s → %s (%s)', $companyName, $user->getEmail(), $monthLabel));
                } catch (\Throwable $e) {
                    $this->logger->error('Failed to send monthly summary email.', [
                        'email' => $user->getEmail(),
                        'company' => $company->getName(),
                        'error' => $e->getMessage(),
                    ]);
                    $io->warning(sprintf('  [ERROR] %s → %s: %s', $companyName, $user->getEmail(), $e->getMessage()));
                }

                $processed++;
                if ($processed % 50 === 0) {
                    $this->entityManager->clear();
                }
            }
        }

        if ($dryRun) {
            $io->note(sprintf('Dry run: %d emails would be sent, %d skipped.', $sent, $skipped));
        } else {
            $io->success(sprintf('Sent %d emails, %d skipped.', $sent, $skipped));
        }

        return Command::SUCCESS;
    }
}
