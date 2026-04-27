<?php

namespace App\Command\Notification;

use App\Entity\Invoice;
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
    name: 'app:notifications:due-invoices',
    description: 'Send reminders for invoices that are due soon, due today, or overdue',
)]
class SendDueInvoiceRemindersCommand extends Command
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

        // Due in 3 days
        $dueSoon = $this->invoiceRepository->findDueInDays(3);
        $sent += $this->processInvoices($io, $dueSoon, 'invoice.due_soon', 'notification.invoice_due_soon', $dryRun, $today);

        // Due today
        $dueToday = $this->invoiceRepository->findDueInDays(0);
        $sent += $this->processInvoices($io, $dueToday, 'invoice.due_today', 'notification.invoice_due_today', $dryRun, $today);

        // Overdue by 1 day
        $overdue = $this->invoiceRepository->findOverdueByDays(1);
        $sent += $this->processInvoices($io, $overdue, 'invoice.overdue', 'notification.invoice_overdue', $dryRun, $today);

        if ($dryRun) {
            $io->note(sprintf('Dry run: %d notifications would be sent.', $sent));
        } else {
            $io->success(sprintf('Sent %d notifications.', $sent));
        }

        return Command::SUCCESS;
    }

    /**
     * @param Invoice[] $invoices
     */
    private function processInvoices(
        SymfonyStyle $io,
        array $invoices,
        string $type,
        string $translationKey,
        bool $dryRun,
        \DateTime $today,
    ): int {
        $sent = 0;

        foreach ($invoices as $invoice) {
            $company = $invoice->getCompany();
            $users = $this->membershipRepository->findActiveUsersByCompany($company);

            foreach ($users as $user) {
                // Dedup: check if we already sent this notification type for this invoice today
                $existing = $this->notificationRepository->createQueryBuilder('n')
                    ->select('COUNT(n.id)')
                    ->where('n.user = :user')
                    ->andWhere('n.type = :type')
                    ->andWhere('n.sentAt >= :todayStart')
                    ->andWhere('n.sentAt < :todayEnd')
                    ->andWhere('n.data LIKE :invoiceId')
                    ->setParameter('user', $user)
                    ->setParameter('type', $type)
                    ->setParameter('todayStart', new \DateTimeImmutable($today->format('Y-m-d') . ' 00:00:00'))
                    ->setParameter('todayEnd', new \DateTimeImmutable($today->format('Y-m-d') . ' 23:59:59'))
                    ->setParameter('invoiceId', '%"invoiceId":"' . $invoice->getId()->toRfc4122() . '"%')
                    ->getQuery()
                    ->getSingleScalarResult();

                if ((int) $existing > 0) {
                    continue;
                }

                $locale = $user->getLocale() ?? 'ro';
                $companyName = $company->getName() ?? '—';
                $titleParams = ['%company%' => $companyName];
                $params = [
                    '%company%' => $companyName,
                    '%number%' => $invoice->getNumber(),
                    '%date%' => $invoice->getDueDate()->format('d.m.Y'),
                ];

                $title = $this->translator->trans($translationKey . '.title', $titleParams, 'notifications', $locale);
                $message = $this->translator->trans($translationKey . '.message', $params, 'notifications', $locale);

                $clientName = $invoice->getClient()?->getName() ?? $invoice->getReceiverName() ?? '';
                if ($clientName) {
                    $message .= ' - ' . $clientName;
                }

                if ($dryRun) {
                    $io->text(sprintf('  [DRY RUN] %s → %s [%s]: %s', $type, $user->getEmail(), $locale, $message));
                } else {
                    $this->notificationService->createNotification(
                        $user,
                        $type,
                        $title,
                        $message,
                        [
                            'invoiceId' => $invoice->getId()->toRfc4122(),
                            'invoiceNumber' => $invoice->getNumber(),
                            'companyId' => $company->getId()->toRfc4122(),
                            'companyName' => $companyName,
                            'titleKey' => $translationKey . '.title',
                            'titleParams' => ['company' => $companyName],
                            'messageKey' => $translationKey . '.message',
                            'messageParams' => [
                                'company' => $companyName,
                                'number' => $invoice->getNumber(),
                                'date' => $invoice->getDueDate()->format('d.m.Y'),
                            ],
                        ],
                    );
                }

                $sent++;
            }
        }

        return $sent;
    }
}
