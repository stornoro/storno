<?php

declare(strict_types=1);

namespace App\Service\Spv;

use App\Enum\SpvDocumentCategory;

/**
 * Turns an ANAF SPV message ("RECIPISA" + "recipisa pentru CIF 12345678, tip D406,
 * numar_inregistrare INTERNT-100000123-2026/31-08-2026, perioada raportare 7.2026")
 * into a sentence a business owner understands: what the document is, which
 * declaration and period it refers to, and what, if anything, they must do.
 * Deterministic, no AI: the wording is ANAF's own vocabulary, decoded.
 */
final class SpvDocumentSummarizer
{
    /** Declaration codes as they appear in `tip Dxxx` and in message types. */
    public const DECLARATIONS = [
        'D010' => 'Declarația de înregistrare fiscală (persoane juridice)',
        'D020' => 'Declarația de înregistrare fiscală (persoane fizice)',
        'D070' => 'Declarația de înregistrare fiscală (PFA, II, IF)',
        'D100' => 'Declarația privind obligațiile de plată la bugetul de stat (impozite și taxe)',
        'D101' => 'Declarația privind impozitul pe profit',
        'D106' => 'Declarația informativă privind dividendele',
        'D112' => 'Declarația privind contribuțiile sociale, impozitul pe venit și evidența angajaților',
        'D120' => 'Decontul privind accizele',
        'D130' => 'Decontul privind impozitul la țiței',
        'D177' => 'Cererea de redirecționare a impozitului pe profit / pe venitul microîntreprinderilor',
        'D180' => 'Nota de certificare a declarațiilor fiscale',
        'D200' => 'Declarația privind veniturile realizate din România (persoane fizice)',
        'D204' => 'Declarația anuală de venit pentru asocieri fără personalitate juridică',
        'D205' => 'Declarația informativă privind impozitul reținut la sursă',
        'D208' => 'Declarația informativă privind transferul proprietăților imobiliare',
        'D212' => 'Declarația unică privind impozitul pe venit și contribuțiile sociale (persoane fizice)',
        'D230' => 'Cererea de direcționare a 3,5% din impozitul pe venit',
        'D300' => 'Decontul de TVA',
        'D301' => 'Decontul special de TVA',
        'D307' => 'Declarația privind sumele rezultate din ajustarea TVA',
        'D311' => 'Declarația privind TVA colectată de persoane cu codul de TVA anulat',
        'D390' => 'Declarația recapitulativă privind livrările și achizițiile intracomunitare',
        'D392' => 'Declarația informativă privind livrările de bunuri și prestările de servicii',
        'D393' => 'Declarația informativă privind biletele de transport internațional',
        'D394' => 'Declarația informativă privind livrările și achizițiile pe teritoriul național',
        'D395' => 'Declarația informativă privind livrările de bunuri cu taxare inversă',
        'D406' => 'Fișierul standard de control fiscal SAF-T (D406)',
        'D600' => 'Declarația privind venitul asigurat la sistemul public de pensii',
        'D700' => 'Declarația pentru modificarea vectorului fiscal',
        'D710' => 'Declarația rectificativă',
        'C168' => 'Cererea de înregistrare a contractelor de închiriere',
        'D010/D020/D070' => 'Declarația de înregistrare fiscală',
    ];

    private const MONTHS = [1 => 'ianuarie', 'februarie', 'martie', 'aprilie', 'mai', 'iunie', 'iulie', 'august', 'septembrie', 'octombrie', 'noiembrie', 'decembrie'];

    public function summarize(string $tip, ?string $detalii, ?SpvDocumentCategory $category = null): string
    {
        $tipN = $this->normalize($tip);
        $det = trim((string) $detalii);
        $detN = $this->normalize($det);
        $facts = $this->parseDetails($det);

        // ── Receipts (confirmation of a filing) ──────────────────────────────
        if (str_contains($tipN, 'recipisa') || str_starts_with($detN, 'recipisa')) {
            $what = $this->describeDeclaration($facts['declaration'] ?? null);
            $period = $this->describePeriod($facts['period'] ?? null);
            $s = 'Confirmare de depunere (recipisă)';
            $s .= $what ? ' pentru ' . $what : '';
            $s .= $period ? ', ' . $period : '';
            if (!empty($facts['registration'])) {
                $s .= ', înregistrată la ANAF cu numărul ' . $facts['registration'];
                $s .= !empty($facts['registeredOn']) ? ' în ' . $facts['registeredOn'] : '';
            }
            $s .= '.';
            if (str_contains($tipN, 'trezorerie')) {
                $s .= ' Este recipisa Trezoreriei pentru plata sau documentul transmis.';
            } elseif (str_contains($detN, 'eroare') || str_contains($detN, 'erori')) {
                $s .= ' Atenție: recipisa menționează erori, deci depunerea NU este considerată validă. Deschide PDF-ul și corectează declarația.';
            } else {
                $s .= ' Dacă PDF-ul nu menționează erori, declarația a fost acceptată și nu mai ai nimic de făcut.';
            }

            return $s;
        }

        // ── Enforcement ──────────────────────────────────────────────────────
        if (str_contains($tipN, 'somatii') || str_contains($tipN, 'somatie')) {
            return 'Somație de plată: ANAF îți cere să plătești obligații restante într-un termen scurt (de regulă 15 zile). Neplata duce la executare silită (popriri pe conturi, sechestre). Verifică suma și termenul din PDF și plătește sau contestă în termen.';
        }
        if (str_contains($detN, 'poprir') || str_contains($tipN, 'poprir')) {
            return 'Poprire: ANAF a înființat sau a ridicat o poprire pe conturile bancare pentru recuperarea unor datorii. Băncile blochează sumele datorate. Verifică imediat situația cu contabilul și cu ANAF.';
        }
        if (str_contains($detN, 'sechestru') || str_contains($detN, 'executare silita')) {
            return 'Act de executare silită: ANAF a început recuperarea forțată a unor datorii (sechestru sau alt act de executare). Verifică urgent PDF-ul și contactează ANAF sau un consultant.';
        }
        if (str_contains($tipN, 'analiza de risc') || str_contains($tipN, 'analiza risc')) {
            return 'Raport de analiză de risc: ANAF semnalează neconcordanțe sau riscuri fiscale identificate la firmă (de exemplu între declarații). Poate preceda o notificare de conformare sau un control. Verifică punctele semnalate.';
        }

        // ── Decisions and notices ────────────────────────────────────────────
        if (str_contains($tipN, 'decizie') || str_starts_with($detN, 'decizie')) {
            return $this->describeDecision($tipN . ' ' . $detN);
        }
        if (str_contains($tipN, 'notificare') || str_contains($tipN, 'instiintare') || str_contains($tipN, 'invitatie') || str_contains($tipN, 'informare')) {
            return $this->describeNotice($tipN . ' ' . $detN);
        }
        if (str_contains($tipN, 'adrese') || str_contains($tipN, 'adresa')) {
            return 'Adresă oficială de la ANAF' . ($det !== '' ? ' (' . $this->shorten($det) . ')' : '') . '. Citește PDF-ul: de obicei cere un răspuns sau documente într-un termen.';
        }

        // ── Answers to our requests / reports ────────────────────────────────
        if (str_contains($tipN, 'raspuns')) {
            return 'Răspunsul ANAF la o solicitare trimisă de tine' . ($det !== '' ? ': ' . $this->shorten($det) : '') . '.';
        }
        if (str_contains($tipN, 'fisa rol') || str_contains($tipN, 'fisa pe platitor')) {
            return 'Fișa pe plătitor (fișa rol): situația detaliată a obligațiilor declarate, plătite și restante, așa cum le vede ANAF. Verifică soldurile restante și eventualele accesorii.';
        }
        if (str_contains($tipN, 'situatie sintetica')) {
            return 'Situația sintetică a datoriilor: totalul obligațiilor restante la ANAF pentru luna anterioară.';
        }
        if (str_contains($tipN, 'obligatii de plata') || str_contains($tipN, 'nota obligatiilor')) {
            return 'Obligațiile de plată neachitate la ANAF, cu sumele și conturile de plată. Poate fi folosită pentru plata online sau la trezorerie.';
        }
        if (str_contains($tipN, 'vector fiscal')) {
            return 'Vectorul fiscal: lista taxelor și declarațiilor la care firma este înregistrată (TVA, impozit pe profit sau micro, contribuții, accize) și periodicitatea lor.';
        }
        if (str_contains($tipN, 'date identificare')) {
            return 'Datele de identificare ale firmei așa cum sunt înregistrate la ANAF (denumire, adresă, stare, cod de TVA).';
        }
        if (str_contains($tipN, 'certificat')) {
            return 'Certificat eliberat de ANAF' . ($det !== '' ? ': ' . $this->shorten($det) : '') . '. Documentul oficial este în PDF.';
        }
        if (str_contains($tipN, 'cazier')) {
            return 'Cazierul fiscal: atestă dacă firma sau persoana are fapte înscrise în cazierul fiscal. Cerut la înființări, licitații, credite.';
        }
        if (str_contains($tipN, 'adeverinta venit')) {
            return 'Adeverință de venit eliberată de ANAF, folosită la instituții (bănci, primării, școli).';
        }
        if (str_contains($tipN, 'istoric declaratii')) {
            return 'Istoricul declarațiilor depuse și valide pentru anul solicitat.';
        }
        if (str_contains($tipN, 'bilant') || str_contains($tipN, 'situatii financiare')) {
            return 'Situații financiare (bilanț) depuse sau raport privind depunerea lor.';
        }

        // ── Declarations echoed back / archives ──────────────────────────────
        if (str_contains($tipN, 'declaratie') || preg_match('/^d\d{3}/', $tipN)) {
            $what = $this->describeDeclaration($facts['declaration'] ?? $this->extractCode($tip));
            $period = $this->describePeriod($facts['period'] ?? null);

            return 'Copie a declarației depuse' . ($what ? ': ' . $what : '') . ($period ? ', ' . $period : '') . '. Document de arhivă, fără acțiune necesară.';
        }
        if (str_contains($tipN, 'facturi arhiva') || str_contains($tipN, 'factur')) {
            return 'Arhivă de facturi electronice (e-Factura) pusă la dispoziție de ANAF. Document de arhivă.';
        }
        if (str_contains($tipN, 'extras de cont') || str_contains($tipN, 'extras cont')) {
            return 'Extras de cont de la Trezorerie: încasările și plățile pe contul fiscal.';
        }
        if (str_contains($tipN, 'plata')) {
            return 'Confirmare de plată către ANAF / Trezorerie.';
        }
        if (str_contains($tipN, 'ajutor de stat')) {
            return 'Document privind un ajutor de stat sau o schemă de sprijin la care firma a aplicat.';
        }
        if (str_contains($tipN, 'tezaur')) {
            return 'Document din Programul Tezaur (titluri de stat pentru populație).';
        }

        // ── Fallback by category ─────────────────────────────────────────────
        return match ($category) {
            SpvDocumentCategory::SOMATIE => 'Somație de plată de la ANAF. Verifică suma și termenul din PDF.',
            SpvDocumentCategory::DECIZIE => $this->describeDecision($detN),
            SpvDocumentCategory::NOTIFICARE => $this->describeNotice($detN),
            SpvDocumentCategory::RECIPISA => 'Confirmare de depunere (recipisă) pentru o declarație.',
            default => ($det !== '' ? $this->shorten($det) : 'Document ANAF de tip ' . $tip) . '. Deschide PDF-ul pentru detalii.',
        };
    }

    /** @return array{declaration?: string, period?: string, registration?: string, registeredOn?: string, cif?: string} */
    public function parseDetails(string $details): array
    {
        $facts = [];
        if (preg_match('/\btip\s+([A-Z]\d{3,4})\b/i', $details, $m)) {
            $facts['declaration'] = strtoupper($m[1]);
        }
        if (preg_match('/perioada\s+raportare\s+([0-9.\/-]+)/i', $details, $m)) {
            $facts['period'] = $m[1];
        }
        if (preg_match('/numar_inregistrare\s+([A-Z0-9-]+)(?:\/(\d{2}-\d{2}-\d{4}))?/i', $details, $m)) {
            $facts['registration'] = $m[1];
            if (isset($m[2])) {
                $facts['registeredOn'] = str_replace('-', '.', $m[2]);
            }
        }
        if (preg_match('/\bCIF\s+(\d{2,13})\b/i', $details, $m)) {
            $facts['cif'] = $m[1];
        }

        return $facts;
    }

    private function describeDeclaration(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }
        $code = strtoupper($code);
        $name = self::DECLARATIONS[$code] ?? null;

        return $name ? sprintf('%s (%s)', $name, $code) : sprintf('declarația %s', $code);
    }

    /** "7.2026" → "luna iulie 2026"; "2025" → "anul 2025"; "T2.2026" → "trimestrul 2 din 2026". */
    private function describePeriod(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        if (preg_match('/^(\d{1,2})\.(\d{4})$/', $raw, $m)) {
            $month = (int) $m[1];
            if ($month >= 1 && $month <= 12) {
                return 'luna ' . self::MONTHS[$month] . ' ' . $m[2];
            }
        }
        if (preg_match('/^(\d{4})$/', $raw, $m)) {
            return 'anul ' . $m[1];
        }
        if (preg_match('/^T(\d)\.?(\d{4})$/i', $raw, $m)) {
            return 'trimestrul ' . $m[1] . ' din ' . $m[2];
        }
        if (preg_match('/^S(\d)\.?(\d{4})$/i', $raw, $m)) {
            return 'semestrul ' . $m[1] . ' din ' . $m[2];
        }

        return 'perioada ' . $raw;
    }

    private function describeDecision(string $text): string
    {
        $t = $this->normalize($text);
        return match (true) {
            str_contains($t, 'inactivare') => 'Decizie de declarare ca inactiv fiscal: firma pierde dreptul de deducere a TVA și partenerii nu pot deduce facturile ei. De obicei vine după declarații nedepuse sau sediu expirat. Remediază cauza și cere reactivarea.',
            str_contains($t, 'reactivare') => 'Decizie de reactivare: firma redevine activă fiscal.',
            str_contains($t, 'anulare') && str_contains($t, 'tva') => 'Decizie de anulare a codului de TVA: firma nu mai poate factura cu TVA și nu mai deduce TVA. Verifică motivul din PDF (de regulă declarații lipsă sau risc fiscal) și, dacă este cazul, cere reînregistrarea.',
            str_contains($t, 'anulare') => 'Decizie de anulare emisă de ANAF. Citește PDF-ul pentru ce anume s-a anulat și ce trebuie făcut.',
            str_contains($t, 'inregistrare') && str_contains($t, 'tva') => 'Decizie privind înregistrarea în scopuri de TVA (aprobare, respingere sau înregistrare din oficiu). Verifică data de la care se aplică.',
            str_contains($t, 'respingere') => 'Decizie de respingere a unei cereri depuse de firmă. PDF-ul conține motivul.',
            str_contains($t, 'impunere') => 'Decizie de impunere: ANAF a stabilit sume de plată (impozite, contribuții, accesorii). Verifică sumele și termenul de plată; poate fi contestată în 45 de zile.',
            str_contains($t, 'accesorii') || str_contains($t, 'dobanzi') || str_contains($t, 'penalitati') => 'Decizie privind dobânzi și penalități de întârziere calculate pentru plăți întârziate.',
            str_contains($t, 'rambursare') => 'Decizie privind rambursarea de TVA.',
            str_contains($t, 'esalonare') => 'Decizie privind eșalonarea la plată a datoriilor.',
            str_contains($t, 'domiciliu fiscal') || str_contains($t, 'dom. fiscal') => 'Decizie privind domiciliul fiscal al firmei.',
            str_contains($t, 'grup') => 'Decizie privind grupul fiscal (impozit pe profit sau TVA).',
            str_contains($t, 'registru') => 'Decizie privind înscrierea sau radierea din registrul entităților / unităților de cult (sponsorizări).',
            default => 'Decizie emisă de ANAF. Produce efecte juridice și poate fi contestată în termen: citește PDF-ul și verifică ce trebuie făcut.',
        };
    }

    private function describeNotice(string $text): string
    {
        $t = $this->normalize($text);
        return match (true) {
            str_contains($t, 'conformare') => 'Notificare de conformare: ANAF a găsit neconcordanțe și te invită să le corectezi (declarații rectificative, plăți) într-un termen, înainte de un control.',
            str_contains($t, 'nedepunere') || str_contains($t, 'nu ati depus') || str_contains($t, 'declaratii nedepuse') => 'Notificare privind declarații nedepuse: depune declarațiile lipsă cât mai repede pentru a evita amenzi și inactivarea.',
            str_contains($t, 'inregistrarea, din oficiu') || str_contains($t, 'din oficiu') => 'Notificare privind înregistrarea sau anularea din oficiu în scopuri de TVA. Verifică termenul de răspuns.',
            str_contains($t, 'radiere') => 'Înștiințare privind radierea (de exemplu din registrul TVA intracomunitar).',
            str_contains($t, 'documente suplimentare') => 'Înștiințare că ANAF cere documente suplimentare pentru o cerere depusă. Trimite-le în termenul indicat.',
            str_contains($t, 'redirectionare') => 'Notificare privind cererea de redirecționare a impozitului (sponsorizări).',
            str_contains($t, 'sesizare') => 'Răspuns la o sesizare sau reclamație trimisă către ANAF.',
            str_contains($t, 'invitatie') => 'Invitație la sediul ANAF sau la un control. Verifică data și ce documente trebuie aduse.',
            str_contains($t, 'control') || str_contains($t, 'inspectie') => 'Aviz sau notificare privind o inspecție fiscală. Pregătește documentele contabile pentru perioada vizată.',
            default => 'Notificare de la ANAF: informativă sau cu termen de răspuns. Citește PDF-ul.',
        };
    }

    private function extractCode(string $text): ?string
    {
        return preg_match('/\b([CD]\d{3,4})\b/i', $text, $m) ? strtoupper($m[1]) : null;
    }

    private function shorten(string $text): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);

        return mb_strlen($text) > 160 ? mb_substr($text, 0, 157) . '…' : $text;
    }

    private function normalize(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = strtr($s, ['ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't']);

        return preg_replace('/\s+/', ' ', $s) ?? $s;
    }
}
