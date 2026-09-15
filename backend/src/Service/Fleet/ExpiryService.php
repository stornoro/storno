<?php

declare(strict_types=1);

namespace App\Service\Fleet;

use App\Entity\Company;
use App\Entity\ExpiryItem;
use App\Entity\Vehicle;
use App\Repository\ExpiryItemRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Expiry items (vehicle documents and company-level ones): validation, the upcoming list
 * with days left and status, and renewal — the next item is created from the old one, which
 * is closed and kept as history.
 */
final class ExpiryService
{
    public const DEFAULT_REMIND_DAYS = 30;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ExpiryItemRepository $items,
    ) {
    }

    /** @param array<string, mixed> $input */
    public function create(Company $company, array $input, ?Vehicle $vehicle = null): ExpiryItem
    {
        $item = (new ExpiryItem())->setCompany($company)->setVehicle($vehicle);
        if (!isset($input['expiresAt']) || (string) $input['expiresAt'] === '') {
            throw new \InvalidArgumentException('expiresAt (data expirării) este obligatorie.');
        }
        $this->apply($item, $input);
        if ($item->getLabel() === '') {
            $item->setLabel(self::defaultLabel($item->getKind()));
        }
        $this->em->persist($item);
        $this->em->flush();

        return $item;
    }

    /** @param array<string, mixed> $input */
    public function apply(ExpiryItem $item, array $input): ExpiryItem
    {
        if (isset($input['kind'])) {
            if (!in_array($input['kind'], ExpiryItem::KINDS, true)) {
                throw new \InvalidArgumentException('Tip necunoscut: ' . $input['kind'] . '. Disponibile: ' . implode(', ', ExpiryItem::KINDS));
            }
            $item->setKind((string) $input['kind']);
        }
        if (isset($input['label'])) {
            $item->setLabel(mb_substr(trim((string) $input['label']), 0, 160));
        }
        foreach (['number' => 100, 'provider' => 120] as $key => $max) {
            if (array_key_exists($key, $input)) {
                $value = $input[$key] !== null ? trim((string) $input[$key]) : '';
                $item->{'set' . ucfirst($key)}($value !== '' ? mb_substr($value, 0, $max) : null);
            }
        }
        if (array_key_exists('validFrom', $input)) {
            $item->setValidFrom($input['validFrom'] ? self::date((string) $input['validFrom'], 'validFrom') : null);
        }
        if (array_key_exists('expiresAt', $input) && $input['expiresAt']) {
            $item->setExpiresAt(self::date((string) $input['expiresAt'], 'expiresAt'));
        }
        if ($item->getValidFrom() !== null && $item->getValidFrom() > $item->getExpiresAt()) {
            throw new \InvalidArgumentException('validFrom nu poate fi după expiresAt.');
        }
        if (array_key_exists('remindDaysBefore', $input) && $input['remindDaysBefore'] !== null && $input['remindDaysBefore'] !== '') {
            $item->setRemindDaysBefore((int) $input['remindDaysBefore']);
        }
        if (array_key_exists('notes', $input)) {
            $item->setNotes($input['notes'] !== null && (string) $input['notes'] !== '' ? (string) $input['notes'] : null);
        }
        $item->touch();

        return $item;
    }

    /**
     * Renew: the next item takes over kind, label, vehicle, provider and reminder setting; the
     * old one is closed and linked as `renewedFrom`. Without a date the usual validity of the
     * kind is added to the old expiry (or to today when that is already past).
     *
     * @param array<string, mixed> $input {expiresAt?, validFrom?, number?, provider?, notes?}
     */
    public function renew(ExpiryItem $old, array $input = [], ?\DateTimeImmutable $today = null): ExpiryItem
    {
        if ($old->isClosed()) {
            throw new \InvalidArgumentException('Elementul a fost deja reînnoit.');
        }
        $today = ($today ?? new \DateTimeImmutable('today'))->setTime(0, 0);
        $next = (new ExpiryItem())
            ->setCompany($old->getCompany())
            ->setVehicle($old->getVehicle())
            ->setKind($old->getKind())
            ->setLabel($old->getLabel())
            ->setProvider($old->getProvider())
            ->setRemindDaysBefore($old->getRemindDaysBefore())
            ->setRenewedFrom($old);
        $expiresAt = isset($input['expiresAt']) && (string) $input['expiresAt'] !== '' ? self::date((string) $input['expiresAt'], 'expiresAt') : self::proposedNextExpiry($old, $today);
        $validFrom = isset($input['validFrom']) && (string) $input['validFrom'] !== '' ? self::date((string) $input['validFrom'], 'validFrom') : max($old->getExpiresAt(), $today);
        if ($expiresAt <= $old->getExpiresAt() && $expiresAt < $today) {
            throw new \InvalidArgumentException('Noua dată de expirare trebuie să fie după cea veche.');
        }
        $next->setExpiresAt($expiresAt)->setValidFrom($validFrom > $expiresAt ? null : $validFrom);
        foreach (['number', 'provider', 'notes'] as $key) {
            if (isset($input[$key]) && (string) $input[$key] !== '') {
                $next->{'set' . ucfirst($key)}(mb_substr(trim((string) $input[$key]), 0, $key === 'notes' ? 5000 : 120));
            }
        }
        $old->setClosedAt(new \DateTimeImmutable())->touch();
        $this->em->persist($next);
        $this->em->flush();

        return $next;
    }

    /** The next expiry a renewal proposes: the usual validity of the kind after the old expiry (or after today when already expired). */
    public static function proposedNextExpiry(ExpiryItem $old, \DateTimeImmutable $today): \DateTimeImmutable
    {
        $months = ExpiryItem::DEFAULT_MONTHS[$old->getKind()] ?? 12;
        $base = max($old->getExpiresAt()->setTime(0, 0), $today->setTime(0, 0));

        return $base->modify(sprintf('+%d months', $months));
    }

    /**
     * Open items expiring within $days days (expired ones included), each as a flat row for
     * the web page, the app and the dashboard card, expired first then soonest first.
     *
     * @return list<array<string, mixed>>
     */
    public function upcoming(Company $company, int $days = 60, ?\DateTimeImmutable $today = null): array
    {
        $today = ($today ?? new \DateTimeImmutable('today'))->setTime(0, 0);
        $rows = [];
        foreach ($this->items->findUpcoming($company, $days, $today) as $item) {
            $rows[] = self::row($item, $today);
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    public static function row(ExpiryItem $item, ?\DateTimeImmutable $today = null): array
    {
        $today = ($today ?? new \DateTimeImmutable('today'))->setTime(0, 0);

        return [
            'id' => $item->getId()?->toRfc4122(),
            'kind' => $item->getKind(),
            'label' => $item->getLabel(),
            'number' => $item->getNumber(),
            'provider' => $item->getProvider(),
            'validFrom' => $item->getValidFrom()?->format('Y-m-d'),
            'expiresAt' => $item->getExpiresAt()->format('Y-m-d'),
            'daysLeft' => $item->daysLeftOn($today),
            'status' => $item->statusOn($today),
            'remindDaysBefore' => $item->getRemindDaysBefore(),
            'vehicleId' => $item->getVehicleId(),
            'vehicle' => $item->getVehicleSummary(),
            'notes' => $item->getNotes(),
        ];
    }

    /** @return array{total: int, expired: int, due: int, ok: int} */
    public static function counts(array $rows): array
    {
        $counts = ['total' => count($rows), 'expired' => 0, 'due' => 0, 'ok' => 0];
        foreach ($rows as $row) {
            if (isset($counts[$row['status']])) {
                $counts[$row['status']]++;
            }
        }

        return $counts;
    }

    public static function defaultLabel(string $kind): string
    {
        return match ($kind) {
            ExpiryItem::KIND_RCA => 'RCA',
            ExpiryItem::KIND_ITP => 'ITP',
            ExpiryItem::KIND_ROVINIETA => 'Rovinietă',
            ExpiryItem::KIND_CASCO => 'CASCO',
            ExpiryItem::KIND_TAHOGRAF => 'Tahograf (calibrare)',
            ExpiryItem::KIND_EXTINCTOR => 'Extinctor',
            ExpiryItem::KIND_TRUSA_MEDICALA => 'Trusă medicală',
            ExpiryItem::KIND_LICENTA_TRANSPORT => 'Licență de transport',
            ExpiryItem::KIND_COPIE_CONFORMA => 'Copie conformă',
            ExpiryItem::KIND_LEASING => 'Sfârșit leasing',
            ExpiryItem::KIND_CERTIFICAT_DIGITAL => 'Certificat digital',
            ExpiryItem::KIND_CONTRACT => 'Contract',
            ExpiryItem::KIND_AUTORIZATIE => 'Autorizație',
            default => 'Altele',
        };
    }

    private static function date(string $value, string $field): \DateTimeImmutable
    {
        try {
            return (new \DateTimeImmutable($value))->setTime(0, 0);
        } catch (\Throwable) {
            throw new \InvalidArgumentException(sprintf('%s: dată invalidă (%s); folosește YYYY-MM-DD.', $field, $value));
        }
    }
}
