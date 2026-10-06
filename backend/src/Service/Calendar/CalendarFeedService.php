<?php

namespace App\Service\Calendar;

use App\Entity\CalendarFeed;
use App\Entity\Company;
use App\Entity\ExpiryItem;
use App\Entity\OrganizationMembership;
use App\Repository\CalendarFeedRepository;
use App\Repository\CompanyRepository;
use App\Repository\ExpiryItemRepository;
use App\Repository\OrganizationMembershipRepository;
use App\Security\Permission;
use App\Security\RolePermissionMap;
use App\Service\Fleet\ExpiryService;

/**
 * Builds the calendar subscription of a member: the secret link and the iCalendar body.
 *
 * The body is recomputed on every fetch from what the member can see right now, so a revoked
 * company access, a renewed RCA or a filed declaration disappears from the phone at the next refresh.
 */
class CalendarFeedService
{
    public const FISCAL_WINDOW_DAYS = 366;
    public const FISCAL_ALARMS = [3, 1];

    public function __construct(
        private readonly CalendarFeedRepository $feeds,
        private readonly OrganizationMembershipRepository $memberships,
        private readonly CompanyRepository $companies,
        private readonly ExpiryItemRepository $expiries,
        private readonly FiscalCalendarService $fiscalCalendar,
        private readonly string $appSecret,
        private readonly string $frontendUrl,
    ) {
    }

    public function signature(CalendarFeed $feed): string
    {
        return substr(hash_hmac('sha256', 'calendar-feed|' . $feed->getId()?->toRfc4122() . '|' . $feed->getVersion(), $this->appSecret), 0, 40);
    }

    public function url(CalendarFeed $feed, string $baseUrl): string
    {
        return rtrim($baseUrl, '/') . '/api/v1/calendar/feed/' . $feed->getId()?->toRfc4122() . '/' . $this->signature($feed) . '.ics';
    }

    /** The feed a link points at, or null when the id is unknown or the signature is stale or wrong. */
    public function findByLink(string $id, string $signature): ?CalendarFeed
    {
        if (!preg_match('/^[0-9a-f-]{36}$/', $id) || !preg_match('/^[0-9a-f]{40}$/', $signature)) {
            return null;
        }
        $feed = $this->feeds->find($id);
        if (!$feed instanceof CalendarFeed || !hash_equals($this->signature($feed), $signature)) {
            return null;
        }

        return $feed;
    }

    /** The active membership behind the feed, or null when the member left or was disabled. */
    public function activeMembership(CalendarFeed $feed): ?OrganizationMembership
    {
        $user = $feed->getUser();
        $organization = $feed->getOrganization();
        if ($user === null || $organization === null || $user->isActive() === false || !$organization->isActive()) {
            return null;
        }
        $membership = $this->memberships->findByUserAndOrganization($user, $organization);

        return $membership !== null && $membership->isActive() ? $membership : null;
    }

    public static function memberCan(OrganizationMembership $membership, string $permission): bool
    {
        $permissions = $membership->getPermissions();
        if ($permissions === []) {
            $permissions = RolePermissionMap::getPermissions($membership->getRole());
        }

        return in_array($permission, $permissions, true);
    }

    public function render(CalendarFeed $feed, OrganizationMembership $membership, ?\DateTimeImmutable $today = null): string
    {
        $today = ($today ?? new \DateTimeImmutable('today'))->setTime(0, 0);
        $stamp = $feed->getUpdatedAt();
        $withExpiries = $feed->includesExpiries() && self::memberCan($membership, Permission::SETTINGS_VIEW);
        $withFiscal = $feed->includesFiscal() && self::memberCan($membership, Permission::DECLARATION_VIEW);

        $ics = new IcsWriter('Storno · ' . ($feed->getOrganization()?->getName() ?: 'termene'));
        foreach ($this->companies->findByOrganizationAndMembership($membership->getOrganization(), $membership) as $company) {
            if ($company->getDeletedAt() !== null) {
                continue;
            }
            if ($withExpiries) {
                foreach ($this->expiries->findForCompany($company) as $item) {
                    $this->addExpiry($ics, $company, $item, $stamp);
                }
            }
            if ($withFiscal) {
                foreach ($this->fiscalCalendar->upcoming($company, $today, self::FISCAL_WINDOW_DAYS) as $deadline) {
                    $this->addDeadline($ics, $company, $deadline, $stamp);
                }
            }
        }

        return $ics->render();
    }

    private function addExpiry(IcsWriter $ics, Company $company, ExpiryItem $item, \DateTimeImmutable $stamp): void
    {
        $vehicle = $item->getVehicle();
        $label = $item->getLabel() !== '' ? $item->getLabel() : ExpiryService::defaultLabel($item->getKind());
        $subject = $vehicle?->getDisplayName() ?: ($company->getName() ?? '');
        $lines = array_filter([
            'Firma: ' . ($company->getName() ?? ''),
            $vehicle ? 'Vehicul: ' . $vehicle->getDisplayName() : null,
            $item->getNumber() ? 'Număr: ' . $item->getNumber() : null,
            $item->getProvider() ? 'Emitent: ' . $item->getProvider() : null,
            $item->getNotes() ?: null,
        ]);
        $url = rtrim($this->frontendUrl, '/') . ($vehicle ? '/vehicles/' . $vehicle->getId()?->toRfc4122() : '/expiries');
        $alarms = [1];
        if ($item->getRemindDaysBefore() > 1) {
            array_unshift($alarms, $item->getRemindDaysBefore());
        }
        $ics->addAllDayEvent(
            'expiry-' . $item->getId()?->toRfc4122() . '@storno.ro',
            $item->getExpiresAt(),
            sprintf('Expiră %s · %s', $label, $subject),
            implode("\n", [...$lines, 'Deschide în Storno: ' . $url]),
            $url,
            $alarms,
            $stamp,
        );
    }

    /** @param array<string, mixed> $deadline */
    private function addDeadline(IcsWriter $ics, Company $company, array $deadline, \DateTimeImmutable $stamp): void
    {
        if (($deadline['status'] ?? null) === FiscalCalendarService::STATUS_FILED) {
            return;
        }
        $code = (string) $deadline['code'];
        $label = (string) ($deadline['label'] ?? $code);
        $summary = str_starts_with($label, $code) || !preg_match('/^[A-Z]\d/', $code) ? $label : $code . ' · ' . $label;
        $url = rtrim($this->frontendUrl, '/') . '/fiscal-calendar';
        $period = self::periodLabel(is_array($deadline['period'] ?? null) ? $deadline['period'] : []);
        $lines = array_filter([
            'Firma: ' . ($company->getName() ?? ''),
            $period !== null ? 'Perioada: ' . $period : null,
            isset($deadline['dosarTitle']) ? 'Dosar: ' . $deadline['dosarTitle'] : null,
            'Deschide în Storno: ' . $url,
        ]);
        $ics->addAllDayEvent(
            sprintf('fiscal-%s-%s-%s@storno.ro', $company->getId()?->toRfc4122(), strtolower($code), $deadline['dueDate']),
            new \DateTimeImmutable((string) $deadline['dueDate']),
            sprintf('%s · %s', $summary, $company->getName() ?? ''),
            implode("\n", $lines),
            $url,
            self::FISCAL_ALARMS,
            $stamp,
        );
    }

    private const MONTHS = ['ianuarie', 'februarie', 'martie', 'aprilie', 'mai', 'iunie', 'iulie', 'august', 'septembrie', 'octombrie', 'noiembrie', 'decembrie'];

    /** @param array<string, mixed> $period */
    public static function periodLabel(array $period): ?string
    {
        if (!isset($period['year'])) {
            return null;
        }
        if (isset($period['month'])) {
            return self::MONTHS[((int) $period['month'] - 1) % 12] . ' ' . $period['year'];
        }
        if (isset($period['quarter'])) {
            return 'trimestrul ' . $period['quarter'] . ' ' . $period['year'];
        }

        return 'anul ' . $period['year'];
    }
}
