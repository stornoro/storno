<?php

namespace App\Command\Notification;

use App\Enum\MessageKey;
use App\Repository\InvoiceRepository;
use App\Repository\NotificationRepository;
use App\Repository\OrganizationMembershipRepository;
use App\Service\NotificationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCommand(
    name: 'app:notifications:anaf-missing-token',
    description: 'Warn when invoices approach the ANAF submission deadline but no SPV token is configured',
)]
class AnafMissingTokenWarningCommand extends Command
{
    // ANAF requires submission within 5 calendar days of issue. Warn at day 3
    // so users have ~2 days to authorize SPV before the deadline passes.
    private const WARN_DAYS_AFTER_ISSUE = 3;
    private const ANAF_DEADLINE_DAYS = 5;

    public function __construct(
        private readonly InvoiceRepository $invoiceRepository,
        private readonly NotificationRepository $notificationRepository,
        private readonly OrganizationMembershipRepository $membershipRepository,
        private readonly NotificationService $notificationService,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only show what would be sent without sending');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');
        $today = new \DateTimeImmutable('today');
        $sent = 0;

        $invoices = $this->invoiceRepository->findApproachingDeadlineWithoutToken(self::WARN_DAYS_AFTER_ISSUE);

        foreach ($invoices as $invoice) {
            $company = $invoice->getCompany();
            $users = $this->membershipRepository->findActiveUsersByCompany($company);
            $deadline = (clone $invoice->getIssueDate())->modify('+' . self::ANAF_DEADLINE_DAYS . ' days');

            foreach ($users as $user) {
                $existing = (int) $this->notificationRepository->createQueryBuilder('n')
                    ->select('COUNT(n.id)')
                    ->where('n.user = :user')
                    ->andWhere('n.type = :type')
                    ->andWhere('n.sentAt >= :todayStart')
                    ->andWhere('n.sentAt < :todayEnd')
                    ->andWhere('n.data LIKE :invoiceId')
                    ->setParameter('user', $user)
                    ->setParameter('type', 'invoice.anaf_missing_token')
                    ->setParameter('todayStart', $today)
                    ->setParameter('todayEnd', $today->modify('+1 day'))
                    ->setParameter('invoiceId', '%"invoiceId":"' . $invoice->getId()->toRfc4122() . '"%')
                    ->getQuery()
                    ->getSingleScalarResult();

                if ($existing > 0) {
                    continue;
                }

                $locale = $user->getLocale() ?? 'ro';
                $companyName = $company->getName() ?? '—';
                $titleParams = ['%company%' => $companyName];
                $params = [
                    '%company%' => $companyName,
                    '%number%' => $invoice->getNumber(),
                    '%date%' => $invoice->getIssueDate()->format('d.m.Y'),
                    '%deadline%' => $deadline->format('d.m.Y'),
                ];

                $title = $this->translator->trans('notification.anaf_missing_token.title', $titleParams, 'notifications', $locale);
                $message = $this->translator->trans('notification.anaf_missing_token.message', $params, 'notifications', $locale);

                if ($dryRun) {
                    $io->text(sprintf('  [DRY RUN] → %s [%s]: %s', $user->getEmail(), $locale, $message));
                } else {
                    $this->notificationService->createNotification(
                        $user,
                        'invoice.anaf_missing_token',
                        $title,
                        $message,
                        [
                            'invoiceId' => $invoice->getId()->toRfc4122(),
                            'invoiceNumber' => $invoice->getNumber(),
                            'companyId' => $company->getId()->toRfc4122(),
                            'companyName' => $companyName,
                            'titleKey' => MessageKey::TITLE_ANAF_MISSING_TOKEN,
                            'titleParams' => ['company' => $companyName],
                            'messageKey' => MessageKey::MSG_ANAF_MISSING_TOKEN,
                            'messageParams' => [
                                'company' => $companyName,
                                'number' => $invoice->getNumber(),
                                'date' => $invoice->getIssueDate()->format('d.m.Y'),
                                'deadline' => $deadline->format('d.m.Y'),
                            ],
                        ],
                    );
                }

                $sent++;
            }
        }

        if ($dryRun) {
            $io->note(sprintf('Dry run: %d notifications would be sent.', $sent));
        } else {
            $io->success(sprintf('Sent %d ANAF missing-token warnings.', $sent));
        }

        return Command::SUCCESS;
    }
}
