<?php

namespace App\Command\Notification;

use App\Entity\ProformaInvoice;
use App\Manager\ProformaInvoiceManager;
use App\Repository\NotificationRepository;
use App\Repository\OrganizationMembershipRepository;
use App\Repository\ProformaInvoiceRepository;
use App\Service\NotificationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:proforma:process-expiry',
    description: 'Auto-expire proforma invoices past their validUntil date and send expiry notifications',
)]
class ProcessProformaExpiryCommand extends Command
{
    public function __construct(
        private readonly ProformaInvoiceRepository $proformaRepository,
        private readonly ProformaInvoiceManager $proformaManager,
        private readonly NotificationRepository $notificationRepository,
        private readonly OrganizationMembershipRepository $membershipRepository,
        private readonly NotificationService $notificationService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only show what would be done without making changes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');
        $today = new \DateTime('today');
        $expired = 0;
        $notified = 0;

        // 1. Notify expiring soon (3 days before)
        $expiringSoon = $this->proformaRepository->findExpiringSoon(3);
        $notified += $this->processProformas($io, $expiringSoon, 'proforma.expiring_soon', function (ProformaInvoice $p) {
            return sprintf(
                'Proforma %s expira in 3 zile (%s)',
                $p->getNumber(),
                $p->getValidUntil()->format('d.m.Y'),
            );
        }, 'Proforma expiră în curând', $dryRun, $today);

        // 2. Auto-expire proformas past validUntil
        $expiredProformas = $this->proformaRepository->findExpired();
        foreach ($expiredProformas as $proforma) {
            if ($dryRun) {
                $io->text(sprintf('  [DRY RUN] Would expire proforma %s (valid until %s)',
                    $proforma->getNumber(),
                    $proforma->getValidUntil()->format('d.m.Y'),
                ));
            } else {
                $this->proformaManager->expire($proforma);
            }
            $expired++;
        }

        // 3. Notify expired (on expiry day)
        $notified += $this->processProformas($io, $expiredProformas, 'proforma.expired', function (ProformaInvoice $p) {
            return sprintf(
                'Proforma %s a expirat (%s)',
                $p->getNumber(),
                $p->getValidUntil()->format('d.m.Y'),
            );
        }, 'Proforma expirată', $dryRun, $today);

        if ($dryRun) {
            $io->note(sprintf('Dry run: %d proformas would be expired, %d notifications would be sent.', $expired, $notified));
        } else {
            $io->success(sprintf('Expired %d proformas, sent %d notifications.', $expired, $notified));
        }

        return Command::SUCCESS;
    }

    /**
     * @param ProformaInvoice[] $proformas
     */
    private function processProformas(
        SymfonyStyle $io,
        array $proformas,
        string $type,
        callable $messageBuilder,
        string $title,
        bool $dryRun,
        \DateTime $today,
    ): int {
        $sent = 0;

        foreach ($proformas as $proforma) {
            $company = $proforma->getCompany();
            $users = $this->membershipRepository->findActiveUsersByCompany($company);

            foreach ($users as $user) {
                // Dedup: check if we already sent this notification type for this proforma today
                $existing = $this->notificationRepository->createQueryBuilder('n')
                    ->select('COUNT(n.id)')
                    ->where('n.user = :user')
                    ->andWhere('n.type = :type')
                    ->andWhere('n.sentAt >= :todayStart')
                    ->andWhere('n.sentAt < :todayEnd')
                    ->andWhere('n.data LIKE :proformaId')
                    ->setParameter('user', $user)
                    ->setParameter('type', $type)
                    ->setParameter('todayStart', new \DateTimeImmutable($today->format('Y-m-d') . ' 00:00:00'))
                    ->setParameter('todayEnd', new \DateTimeImmutable($today->format('Y-m-d') . ' 23:59:59'))
                    ->setParameter('proformaId', '%"proformaId":"' . $proforma->getId()->toRfc4122() . '"%')
                    ->getQuery()
                    ->getSingleScalarResult();

                if ((int) $existing > 0) {
                    continue;
                }

                $message = $messageBuilder($proforma);
                $clientName = $proforma->getClient()?->getName() ?? '';
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
                            'proformaId' => $proforma->getId()->toRfc4122(),
                            'proformaNumber' => $proforma->getNumber(),
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
