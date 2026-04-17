<?php

namespace App\Command\Notification;

use App\Entity\Invoice;
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
    name: 'app:notifications:anaf-deadline',
    description: 'Warn about invoices approaching the 5-day ANAF submission deadline',
)]
class AnafDeadlineWarningCommand extends Command
{
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
        $today = new \DateTime('today');
        $sent = 0;

        $invoices = $this->invoiceRepository->findApproachingAnafDeadline(4);

        foreach ($invoices as $invoice) {
            $company = $invoice->getCompany();
            $users = $this->membershipRepository->findActiveUsersByCompany($company);

            foreach ($users as $user) {
                $existing = $this->notificationRepository->createQueryBuilder('n')
                    ->select('COUNT(n.id)')
                    ->where('n.user = :user')
                    ->andWhere('n.type = :type')
                    ->andWhere('n.sentAt >= :todayStart')
                    ->andWhere('n.sentAt < :todayEnd')
                    ->andWhere('n.data LIKE :invoiceId')
                    ->setParameter('user', $user)
                    ->setParameter('type', 'invoice.anaf_deadline')
                    ->setParameter('todayStart', new \DateTimeImmutable($today->format('Y-m-d') . ' 00:00:00'))
                    ->setParameter('todayEnd', new \DateTimeImmutable($today->format('Y-m-d') . ' 23:59:59'))
                    ->setParameter('invoiceId', '%"invoiceId":"' . $invoice->getId()->toRfc4122() . '"%')
                    ->getQuery()
                    ->getSingleScalarResult();

                if ((int) $existing > 0) {
                    continue;
                }

                $locale = $user->getLocale() ?? 'ro';
                $params = [
                    '%number%' => $invoice->getNumber(),
                    '%date%' => $invoice->getIssueDate()->format('d.m.Y'),
                ];

                $title = $this->translator->trans('notification.anaf_deadline.title', [], 'notifications', $locale);
                $message = $this->translator->trans('notification.anaf_deadline.message', $params, 'notifications', $locale);

                if ($dryRun) {
                    $io->text(sprintf('  [DRY RUN] → %s [%s]: %s', $user->getEmail(), $locale, $message));
                } else {
                    $this->notificationService->createNotification(
                        $user,
                        'invoice.anaf_deadline',
                        $title,
                        $message,
                        [
                            'invoiceId' => $invoice->getId()->toRfc4122(),
                            'invoiceNumber' => $invoice->getNumber(),
                            'companyId' => $company->getId()->toRfc4122(),
                            'titleKey' => MessageKey::TITLE_ANAF_DEADLINE,
                            'messageKey' => MessageKey::MSG_ANAF_DEADLINE,
                            'messageParams' => [
                                'number' => $invoice->getNumber(),
                                'date' => $invoice->getIssueDate()->format('d.m.Y'),
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
            $io->success(sprintf('Sent %d ANAF deadline warnings.', $sent));
        }

        return Command::SUCCESS;
    }
}
