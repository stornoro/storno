<?php

namespace App\Command\Notification;

use App\Entity\ExpiryItem;
use App\Enum\MessageKey;
use App\Repository\ExpiryItemRepository;
use App\Repository\OrganizationMembershipRepository;
use App\Service\Fleet\ExpiryService;
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
 * Daily: remind the members of a company of the vehicle documents and company items that
 * expire — at the item's own `remindDaysBefore` (30 by default), then 7 and 1 days before
 * and on the day. Each threshold is notified once per item; a changed date restarts them.
 * Already expired items are not nagged daily; they stay visible as "expired" in the lists.
 */
#[AsCommand(
    name: 'app:notifications:expiries',
    description: 'Remind company members of expiring vehicle documents and company items (30 / 7 / 1 / 0 days)',
)]
class ExpiryReminderCommand extends Command
{
    public const TYPE = 'expiry.due';
    public const THRESHOLDS = [7, 1, 0];

    public function __construct(
        private readonly ExpiryItemRepository $items,
        private readonly OrganizationMembershipRepository $memberships,
        private readonly NotificationService $notifications,
        private readonly TranslatorInterface $translator,
        private readonly EntityManagerInterface $em,
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

        foreach ($this->items->findOpenExpiringWithin(365, $today) as $item) {
            $company = $item->getCompany();
            if ($company === null || $company->getDeletedAt() !== null || !$company->getOrganization()?->isActive()) {
                continue;
            }
            $days = $item->daysLeftOn($today);
            $threshold = $this->thresholdFor($item, $days);
            if ($threshold === null) {
                continue;
            }
            $companyName = $company->getName() ?? '—';
            $vehicle = $item->getVehicle();
            foreach ($this->memberships->findActiveUsersByCompany($company) as $user) {
                $locale = $user->getLocale() ?? 'ro';
                $params = [
                    'company' => $companyName,
                    'label' => $item->getLabel(),
                    'kind' => $this->translator->trans('notification.expiry.kind.' . $item->getKind(), [], 'notifications', $locale),
                    'vehicle' => $vehicle?->getDisplayName() ?? '',
                    'subject' => $vehicle?->getDisplayName() ?? $companyName,
                    'date' => $item->getExpiresAt()->format('d.m.Y'),
                    'count' => $days,
                    'days' => (string) $days,
                ];
                $transParams = [];
                foreach ($params as $name => $value) {
                    $transParams['%' . $name . '%'] = $value;
                }
                $titleKey = $days === 0 ? MessageKey::TITLE_EXPIRY_TODAY : MessageKey::TITLE_EXPIRY_DUE;
                $messageKey = $days === 0 ? MessageKey::MSG_EXPIRY_TODAY : MessageKey::MSG_EXPIRY_DUE;
                $title = $this->translator->trans($titleKey, $transParams, 'notifications', $locale);
                $message = $this->translator->trans($messageKey, $transParams, 'notifications', $locale);

                if ($dryRun) {
                    $io->text(sprintf('  [DRY RUN] → %s [%s]: %s', $user->getEmail(), $locale, $message));
                } else {
                    $this->notifications->createNotification($user, self::TYPE, $title, $message, [
                        'expiryId' => $item->getId()?->toRfc4122(),
                        'vehicleId' => $item->getVehicleId(),
                        'vehicle' => $vehicle?->getDisplayName(),
                        'kind' => $item->getKind(),
                        'kindLabel' => $params['kind'],
                        'label' => $item->getLabel(),
                        'number' => $item->getNumber(),
                        'provider' => $item->getProvider(),
                        'expiresAt' => $item->getExpiresAt()->format('Y-m-d'),
                        'expiresAtLabel' => $item->getExpiresAt()->format('d.m.Y'),
                        'daysLeft' => $days,
                        'threshold' => $threshold,
                        'companyId' => $company->getId()?->toRfc4122(),
                        'companyName' => $companyName,
                        'url' => $vehicle ? '/vehicles/' . $vehicle->getId()?->toRfc4122() : '/expiries',
                        'titleKey' => $titleKey,
                        'titleParams' => $params,
                        'messageKey' => $messageKey,
                        'messageParams' => $params,
                    ]);
                }
                $sent++;
            }
            if (!$dryRun) {
                $item->markNotified($threshold);
            }
        }
        if (!$dryRun) {
            $this->em->flush();
        }

        if ($dryRun) {
            $io->note(sprintf('Dry run: %d expiry reminders would be sent.', $sent));
        } else {
            $io->success(sprintf('Sent %d expiry reminders.', $sent));
        }

        return Command::SUCCESS;
    }

    /**
     * The smallest threshold already reached (the item's own remindDaysBefore, then 7, 1 and 0
     * days — only those at or below remindDaysBefore), unless it was notified already. Null when
     * nothing is due today. A threshold skipped because the item was created late is never sent.
     */
    public static function thresholdFor(ExpiryItem $item, int $days): ?int
    {
        if ($days < 0) {
            return null;
        }
        $reached = null;
        foreach (array_unique([$item->getRemindDaysBefore(), ...self::THRESHOLDS]) as $t) {
            if ($t <= $item->getRemindDaysBefore() && $days <= $t && ($reached === null || $t < $reached)) {
                $reached = $t;
            }
        }
        if ($reached === null || in_array($reached, $item->getNotified(), true)) {
            return null;
        }

        return $reached;
    }
}
