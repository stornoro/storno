<?php

declare(strict_types=1);

namespace App\Service\Dosar;

use App\Entity\Company;
use App\Entity\Dosar;
use App\Entity\SpvDocument;
use App\Entity\SpvRequest;
use App\Entity\TaxDeclaration;
use App\Entity\User;
use App\Enum\DeclarationStatus;
use App\Enum\DeclarationType;
use App\Enum\SpvDocumentCategory;
use App\Repository\DosarRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Dosare: grouping, automatic linking of ANAF messages to what they answer,
 * the "what needs attention" feed, and the yearly Declarația unică dosar with
 * its 25 May deadline for people who rent out property.
 */
final class DosarService
{
    public const D212_DEADLINE_MONTH_DAY = '05-25';
    public const C168_DAYS = 30;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DosarRepository $dosare,
    ) {
    }

    /** @param array<string, mixed> $input */
    public function create(Company $company, array $input, ?User $user): Dosar
    {
        $type = (string) ($input['type'] ?? Dosar::TYPE_GENERIC);
        if (!in_array($type, Dosar::TYPES, true)) {
            throw new \InvalidArgumentException('Tip de dosar necunoscut: ' . $type . '. Disponibile: ' . implode(', ', Dosar::TYPES));
        }
        $dosar = (new Dosar())->setCompany($company)->setType($type)->setCreatedBy($user);
        $this->apply($dosar, $input);
        if ($dosar->getTitle() === '') {
            $dosar->setTitle($this->defaultTitle($dosar));
        }
        if ($type === Dosar::TYPE_RENTAL_CONTRACT && $dosar->getDeadlineAt() === null) {
            $this->setContractDeadline($dosar);
        }
        $this->em->persist($dosar);
        $this->em->flush();

        return $dosar;
    }

    /** @param array<string, mixed> $input */
    public function apply(Dosar $dosar, array $input): Dosar
    {
        if (isset($input['title'])) {
            $dosar->setTitle(mb_substr(trim((string) $input['title']), 0, 255));
        }
        if (isset($input['subject']) && is_array($input['subject'])) {
            $dosar->setSubject(array_replace($dosar->getSubject(), $input['subject']));
        }
        if (isset($input['status'])) {
            if (!in_array($input['status'], Dosar::STATUSES, true)) {
                throw new \InvalidArgumentException('Stare necunoscută: ' . $input['status']);
            }
            $dosar->setStatus((string) $input['status']);
        }
        if (array_key_exists('nextStep', $input)) {
            $dosar->setNextStep($input['nextStep'] !== null ? mb_substr((string) $input['nextStep'], 0, 500) : null);
        }
        if (array_key_exists('deadlineAt', $input)) {
            $dosar->setDeadlineAt($input['deadlineAt'] ? new \DateTimeImmutable((string) $input['deadlineAt']) : null);
        }
        if (array_key_exists('deadlineLabel', $input)) {
            $dosar->setDeadlineLabel($input['deadlineLabel'] !== null ? mb_substr((string) $input['deadlineLabel'], 0, 120) : null);
        }
        if (array_key_exists('notes', $input)) {
            $dosar->setNotes($input['notes'] !== null ? (string) $input['notes'] : null);
        }
        $dosar->touch();

        return $dosar;
    }

    private function defaultTitle(Dosar $dosar): string
    {
        $s = $dosar->getSubject();

        return match ($dosar->getType()) {
            Dosar::TYPE_RENTAL_CONTRACT => trim('Contract de închiriere ' . (($s['adresa'] ?? '') !== '' ? $s['adresa'] : ('nr. ' . ($s['numar'] ?? '?')))),
            Dosar::TYPE_ANNUAL_RETURN => 'Declarația unică ' . ($s['an'] ?? date('Y')),
            Dosar::TYPE_PERIODIC => 'Declarații periodice',
            Dosar::TYPE_FISCAL_STATUS => 'Situația fiscală',
            default => 'Dosar',
        };
    }

    /** A rental contract must be registered within 30 days of signing (or of the addendum / termination). */
    public function setContractDeadline(Dosar $dosar): void
    {
        $s = $dosar->getSubject();
        $ref = $s['dataIncetare'] ?? $s['dataModificare'] ?? $s['data'] ?? null;
        $date = $this->date($ref);
        if ($date === null) {
            return;
        }
        $deadline = $date->modify('+' . self::C168_DAYS . ' days');
        if ($deadline >= new \DateTimeImmutable('today')) {
            $label = isset($s['dataIncetare']) ? 'C168 încetare: 30 de zile de la încetare' : (isset($s['dataModificare']) ? 'C168 modificare: 30 de zile de la actul adițional' : 'C168 înregistrare: 30 de zile de la semnarea contractului');
            $dosar->setDeadlineAt($deadline)->setDeadlineLabel($label);
        }
    }

    // ── Linking ────────────────────────────────────────────────────────

    /** Called by the SPV inbox sync for every new message: recipisa → declaration's dosar, answer → request's dosar. */
    public function linkDocument(Company $company, SpvDocument $doc): void
    {
        if ($doc->getDosar() !== null) {
            return;
        }
        $index = $this->recipisaIndex($doc);
        if ($index !== null) {
            $decl = $this->em->getRepository(TaxDeclaration::class)->findOneBy(['company' => $company, 'anafUploadId' => $index]);
            if ($decl !== null) {
                if ($decl->getDosar() !== null) {
                    $doc->setDosar($decl->getDosar());
                }
                $this->noteRecipisa($decl, $doc);
            }

            return;
        }
        if ($doc->getIdSolicitare() !== null) {
            $req = $this->em->getRepository(SpvRequest::class)->findOneBy(['company' => $company, 'anafRequestId' => $doc->getIdSolicitare()]);
            if ($req?->getDosar() !== null) {
                $doc->setDosar($req->getDosar());
            }
        }
    }

    /** Upload index from a RECIPISA message ("… numar_inregistrare INTERNT-1216782129-2026/04-09-2026 …"). */
    public function recipisaIndex(SpvDocument $doc): ?string
    {
        if ($doc->getCategory() !== SpvDocumentCategory::RECIPISA) {
            return null;
        }

        return preg_match('/INTERNT-(\d+)-\d{4}/', (string) $doc->getDetails(), $m) ? $m[1] : null;
    }

    /** The recipisa arrived: the declaration is no longer "in processing" from the user's point of view. */
    private function noteRecipisa(TaxDeclaration $decl, SpvDocument $doc): void
    {
        $meta = $decl->getMetadata() ?? [];
        $meta['recipisaDocumentId'] = $doc->getId()?->toRfc4122();
        $meta['recipisaAt'] = ($doc->getAnafCreatedAt() ?? new \DateTimeImmutable())->format(DATE_ATOM);
        $decl->setMetadata($meta);
        $dosar = $decl->getDosar();
        if ($dosar !== null && $dosar->getStatus() === Dosar::STATUS_ACTIVE) {
            $dosar->setNextStep('Recipisa a sosit: verifică rezultatul în mesajul ANAF.')->touch();
        }
    }

    public function attachDeclaration(Dosar $dosar, TaxDeclaration $decl): void
    {
        $decl->setDosar($dosar);
        // recipisas already archived for this filing follow it into the dosar
        if ($decl->getAnafUploadId() !== null) {
            foreach ($this->em->getRepository(SpvDocument::class)->findBy(['company' => $dosar->getCompany(), 'category' => SpvDocumentCategory::RECIPISA]) as $doc) {
                if ($this->recipisaIndex($doc) === $decl->getAnafUploadId()) {
                    $doc->setDosar($dosar);
                }
            }
        }
        $dosar->touch();
    }

    public function attachRequest(Dosar $dosar, SpvRequest $req): void
    {
        $req->setDosar($dosar);
        $req->getAnswerDocument()?->setDosar($dosar);
        $dosar->touch();
    }

    public function attachDocument(Dosar $dosar, SpvDocument $doc): void
    {
        $doc->setDosar($dosar);
        $dosar->touch();
    }

    // ── Feeds ──────────────────────────────────────────────────────────

    /**
     * What the person should look at: things to fix, things ANAF is still processing, fresh answers.
     * @return array{todo: list<array<string, mixed>>, inProgress: list<array<string, mixed>>, answers: list<array<string, mixed>>}
     */
    public function actions(Company $company): array
    {
        $todo = [];
        $inProgress = [];
        $answers = [];
        $today = new \DateTimeImmutable('today');

        foreach ($this->dosare->findForCompany($company) as $dosar) {
            if ($dosar->getStatus() === Dosar::STATUS_CLOSED) {
                continue;
            }
            $days = $dosar->getDaysToDeadline();
            $expiry = $this->contractExpiryDays($dosar);
            if ($expiry !== null && $expiry <= 60 && $expiry >= -30) {
                $todo[] = $this->item('expiry', $dosar->getId(), $dosar->getTitle(), $expiry < 0 ? sprintf('Contractul a expirat de %d zile: prelungește (act adițional) sau declară încetarea (C168) în 30 de zile', -$expiry) : ($expiry === 0 ? 'Contractul expiră astăzi' : sprintf('Contractul expiră în %d zile: prelungire sau încetare?', $expiry)), $dosar, $this->date($dosar->getSubject()['panaLa'] ?? null), $expiry <= 7 ? 'high' : 'normal');
            }
            if ($dosar->getStatus() === Dosar::STATUS_ATTENTION) {
                $todo[] = $this->item('dosar', $dosar->getId(), $dosar->getTitle(), $dosar->getNextStep() ?? 'Necesită o decizie', $dosar, $dosar->getUpdatedAt(), 'high');
            } elseif ($days !== null && $days <= 14) {
                $todo[] = $this->item('deadline', $dosar->getId(), $dosar->getTitle(), sprintf('%s — %s', $dosar->getDeadlineLabel() ?? 'termen', $days < 0 ? sprintf('depășit cu %d zile', -$days) : ($days === 0 ? 'astăzi' : sprintf('în %d zile', $days))), $dosar, $dosar->getDeadlineAt(), $days <= 1 ? 'critical' : 'high');
            }
        }

        /** @var TaxDeclaration $decl */
        foreach ($this->em->getRepository(TaxDeclaration::class)->findBy(['company' => $company], ['createdAt' => 'DESC'], 200) as $decl) {
            $title = sprintf('%s %s', strtoupper($decl->getType()->value), $decl->getPeriodType() === 'annual' ? $decl->getYear() : sprintf('%02d.%d', $decl->getMonth(), $decl->getYear()));
            switch ($decl->getStatus()) {
                case DeclarationStatus::REJECTED:
                case DeclarationStatus::ERROR:
                    $todo[] = $this->item('declaration', $decl->getId(), $title . ' — respinsă', mb_substr((string) ($decl->getErrorMessage() ?? 'Vezi recipisa'), 0, 200), $decl->getDosar(), $decl->getUpdatedAt() ?? $decl->getCreatedAt(), 'high');
                    break;
                case DeclarationStatus::SUBMITTED:
                case DeclarationStatus::PROCESSING:
                    $inProgress[] = $this->item('declaration', $decl->getId(), $title, 'În prelucrare la ANAF' . ($decl->getAnafUploadId() ? ' · index ' . $decl->getAnafUploadId() : ''), $decl->getDosar(), $decl->getSubmittedAt() ?? $decl->getCreatedAt(), 'normal');
                    break;
                default:
            }
        }

        /** @var SpvRequest $req */
        foreach ($this->em->getRepository(SpvRequest::class)->findBy(['company' => $company], ['createdAt' => 'DESC'], 100) as $req) {
            $title = $req->getTitle() ?? $req->getRequestType();
            if ($req->getStatus() === SpvRequest::STATUS_ERROR) {
                $todo[] = $this->item('request', $req->getId(), $title . ' — eroare', mb_substr((string) $req->getErrorMessage(), 0, 200), $req->getDosar(), $req->getCreatedAt(), 'high');
            } elseif (in_array($req->getStatus(), [SpvRequest::STATUS_PENDING, SpvRequest::STATUS_REQUESTED], true)) {
                $age = (int) $req->getCreatedAt()->diff($today)->format('%a');
                $inProgress[] = $this->item('request', $req->getId(), $title, $age >= 3 ? sprintf('Fără răspuns de %d zile', $age) : 'Așteaptă răspunsul ANAF', $req->getDosar(), $req->getCreatedAt(), $age >= 3 ? 'high' : 'normal');
            }
        }

        /** @var SpvDocument $doc */
        foreach ($this->em->getRepository(SpvDocument::class)->findBy(['company' => $company], ['anafCreatedAt' => 'DESC'], 60) as $doc) {
            $cat = $doc->getCategory();
            $recent = $doc->getAnafCreatedAt() !== null && $doc->getAnafCreatedAt() >= $today->modify('-14 days');
            if (in_array($cat, [SpvDocumentCategory::SOMATIE, SpvDocumentCategory::DECIZIE, SpvDocumentCategory::ANALIZA_RISC], true) && $doc->getReadAt() === null) {
                $todo[] = $this->item('document', $doc->getId(), $doc->getMessageType(), mb_substr((string) ($doc->getSummary() ?? $doc->getDetails()), 0, 200), $doc->getDosar(), $doc->getAnafCreatedAt(), $cat === SpvDocumentCategory::SOMATIE ? 'critical' : 'high');
            } elseif ($recent && in_array($cat, [SpvDocumentCategory::RECIPISA, SpvDocumentCategory::RASPUNS, SpvDocumentCategory::CERTIFICAT, SpvDocumentCategory::REGISTRU], true)) {
                $answers[] = $this->item('document', $doc->getId(), $doc->getMessageType(), mb_substr((string) ($doc->getSummary() ?? $doc->getDetails()), 0, 200), $doc->getDosar(), $doc->getAnafCreatedAt(), $doc->getReadAt() === null ? 'normal' : 'low');
            }
        }

        $order = ['critical' => 0, 'high' => 1, 'normal' => 2, 'low' => 3];
        usort($todo, fn ($a, $b) => [$order[$a['severity']], $b['date']] <=> [$order[$b['severity']], $a['date']]);

        return ['todo' => $todo, 'inProgress' => $inProgress, 'answers' => $answers];
    }

    /** @return array<string, mixed> */
    private function item(string $kind, mixed $id, string $title, string $subtitle, ?Dosar $dosar, mixed $date, string $severity): array
    {
        return [
            'kind' => $kind,
            'id' => (string) $id,
            'title' => $title,
            'subtitle' => $subtitle,
            'dosarId' => $dosar?->getId()?->toRfc4122(),
            'dosarTitle' => $dosar?->getTitle(),
            'date' => $date instanceof \DateTimeInterface ? $date->format(DATE_ATOM) : null,
            'severity' => $severity,
        ];
    }

    /** Chronological events of a dosar, newest first. @return list<array<string, mixed>> */
    public function timeline(Dosar $dosar): array
    {
        $events = [];
        foreach ($this->em->getRepository(TaxDeclaration::class)->findBy(['dosar' => $dosar]) as $d) {
            $label = sprintf('%s %s', strtoupper($d->getType()->value), $d->getPeriodType() === 'annual' ? $d->getYear() : sprintf('%02d.%d', $d->getMonth(), $d->getYear()));
            $events[] = ['date' => $d->getCreatedAt(), 'kind' => 'declaration', 'id' => (string) $d->getId(), 'title' => $label . ' creată', 'status' => $d->getStatus()->value];
            if ($d->getSubmittedAt() !== null) {
                $events[] = ['date' => $d->getSubmittedAt(), 'kind' => 'declaration', 'id' => (string) $d->getId(), 'title' => $label . ' depusă' . ($d->getAnafUploadId() ? ' · index ' . $d->getAnafUploadId() : ''), 'status' => $d->getStatus()->value];
            }
            if ($d->getAcceptedAt() !== null) {
                $events[] = ['date' => $d->getAcceptedAt(), 'kind' => 'declaration', 'id' => (string) $d->getId(), 'title' => $label . ' acceptată de ANAF', 'status' => 'accepted'];
            }
        }
        foreach ($this->em->getRepository(SpvRequest::class)->findBy(['dosar' => $dosar]) as $r) {
            $events[] = ['date' => $r->getCreatedAt(), 'kind' => 'request', 'id' => (string) $r->getId(), 'title' => 'Solicitare: ' . ($r->getTitle() ?? $r->getRequestType()), 'status' => $r->getStatus()];
            if ($r->getAnsweredAt() !== null) {
                $events[] = ['date' => $r->getAnsweredAt(), 'kind' => 'request', 'id' => (string) $r->getId(), 'title' => 'Răspuns primit: ' . ($r->getTitle() ?? $r->getRequestType()), 'status' => 'answered'];
            }
        }
        foreach ($this->em->getRepository(SpvDocument::class)->findBy(['dosar' => $dosar]) as $doc) {
            $events[] = ['date' => $doc->getAnafCreatedAt() ?? $doc->getCreatedAt(), 'kind' => 'document', 'id' => (string) $doc->getId(), 'title' => $doc->getMessageType() . ': ' . mb_substr((string) ($doc->getSummary() ?? $doc->getDetails()), 0, 140), 'status' => $doc->getCategory()->value];
        }
        usort($events, fn ($a, $b) => $b['date'] <=> $a['date']);

        return array_map(fn ($e) => ['date' => $e['date']->format(DATE_ATOM)] + $e, $events);
    }

    // ── Declarația unică ───────────────────────────────────────────────

    /** The filing year whose 25 May deadline is next (this year until 25 May, next year after). */
    public function nextFilingYear(?\DateTimeImmutable $today = null): int
    {
        $today ??= new \DateTimeImmutable('today');
        $y = (int) $today->format('Y');

        return $today <= new \DateTimeImmutable($y . '-' . self::D212_DEADLINE_MONTH_DAY) ? $y : $y + 1;
    }

    /** One "Declarația unică <an>" dosar per filing year, with the 25 May deadline, for taxpayers who rent out property. */
    public function ensureAnnualReturnDosar(Company $company, int $filingYear, ?User $user = null): Dosar
    {
        $existing = $this->dosare->findOneAnnualReturn($company, $filingYear);
        if ($existing !== null) {
            return $existing;
        }
        $dosar = (new Dosar())
            ->setCompany($company)
            ->setType(Dosar::TYPE_ANNUAL_RETURN)
            ->setTitle(sprintf('Declarația unică %d (venituri %d)', $filingYear, $filingYear - 1))
            ->setSubject(['an' => $filingYear])
            ->setDeadlineAt(new \DateTimeImmutable($filingYear . '-' . self::D212_DEADLINE_MONTH_DAY))
            ->setDeadlineLabel('D212: termen 25 mai')
            ->setNextStep('Construiește D212 din contractele de închiriere și depune-o.')
            ->setCreatedBy($user);
        $this->em->persist($dosar);
        $this->em->flush();

        return $dosar;
    }

    /**
     * Prefill for the D212 rent scenario from the rental-contract dosare of the company:
     * one entry per contract active in the income year, gross rent = monthly rent × months
     * (RON only; other currencies must be converted by the user at the BNR rates of each payment).
     * @return array<string, mixed> input for the D212 form + notes about what to check
     */
    public function prefillD212(Company $company, int $filingYear): array
    {
        $incomeYear = $filingYear - 1;
        $start = new \DateTimeImmutable($incomeYear . '-01-01');
        $end = new \DateTimeImmutable($incomeYear . '-12-31');
        $chirii = [];
        $notes = [];
        foreach ($this->dosare->findForCompany($company, Dosar::TYPE_RENTAL_CONTRACT) as $dosar) {
            $s = $dosar->getSubject();
            $deLa = $this->date($s['deLa'] ?? $s['data'] ?? null);
            $panaLa = $this->date($s['dataIncetare'] ?? $s['panaLa'] ?? null);
            $from = $deLa === null || $deLa < $start ? $start : $deLa;
            $to = $panaLa === null || $panaLa > $end ? $end : $panaLa;
            if ($from > $to || ($deLa !== null && $deLa > $end) || ($panaLa !== null && $panaLa < $start)) {
                continue;
            }
            $months = ($to->format('Y') - $from->format('Y')) * 12 + ((int) $to->format('n') - (int) $from->format('n')) + 1;
            $chirie = isset($s['chirie']) && is_numeric($s['chirie']) ? (float) $s['chirie'] : 0.0;
            $moneda = strtoupper((string) ($s['moneda'] ?? 'RON'));
            $brut = $moneda === 'RON' ? (int) round($chirie * $months) : 0;
            if ($moneda !== 'RON') {
                $notes[] = sprintf('%s: chiria este în %s (%s/lună × %d luni); completează venitBrut în lei la cursul BNR din ziua fiecărei încasări.', $dosar->getTitle(), $moneda, $chirie, $months);
            }
            $chirii[] = [
                'numarContract' => (string) ($s['numar'] ?? ''),
                'dataContract' => (string) ($s['data'] ?? ''),
                'adresaBun' => (string) ($s['adresa'] ?? $dosar->getTitle()),
                'deLa' => $from->format('d.m.Y'),
                'panaLa' => $to->format('d.m.Y'),
                'venitBrut' => $brut,
                'dosarId' => $dosar->getId()?->toRfc4122(),
            ];
        }
        $nume = (string) ($company->getName() ?? '');
        $address = method_exists($company, 'getAddress') ? (string) ($company->getAddress() ?? '') : '';

        return [
            'input' => [
                'an' => $filingYear,
                'contribuabil' => ['nume' => $nume, 'cnp' => (string) $company->getCif(), 'adresa' => $address],
                'chirii' => $chirii,
            ],
            'notes' => $notes,
        ];
    }

    /** Days until the contract end (panaLa) of a rental dosar that has not been terminated; null otherwise. */
    public function contractExpiryDays(Dosar $dosar): ?int
    {
        if ($dosar->getType() !== Dosar::TYPE_RENTAL_CONTRACT || $dosar->getStatus() === Dosar::STATUS_CLOSED) {
            return null;
        }
        $s = $dosar->getSubject();
        if (!empty($s['dataIncetare'])) {
            return null;
        }
        $end = $this->date($s['panaLa'] ?? null);
        if ($end === null) {
            return null;
        }

        return (int) (new \DateTimeImmutable('today'))->diff($end->setTime(0, 0))->format('%r%a');
    }

    // ── Portfolio statistics ───────────────────────────────────────────

    /**
     * The rental portfolio of a company: every property with its contract, the monthly
     * rent by currency, what the D212s declared per year versus what the contracts imply.
     * @return array<string, mixed>
     */
    public function stats(Company $company): array
    {
        $today = new \DateTimeImmutable('today');
        $properties = [];
        $monthly = [];
        $active = 0;
        $expiring = 0;
        $expected = [];
        foreach ($this->dosare->findForCompany($company, Dosar::TYPE_RENTAL_CONTRACT) as $dosar) {
            $s = $dosar->getSubject();
            $start = $this->date($s['deLa'] ?? $s['data'] ?? null);
            $end = $this->date($s['dataIncetare'] ?? $s['panaLa'] ?? null);
            $isActive = $dosar->getStatus() !== Dosar::STATUS_CLOSED && empty($s['dataIncetare']) && ($end === null || $end >= $today) && ($start === null || $start <= $today);
            $chirie = isset($s['chirie']) && is_numeric($s['chirie']) ? (float) $s['chirie'] : 0.0;
            $moneda = strtoupper((string) ($s['moneda'] ?? 'RON'));
            $expiry = $this->contractExpiryDays($dosar);
            if ($isActive) {
                $active++;
                $monthly[$moneda] = ($monthly[$moneda] ?? 0) + $chirie;
            }
            if ($expiry !== null && $expiry >= 0 && $expiry <= 60) {
                $expiring++;
            }
            // expected gross rent per income year from the contract terms (RON only)
            if ($chirie > 0 && $start !== null) {
                $last = $end ?? $today;
                for ($y = (int) $start->format('Y'); $y <= (int) $last->format('Y'); $y++) {
                    $from = max($start, new \DateTimeImmutable($y . '-01-01'));
                    $to = min($last, new \DateTimeImmutable($y . '-12-31'));
                    if ($from > $to) {
                        continue;
                    }
                    $months = ((int) $to->format('n') - (int) $from->format('n')) + 1;
                    $expected[$y][$moneda] = ($expected[$y][$moneda] ?? 0) + $chirie * $months;
                }
            }
            $properties[] = [
                'dosarId' => $dosar->getId()?->toRfc4122(),
                'title' => $dosar->getTitle(),
                'adresa' => $s['adresa'] ?? null,
                'chirias' => $s['chirias'] ?? null,
                'chirie' => $chirie,
                'moneda' => $moneda,
                'deLa' => $start?->format('Y-m-d'),
                'panaLa' => $end?->format('Y-m-d'),
                'active' => $isActive,
                'expiresInDays' => $expiry,
                'status' => $dosar->getStatus(),
                'declarations' => $this->em->getRepository(TaxDeclaration::class)->count(['dosar' => $dosar]),
            ];
        }
        // declared: D212 rent income per filing year (from the form input, accepted or not)
        $declared = [];
        foreach ($this->em->getRepository(TaxDeclaration::class)->findBy(['company' => $company, 'type' => DeclarationType::D212]) as $d) {
            $input = $d->getData()['input'] ?? [];
            $sum = 0;
            foreach (is_array($input['chirii'] ?? null) ? $input['chirii'] : [] as $c) {
                $sum += (int) ($c['venitBrut'] ?? 0);
            }
            $incomeYear = $d->getYear() - 1;
            $declared[$incomeYear] = ['venitBrut' => ($declared[$incomeYear]['venitBrut'] ?? 0) + $sum, 'status' => $d->getStatus()->value, 'declarationId' => (string) $d->getId()];
        }
        ksort($expected);
        ksort($declared);

        return [
            'properties' => $properties,
            'activeContracts' => $active,
            'expiringWithin60Days' => $expiring,
            'monthlyRent' => $monthly,
            'expectedGrossByYear' => $expected,
            'declaredByIncomeYear' => $declared,
        ];
    }

    // ── Documents from a dosar ─────────────────────────────────────────

    /**
     * Prefill for the legal document generator (convenție de încetare, declarație pe propria
     * răspundere) from the rental dosar and the company; $overrides come from the user.
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public function documentInput(Dosar $dosar, string $type, array $overrides = []): array
    {
        $s = $dosar->getSubject();
        $company = $dosar->getCompany();
        $locator = ['nume' => (string) ($company?->getName() ?? ''), 'adresa' => trim(implode(', ', array_filter([$company?->getAddress(), $company?->getCity()]))), 'cnp' => (string) ($company?->getCif() ?? '')];
        $locatar = ['nume' => (string) ($s['chirias'] ?? ''), 'adresa' => (string) ($s['chiriasAdresa'] ?? ''), 'cnp' => (string) ($s['chiriasCif'] ?? '')];
        $contract = [
            'numar' => (string) ($s['numar'] ?? ''),
            'data' => $this->roDate($s['data'] ?? null),
            'adresa_imobil' => (string) ($s['adresa'] ?? ''),
            'data_inceput' => $this->roDate($s['deLa'] ?? $s['data'] ?? null),
            'data_sfarsit' => $this->roDate($s['panaLa'] ?? null),
            'chirie' => $s['chirie'] ?? null,
            'valuta' => $s['moneda'] ?? 'RON',
            'numar_inregistrare_anaf' => $s['numarInregistrareAnaf'] ?? null,
            'data_inregistrare_anaf' => $this->roDate($s['dataInregistrareAnaf'] ?? null),
        ];
        $base = [
            'locator' => $locator,
            'locatar' => $locatar,
            'contract' => $contract,
            'data_incetare' => $this->roDate($s['dataIncetare'] ?? $s['panaLa'] ?? null),
        ];
        if ($type === 'declaratie_incetare_contract') {
            $base['motiv'] = 'la termen';
        }
        if ($type === 'act_aditional_inchiriere') {
            $base['act'] = ['numar' => $s['modificareNumar'] ?? null, 'data' => $this->roDate($s['dataModificare'] ?? null)];
            $base['prelungire'] = ['data_inceput' => null, 'data_sfarsit' => $this->roDate($s['modificarePanaLa'] ?? null)];
            $base['chirie_noua'] = ['suma' => $s['modificareChirie'] ?? null, 'valuta' => $s['moneda'] ?? 'EUR', 'de_la' => $this->roDate($s['modificareDeLa'] ?? null)];
        }
        if ($type === 'notificare_incetare_inchiriere') {
            $base['preaviz_zile'] = $s['preavizZile'] ?? null;
        }

        return array_replace_recursive($base, $overrides);
    }

    // ── C168 from a dosar ──────────────────────────────────────────────

    /**
     * Input for the C168 form builder from a rental dosar and its company: the designated
     * landlord (the company), the contract, the property and the tenant. Coded addresses
     * (county / locality / street codes from the nomenclator) live in the subject as
     * `adresaCod` (property), `chiriasAdresaCod` (tenant) and `locatorAdresaCod` (landlord);
     * what is missing shows up as build issues for the user to fill in.
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public function c168Input(Dosar $dosar, string $actiune, array $overrides = []): array
    {
        $s = $dosar->getSubject();
        $company = $dosar->getCompany();
        $name = trim((string) ($company?->getName() ?? ''));
        $rep = method_exists($company, 'getRepresentative') ? trim((string) ($company->getRepresentative() ?? '')) : '';
        $parts = preg_split('/\s+/', $rep !== '' ? $rep : $name) ?: [];
        $declarant = ['nume' => $parts[0] ?? '', 'prenume' => implode(' ', array_slice($parts, 1)) ?: ($parts[0] ?? ''), 'calitate' => $rep !== '' && $rep !== $name ? 'Împuternicit' : 'Locator'];
        $locatorAdresa = is_array($s['locatorAdresaCod'] ?? null) ? $s['locatorAdresaCod'] : ['tara' => 'RO'];
        $contract = [
            'actiune' => $actiune,
            'cotaVenit' => $s['cotaVenit'] ?? 100,
            'numar' => (string) ($s['numar'] ?? ''),
            'data' => (string) ($s['data'] ?? ''),
            'deLa' => (string) ($s['deLa'] ?? $s['data'] ?? ''),
            'panaLa' => (string) ($s['panaLa'] ?? ''),
            'bun' => ['tip' => 'imobil', 'adresa' => is_array($s['adresaCod'] ?? null) ? $s['adresaCod'] : ['tara' => 'RO', 'detalii' => (string) ($s['adresa'] ?? '')]],
            'chirie' => ['suma' => $s['chirie'] ?? null, 'moneda' => $s['moneda'] ?? 'RON'],
            'locatari' => [[
                'denumire' => (string) ($s['chirias'] ?? ''),
                'cif' => (string) ($s['chiriasCif'] ?? ''),
                'adresa' => is_array($s['chiriasAdresaCod'] ?? null) ? $s['chiriasAdresaCod'] : ['tara' => 'RO'],
            ]],
        ];
        if ($actiune === 'incetare') {
            $when = (string) ($s['dataIncetare'] ?? $s['panaLa'] ?? '');
            $contract['incetare'] = ['numar' => (string) ($s['incetareNumar'] ?? $s['numar'] ?? ''), 'data' => (string) ($s['incetareData'] ?? $s['data'] ?? ''), 'deLa' => $when, 'panaLa' => $when, 'motiv' => (string) ($s['incetareMotiv'] ?? 'Încetare la termen')];
        }
        if ($actiune === 'modificare') {
            $contract['modificare'] = ['numar' => (string) ($s['modificareNumar'] ?? ''), 'data' => (string) ($s['dataModificare'] ?? ''), 'deLa' => (string) ($s['modificareDeLa'] ?? ''), 'panaLa' => (string) ($s['modificarePanaLa'] ?? ''), 'chirie' => ['suma' => $s['modificareChirie'] ?? $s['chirie'] ?? null, 'moneda' => $s['moneda'] ?? 'RON']];
        }
        $input = [
            'an' => (int) date('Y'),
            'declarant' => $declarant,
            'locator' => ['tip' => (int) ($s['locatorTip'] ?? 1), 'denumire' => $name, 'cif' => (string) ($company?->getCif() ?? ''), 'adresa' => $locatorAdresa, 'email' => method_exists($company, 'getEmail') ? $company->getEmail() : null],
            'contracte' => [$contract],
        ];

        return array_replace_recursive($input, $overrides);
    }

    /** Remember the reviewed C168 input in the dosar so the next filing starts from it. */
    public function rememberC168Input(Dosar $dosar, array $input): void
    {
        $s = $dosar->getSubject();
        $c = $input['contracte'][0] ?? [];
        foreach ([['locatorAdresaCod', $input['locator']['adresa'] ?? null], ['adresaCod', $c['bun']['adresa'] ?? null], ['chiriasAdresaCod', $c['locatari'][0]['adresa'] ?? null]] as [$key, $val]) {
            if (is_array($val) && $val !== []) {
                $s[$key] = $val;
            }
        }
        foreach ([['chiriasCif', $c['locatari'][0]['cif'] ?? null], ['chirias', $c['locatari'][0]['denumire'] ?? null], ['numar', $c['numar'] ?? null], ['data', $c['data'] ?? null], ['deLa', $c['deLa'] ?? null], ['panaLa', $c['panaLa'] ?? null]] as [$key, $val]) {
            if (is_string($val) && trim($val) !== '') {
                $s[$key] = $val;
            }
        }
        if (($c['actiune'] ?? '') === 'incetare' && !empty($c['incetare']['deLa'])) {
            $s['dataIncetare'] = $c['incetare']['deLa'];
            $s['incetareNumar'] = $c['incetare']['numar'] ?? null;
            $s['incetareData'] = $c['incetare']['data'] ?? null;
            $s['incetareMotiv'] = $c['incetare']['motiv'] ?? null;
        }
        if (($c['actiune'] ?? '') === 'modificare' && !empty($c['modificare'])) {
            $s['dataModificare'] = $c['modificare']['data'] ?? null;
            $s['modificareNumar'] = $c['modificare']['numar'] ?? null;
            $s['modificareDeLa'] = $c['modificare']['deLa'] ?? null;
            $s['modificarePanaLa'] = $c['modificare']['panaLa'] ?? null;
            $s['modificareChirie'] = $c['modificare']['chirie']['suma'] ?? null;
        }
        $dosar->setSubject($s);
        $this->setContractDeadline($dosar);
        $dosar->touch();
    }

    private function roDate(mixed $v): ?string
    {
        $d = $this->date($v);

        return $d?->format('d.m.Y');
    }

    private function date(mixed $v): ?\DateTimeImmutable
    {
        if (!is_string($v) || trim($v) === '') {
            return null;
        }
        $v = trim($v);
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $v, $m)) {
            $v = sprintf('%s-%02d-%02d', $m[3], (int) $m[2], (int) $m[1]);
        }
        try {
            return new \DateTimeImmutable($v);
        } catch (\Throwable) {
            return null;
        }
    }
}
