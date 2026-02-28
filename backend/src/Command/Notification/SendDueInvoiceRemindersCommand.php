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
        $sent += $this->processInvoices($io, $dueSoon, 'invoice.due_soon', function (Invoice $inv) {
            return sprintf(
                'Factura %s scade în 3 zile (%s)',
                $inv->getNumber(),
                $inv->getDueDate()->format('d.m.Y'),
            );
        }, 'Factură scadentă în curând', $dryRun, $today);

        // Due today
        $dueToday = $this->invoiceRepository->findDueInDays(0);
        $sent += $this->processInvoices($io, $dueToday, 'invoice.due_today', function (Invoice $inv) {
            return sprintf('Factura %s scade astăzi', $inv->getNumber());
        }, 'Factură scadentă astăzi', $dryRun, $today);

        // Overdue by 1 day
        $overdue = $this->invoiceRepository->findOverdueByDays(1);
        $sent += $this->processInvoices($io, $overdue, 'invoice.overdue', function (Invoice $inv) {
            return sprintf(
                'Factura %s este scadentă din %s',
                $inv->getNumber(),
                $inv->getDueDate()->format('d.m.Y'),
            );
        }, 'Factură restantă', $dryRun, $today);

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
        callable $messageBuilder,
        string $title,
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
                    ->setParameter('user', $user)
                    ->setParameter('type', $type)
                    ->setParameter('todayStart', new \DateTimeImmutable($today->format('Y-m-d') . ' 00:00:00'))
                    ->setParameter('todayEnd', new \DateTimeImmutable($today->format('Y-m-d') . ' 23:59:59'))
                    ->getQuery()
                    ->getSingleScalarResult();

                // Additional check: same invoiceId in data
                if ($existing > 0) {
                    $existingWithInvoice = $this->notificationRepository->createQueryBuilder('n')
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

                    if ((int) $existingWithInvoice > 0) {
                        continue;
                    }
                }

                $message = $messageBuilder($invoice);
                $clientName = $invoice->getClient()?->getName() ?? $invoice->getReceiverName() ?? '';
                if ($clientName) {
                    $message .= ' - ' . $clientName;
                }

                if ($dryRun) {
                    $io->text(sprintf('  [DRY RUN] %s → %s: %s', $type, $user->getEmail(), $message));
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
                        ],
                    );
                }

                $sent++;
            }
        }

        return $sent;
    }
}
