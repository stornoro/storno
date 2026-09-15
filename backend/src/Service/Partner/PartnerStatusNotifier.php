<?php

namespace App\Service\Partner;

use App\Entity\Client;
use App\Entity\Supplier;
use App\Enum\MessageKey;
use App\Repository\OrganizationMembershipRepository;
use App\Service\NotificationService;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Sends `partner.status_changed` to every active member of the partner's
 * company when a registry check finds the partner inactive, without VAT
 * registration, with VAT on collection or with an invalid VIES number.
 */
class PartnerStatusNotifier
{
    public const TYPE = 'partner.status_changed';

    public function __construct(
        private readonly OrganizationMembershipRepository $membershipRepository,
        private readonly NotificationService $notificationService,
        private readonly TranslatorInterface $translator,
    ) {}

    /** @param string[] $changes PartnerVerificationService::CHANGE_* codes */
    public function notify(Client|Supplier $partner, array $changes): void
    {
        $company = $partner->getCompany();
        if ($company === null || $changes === []) {
            return;
        }

        $isClient = $partner instanceof Client;
        $identifier = $isClient ? $partner->getCui() : $partner->getCif();
        $users = $this->membershipRepository->findActiveUsersByCompany($company);

        foreach ($users as $user) {
            $locale = $user->getLocale() ?? 'ro';
            $labels = array_map(
                fn (string $change) => $this->translator->trans('notification.partner_status_changed.change.' . $change, [], 'notifications', $locale),
                $changes,
            );
            $params = [
                'company' => $company->getName() ?? '—',
                'partner' => $partner->getName() ?? '—',
                'identifier' => $identifier ?: '—',
                'kind' => $this->translator->trans('notification.partner_status_changed.kind.' . ($isClient ? 'client' : 'supplier'), [], 'notifications', $locale),
                'changes' => implode(', ', $labels),
            ];
            $transParams = [];
            foreach ($params as $name => $value) {
                $transParams['%' . $name . '%'] = $value;
            }
            $title = $this->translator->trans(MessageKey::TITLE_PARTNER_STATUS_CHANGED, $transParams, 'notifications', $locale);
            $message = $this->translator->trans(MessageKey::MSG_PARTNER_STATUS_CHANGED, $transParams, 'notifications', $locale);

            $this->notificationService->createNotification($user, self::TYPE, $title, $message, [
                'partnerType' => $isClient ? 'client' : 'supplier',
                'partnerId' => (string) $partner->getId(),
                'partnerName' => $partner->getName(),
                'changes' => $changes,
                'companyId' => (string) $company->getId(),
                'companyName' => $company->getName(),
                'titleKey' => MessageKey::TITLE_PARTNER_STATUS_CHANGED,
                'titleParams' => $params,
                'messageKey' => MessageKey::MSG_PARTNER_STATUS_CHANGED,
                'messageParams' => $params,
            ]);
        }
    }
}
