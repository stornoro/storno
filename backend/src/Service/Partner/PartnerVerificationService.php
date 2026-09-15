<?php

namespace App\Service\Partner;

use App\Entity\Client;
use App\Entity\Company;
use App\Entity\Supplier;
use App\Exception\AnafRateLimitException;
use App\Model\Anaf\CompanyInfo;
use App\Repository\ClientRepository;
use App\Repository\SupplierRepository;
use App\Service\Anaf\AnafRateLimiter;
use App\Service\Vies\ViesService;
use App\Services\AnafService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Checks a partner (client or supplier) against the public registries and
 * stores the answer on the entity: ANAF for Romanian companies (VAT
 * registration, VAT on collection with its period, inactive status, RO
 * e-Factura register), VIES for EU partners. Registry outages never fail the
 * call: the partner keeps its previous snapshot, gets a note and stays
 * "unchecked" so the daily job retries it.
 *
 * Nothing is changed on the partner's own invoicing settings (`isVatPayer`,
 * `vatCode`); the snapshot is informational and drives badges, warnings and
 * the `partner.status_changed` notification.
 */
class PartnerVerificationService
{
    public const STALE_AFTER_DAYS = 30;
    public const ANAF_BATCH_SIZE = 100;

    public const CHANGE_BECAME_INACTIVE = 'became_inactive';
    public const CHANGE_REACTIVATED = 'reactivated';
    public const CHANGE_LOST_VAT = 'lost_vat_registration';
    public const CHANGE_VAT_REGISTERED = 'vat_registered';
    public const CHANGE_VAT_ON_COLLECTION = 'vat_on_collection';
    public const CHANGE_VAT_ON_COLLECTION_ENDED = 'vat_on_collection_ended';
    public const CHANGE_VIES_INVALID = 'vies_invalid';
    public const CHANGE_VIES_VALID = 'vies_valid';

    /** Changes worth a notification: the partner became a worse counterparty. */
    public const DEGRADING_CHANGES = [
        self::CHANGE_BECAME_INACTIVE,
        self::CHANGE_LOST_VAT,
        self::CHANGE_VAT_ON_COLLECTION,
        self::CHANGE_VIES_INVALID,
    ];

    public const EU_COUNTRY_CODES = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'GR', 'ES',
        'FI', 'FR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT',
        'NL', 'PL', 'PT', 'SE', 'SI', 'SK', 'XI',
    ];

    public function __construct(
        private readonly AnafService $anafService,
        private readonly ViesService $viesService,
        private readonly AnafRateLimiter $rateLimiter,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClientRepository $clientRepository,
        private readonly SupplierRepository $supplierRepository,
        private readonly PartnerStatusNotifier $notifier,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return array{checked: bool, source: 'anaf'|'vies'|null, changes: string[], error: ?string}
     */
    public function verify(Client|Supplier $partner, bool $notify = true): array
    {
        $result = $this->verifyWithoutFlush($partner, $notify);
        $this->entityManager->flush();

        return $result;
    }

    /**
     * Re-check every company / EU partner of the company whose last check is
     * older than `$since` (default 30 days) or missing. Romanian partners go to
     * ANAF in batches of 100 (one rate-limiter token per batch), EU partners to
     * VIES one by one.
     *
     * @return array{checked: int, changed: int, failed: int, skipped: int}
     */
    public function verifyAll(Company $company, ?\DateTimeImmutable $since = null): array
    {
        $since ??= new \DateTimeImmutable('-' . self::STALE_AFTER_DAYS . ' days');
        $counts = ['checked' => 0, 'changed' => 0, 'failed' => 0, 'skipped' => 0];

        /** @var array<int, Client|Supplier> $partners */
        $partners = array_merge(
            $this->clientRepository->findStaleForVerification($company, $since),
            $this->supplierRepository->findStaleForVerification($company, $since),
        );

        $romanian = [];
        $european = [];
        foreach ($partners as $partner) {
            $kind = $this->registryFor($partner);
            if ($kind === 'anaf') {
                $romanian[] = $partner;
            } elseif ($kind === 'vies') {
                $european[] = $partner;
            } else {
                $counts['skipped']++;
            }
        }

        foreach (array_chunk($romanian, self::ANAF_BATCH_SIZE) as $index => $batch) {
            if ($index > 0) {
                // ANAF's public registry accepts one request per second.
                sleep(1);
            }
            $byCui = [];
            foreach ($batch as $partner) {
                $byCui[$this->cuiDigits($partner)][] = $partner;
            }

            $answers = null;
            try {
                $this->rateLimiter->consumeGlobal();
                $answers = $this->anafService->findCompaniesOrNull(array_keys($byCui));
            } catch (AnafRateLimitException $e) {
                $this->logger->warning('Partner verification: ANAF rate limit hit', ['company' => (string) $company->getId()]);
            }

            foreach ($byCui as $cui => $group) {
                foreach ($group as $partner) {
                    $info = $answers === null ? null : ($answers[$cui] ?? null);
                    $result = $this->applyAnafAnswer($partner, $info, $answers !== null);
                    $this->tally($counts, $result);
                    $this->notifyIfDegraded($partner, $result['changes']);
                }
            }
            $this->entityManager->flush();
        }

        foreach ($european as $partner) {
            $result = $this->applyVies($partner);
            $this->tally($counts, $result);
            $this->notifyIfDegraded($partner, $result['changes']);
        }
        if ($european !== []) {
            $this->entityManager->flush();
        }

        return $counts;
    }

    /**
     * Which registry answers for this partner: 'anaf' (Romanian company with a
     * CUI), 'vies' (EU company with a VAT number) or null (nothing to check —
     * individuals, non-EU partners, partners without an identifier).
     */
    public function registryFor(Client|Supplier $partner): ?string
    {
        if ($partner instanceof Client && $partner->getType() !== 'company') {
            return null;
        }
        $country = strtoupper(trim($partner->getCountry() ?: 'RO')) ?: 'RO';
        if ($country === 'RO') {
            return $this->cuiDigits($partner) !== '' ? 'anaf' : null;
        }
        if (in_array($country, self::EU_COUNTRY_CODES, true)) {
            return $this->viesNumber($partner) !== null ? 'vies' : null;
        }

        return null;
    }

    /** @return array{checked: bool, source: 'anaf'|'vies'|null, changes: string[], error: ?string} */
    private function verifyWithoutFlush(Client|Supplier $partner, bool $notify): array
    {
        $registry = $this->registryFor($partner);
        if ($registry === null) {
            return ['checked' => false, 'source' => null, 'changes' => [], 'error' => 'not_applicable'];
        }

        if ($registry === 'vies') {
            $result = $this->applyVies($partner);
        } else {
            $cui = $this->cuiDigits($partner);
            $answers = null;
            try {
                $this->rateLimiter->consumeGlobal();
                $answers = $this->anafService->findCompaniesOrNull([$cui]);
            } catch (AnafRateLimitException) {
                $answers = null;
            }
            $result = $this->applyAnafAnswer($partner, $answers[$cui] ?? null, $answers !== null);
        }

        if ($notify) {
            $this->notifyIfDegraded($partner, $result['changes']);
        }

        return $result;
    }

    /** @return array{checked: bool, source: 'anaf', changes: string[], error: ?string} */
    private function applyAnafAnswer(Client|Supplier $partner, ?CompanyInfo $info, bool $registryAnswered): array
    {
        if (!$registryAnswered) {
            $partner->setVerificationNotes('ANAF: registrul nu a raspuns; verificarea se reia automat.');

            return ['checked' => false, 'source' => 'anaf', 'changes' => [], 'error' => 'registry_unavailable'];
        }

        $now = new \DateTimeImmutable();
        if ($info === null) {
            $partner->setVatStatusCheckedAt($now);
            $partner->setVerificationNotes('ANAF: CUI negasit in registru.');

            return ['checked' => true, 'source' => 'anaf', 'changes' => [], 'error' => 'not_found'];
        }

        $raw = $info->getRawData();
        $changes = [];

        $wasInactive = $partner->isInactive();
        $wasVatRegistered = $partner->isVatRegistered() ?? ($partner->getVatStatusCheckedAt() === null ? $partner->isVatPayer() : null);
        $wasOnCollection = $partner->isVatOnCollection();

        $inactive = $info->isInactive();
        $vatRegistered = $info->isVatPayer();
        $onCollection = $info->isVatOnCollection();

        if ($inactive && $wasInactive !== true) {
            $changes[] = self::CHANGE_BECAME_INACTIVE;
        } elseif (!$inactive && $wasInactive === true) {
            $changes[] = self::CHANGE_REACTIVATED;
        }
        if (!$vatRegistered && $wasVatRegistered === true) {
            $changes[] = self::CHANGE_LOST_VAT;
        } elseif ($vatRegistered && $wasVatRegistered === false) {
            $changes[] = self::CHANGE_VAT_REGISTERED;
        }
        if ($onCollection && $wasOnCollection !== true) {
            $changes[] = self::CHANGE_VAT_ON_COLLECTION;
        } elseif (!$onCollection && $wasOnCollection === true) {
            $changes[] = self::CHANGE_VAT_ON_COLLECTION_ENDED;
        }

        $partner->setInactive($inactive);
        $partner->setVatRegistered($vatRegistered);
        $partner->setVatOnCollection($onCollection);
        $partner->setVatOnCollectionFrom($this->anafDate($raw['inregistrare_RTVAI']['dataInceputTvaInc'] ?? null));
        $partner->setVatOnCollectionTo($this->anafDate($raw['inregistrare_RTVAI']['dataSfarsitTvaInc'] ?? null));
        $partner->setEfacturaRegistered($info->isEFacturaEnabled());
        $partner->setVatStatusCheckedAt($now);
        $partner->setVerificationNotes($this->anafNotes($partner, $info));

        return ['checked' => true, 'source' => 'anaf', 'changes' => $changes, 'error' => null];
    }

    /** @return array{checked: bool, source: 'vies', changes: string[], error: ?string} */
    private function applyVies(Client|Supplier $partner): array
    {
        $number = $this->viesNumber($partner);
        if ($number === null) {
            return ['checked' => false, 'source' => 'vies', 'changes' => [], 'error' => 'not_applicable'];
        }

        $answer = $this->viesService->validate($number['countryCode'], $number['vatNumber']);
        if ($answer === null) {
            $partner->setViesValid(null);
            $partner->setVerificationNotes('VIES: serviciul nu a raspuns; verificarea se reia automat.');

            return ['checked' => false, 'source' => 'vies', 'changes' => [], 'error' => 'registry_unavailable'];
        }

        $changes = [];
        $wasValid = $partner->isViesValid();
        if (!$answer['valid'] && $wasValid === true) {
            $changes[] = self::CHANGE_VIES_INVALID;
        } elseif ($answer['valid'] && $wasValid === false) {
            $changes[] = self::CHANGE_VIES_VALID;
        }

        $now = new \DateTimeImmutable();
        $partner->setViesValid($answer['valid']);
        $partner->setVatRegistered($answer['valid']);
        $partner->setVatStatusCheckedAt($now);
        if ($partner instanceof Client) {
            $partner->setViesValidatedAt($now);
            $partner->setViesName($answer['name']);
        }
        $notes = $answer['valid'] ? 'VIES: cod TVA valid.' : 'VIES: cod TVA invalid.';
        if ($answer['name'] && mb_strtoupper(trim($answer['name'])) !== mb_strtoupper(trim((string) $partner->getName()))) {
            $notes .= ' Denumire VIES: ' . $answer['name'] . '.';
        }
        $partner->setVerificationNotes($notes);

        return ['checked' => true, 'source' => 'vies', 'changes' => $changes, 'error' => null];
    }

    private function anafNotes(Client|Supplier $partner, CompanyInfo $info): string
    {
        $raw = $info->getRawData();
        $parts = [];

        $anafName = mb_strtoupper(trim($info->getName()));
        if ($anafName !== '' && $anafName !== mb_strtoupper(trim((string) $partner->getName()))) {
            $parts[] = 'Denumire ANAF: ' . $info->getName() . '.';
        }
        if ($info->isInactive()) {
            $date = $raw['stare_inactiv']['dataInactivare'] ?? null;
            $parts[] = 'Contribuabil inactiv' . ($this->anafDate($date) ? ' din ' . $this->anafDate($date)->format('d.m.Y') : '') . '.';
        }
        if (($status = trim((string) $info->getRegistrationStatus())) !== '' && stripos($status, 'INREGISTRAT') === false) {
            $parts[] = 'Stare inregistrare: ' . $status . '.';
        }
        if ($info->isVatPayer() !== $partner->isVatPayer()) {
            $parts[] = $info->isVatPayer()
                ? 'ANAF: inregistrat in scopuri de TVA, in Storno este marcat neplatitor.'
                : 'ANAF: neinregistrat in scopuri de TVA, in Storno este marcat platitor.';
        }
        if ($info->isVatOnCollection()) {
            $from = $this->anafDate($raw['inregistrare_RTVAI']['dataInceputTvaInc'] ?? null);
            $parts[] = 'TVA la incasare' . ($from ? ' din ' . $from->format('d.m.Y') : '') . '.';
        }
        if ($info->isSplitVat()) {
            $parts[] = 'Plata defalcata a TVA.';
        }

        return $parts === [] ? 'ANAF: fara observatii.' : implode(' ', $parts);
    }

    private function anafDate(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value))) {
            return null;
        }
        try {
            return new \DateTimeImmutable(trim($value));
        } catch (\Throwable) {
            return null;
        }
    }

    private function cuiDigits(Client|Supplier $partner): string
    {
        $cui = $partner instanceof Client ? $partner->getCui() : $partner->getCif();

        return preg_replace('/\D/', '', (string) $cui) ?? '';
    }

    /** @return array{countryCode: string, vatNumber: string}|null */
    private function viesNumber(Client|Supplier $partner): ?array
    {
        $country = strtoupper(trim($partner->getCountry() ?: ''));
        $vatCode = trim((string) $partner->getVatCode());
        if ($vatCode !== '') {
            $parsed = $this->viesService->parseVatCode($vatCode);
            if ($parsed !== null) {
                return $parsed;
            }
            return ['countryCode' => $country, 'vatNumber' => $vatCode];
        }
        $cui = trim((string) ($partner instanceof Client ? $partner->getCui() : $partner->getCif()));
        if ($cui === '') {
            return null;
        }
        $parsed = $this->viesService->parseVatCode($cui);
        if ($parsed !== null && in_array($parsed['countryCode'], self::EU_COUNTRY_CODES, true)) {
            return $parsed;
        }

        return ['countryCode' => $country, 'vatNumber' => preg_replace('/[^A-Z0-9]/i', '', $cui) ?? ''];
    }

    private function tally(array &$counts, array $result): void
    {
        if ($result['checked']) {
            $counts['checked']++;
            if ($result['changes'] !== []) {
                $counts['changed']++;
            }
        } else {
            $counts['failed']++;
        }
    }

    private function notifyIfDegraded(Client|Supplier $partner, array $changes): void
    {
        $degrading = array_values(array_intersect($changes, self::DEGRADING_CHANGES));
        if ($degrading === []) {
            return;
        }
        try {
            $this->notifier->notify($partner, $degrading);
        } catch (\Throwable $e) {
            $this->logger->error('Partner verification: notification failed', ['error' => $e->getMessage()]);
        }
    }
}
