<?php

declare(strict_types=1);

namespace App\Service\Spv;

/**
 * What can be requested from ANAF SPV through `GET /SPVWS2/rest/cerere` and with
 * which parameters. Sources: ANAF's ClientSPV README (parameter rules, exact
 * wording of `tip` and of the income-certificate reasons) and the type list of
 * the SPV web form. ANAF answers `{id_solicitare, titlu}` and the document lands
 * in listaMesaje with the same `id_solicitare`, where the inbox sync archives it.
 *
 * Parameter codes: an (year), luna (month 1-12), motiv (income certificate reason,
 * exact text), numar_inregistrare (recipisa registration number), cui_pui (branch
 * CUI, fisa rol), lunai/lunas (month range, D394 mismatches).
 */
final class SpvRequestCatalog
{
    public const ANAF_CERERE_URL = 'https://webserviced.anaf.ro/SPVWS2/rest/cerere';

    /** @var array<string, array{group: string, label: string, params: list<string>, optional?: list<string>, note?: string}> */
    private const TYPES = [
        // ── Rapoarte despre contribuabil ───────────────────────────────────
        'DATE IDENTIFICARE' => ['group' => 'rapoarte', 'label' => 'Date de identificare (din baza ANAF)', 'params' => []],
        'VECTOR FISCAL' => ['group' => 'rapoarte', 'label' => 'Vector fiscal', 'params' => []],
        'Situatie Sintetica' => ['group' => 'rapoarte', 'label' => 'Situatie sintetica a debitelor (luna anterioara)', 'params' => []],
        'Obligatii de plata' => ['group' => 'rapoarte', 'label' => 'Obligatii fiscale de plata neachitate', 'params' => []],
        'Nota obligatiilor de plata' => ['group' => 'rapoarte', 'label' => 'Nota obligatiilor de plata (pentru trezorerie / plata online)', 'params' => []],
        'Fisa Rol' => ['group' => 'rapoarte', 'label' => 'Fisa pe platitor (fisa rol)', 'params' => [], 'optional' => ['cui_pui']],
        'Fisa Rol Completa' => ['group' => 'rapoarte', 'label' => 'Fisa rol completa', 'params' => [], 'optional' => ['cui_pui']],
        'Fisa Rol Simplificata' => ['group' => 'rapoarte', 'label' => 'Fisa rol simplificata', 'params' => [], 'optional' => ['cui_pui']],
        'Rezumat Fisa Rol cu XLS atasat' => ['group' => 'rapoarte', 'label' => 'Rezumat fisa rol cu XLS atasat', 'params' => [], 'optional' => ['cui_pui']],
        'Istoric declaratii' => ['group' => 'rapoarte', 'label' => 'Istoric declaratii depuse (pe an)', 'params' => ['an']],
        'Istoric bilant' => ['group' => 'rapoarte', 'label' => 'Istoricul situatiilor financiare', 'params' => []],
        'Bilant anual' => ['group' => 'rapoarte', 'label' => 'Situatii financiare anuale', 'params' => ['an']],
        'Bilant semestrial' => ['group' => 'rapoarte', 'label' => 'Raportari financiare semestriale', 'params' => ['an']],
        'Istoric Spatiu Virtual' => ['group' => 'rapoarte', 'label' => 'Istoric activitati SPV (profil, descarcari)', 'params' => []],
        'Registru intrari-iesiri' => ['group' => 'rapoarte', 'label' => 'Registru intrari-iesiri documente SPV', 'params' => []],
        'InterogariBanci' => ['group' => 'rapoarte', 'label' => 'Interogari ale bancilor privind veniturile (PF)', 'params' => []],
        'Reprezentanti SPV' => ['group' => 'rapoarte', 'label' => 'Reprezentanti / imputerniciti in SPV', 'params' => []],
        'D112Contrib' => ['group' => 'rapoarte', 'label' => 'Contributii sociale declarate de angajatori (D112)', 'params' => []],
        'NeconcordanteD112CNP' => ['group' => 'rapoarte', 'label' => 'Neconcordante D112 - REVISAL', 'params' => []],
        'Neconcordante D394' => ['group' => 'rapoarte', 'label' => 'Neconcordante D394 (interval de luni)', 'params' => ['an', 'lunai', 'lunas']],
        'NeconcordanteD394' => ['group' => 'rapoarte', 'label' => 'Neconcordante D394 (interval de luni, cod WS)', 'params' => ['an', 'lunai', 'lunas']],

        // ── Duplicate / certificate ────────────────────────────────────────
        'Duplicat Recipisa' => ['group' => 'documente', 'label' => 'Duplicat recipisa (dupa numarul de inregistrare)', 'params' => ['numar_inregistrare']],
        'Adeverinte Venit' => ['group' => 'documente', 'label' => 'Adeverinta de venit (PF)', 'params' => ['an', 'motiv']],
        'Certificat' => ['group' => 'documente', 'label' => 'Certificat', 'params' => [], 'optional' => ['an']],
        'Certificat TVA' => ['group' => 'documente', 'label' => 'Certificat TVA', 'params' => [], 'optional' => ['an']],
        'Certificat inregistrare fiscala' => ['group' => 'documente', 'label' => 'Certificat de inregistrare fiscala', 'params' => []],
        'Certificat de rezidenta fiscala, pentru persoane juridice rezidente in Romania' => ['group' => 'documente', 'label' => 'Certificat de rezidenta fiscala (PJ)', 'params' => [], 'optional' => ['an']],
        'Certificat de rezidenta fiscala, pentru persoane rezidente in Romania' => ['group' => 'documente', 'label' => 'Certificat de rezidenta fiscala (PF)', 'params' => [], 'optional' => ['an']],
        'Certificat atestare activitate sediu permanent/desemnat PJ străine în România' => ['group' => 'documente', 'label' => 'Certificat atestare activitate sediu permanent (PJ straine)', 'params' => [], 'optional' => ['an']],
        'Certificat privind atestarea impozitului plătit în RO de persoane juridice străine' => ['group' => 'documente', 'label' => 'Certificat atestare impozit platit in RO (PJ straine)', 'params' => [], 'optional' => ['an']],

        // ── Copii dupa declaratiile depuse ─────────────────────────────────
        'D100' => ['group' => 'declaratii', 'label' => 'D100 / D710 - obligatii la bugetul de stat', 'params' => ['an', 'luna']],
        'D101' => ['group' => 'declaratii', 'label' => 'D101 - impozit pe profit', 'params' => ['an']],
        'D106' => ['group' => 'declaratii', 'label' => 'D106 - dividende', 'params' => ['an']],
        'D112' => ['group' => 'declaratii', 'label' => 'D112 - contributii sociale', 'params' => ['an', 'luna']],
        'D120' => ['group' => 'declaratii', 'label' => 'D120 - accize', 'params' => ['an']],
        'D130' => ['group' => 'declaratii', 'label' => 'D130 - titei', 'params' => ['an']],
        'D180' => ['group' => 'declaratii', 'label' => 'D180 - nota de certificare', 'params' => ['an', 'luna']],
        'D205' => ['group' => 'declaratii', 'label' => 'D205 - impozit retinut la sursa', 'params' => ['an']],
        'D208' => ['group' => 'declaratii', 'label' => 'D208 - transfer proprietati (luna 6 sau 12)', 'params' => ['an', 'luna']],
        'D212' => ['group' => 'declaratii', 'label' => 'D212 - ultimele declaratii unice depuse (PF)', 'params' => ['an']],
        'D300' => ['group' => 'declaratii', 'label' => 'D300 - decont TVA (include D305)', 'params' => ['an', 'luna']],
        'D301' => ['group' => 'declaratii', 'label' => 'D301 - decont special TVA', 'params' => ['an', 'luna']],
        'D311' => ['group' => 'declaratii', 'label' => 'D311 - TVA colectata (cod anulat)', 'params' => ['an', 'luna']],
        'D390' => ['group' => 'declaratii', 'label' => 'D390 - livrari/achizitii intracomunitare', 'params' => ['an', 'luna']],
        'D392' => ['group' => 'declaratii', 'label' => 'D392 - livrari de bunuri si servicii', 'params' => ['an']],
        'D393' => ['group' => 'declaratii', 'label' => 'D393 - bilete transport international', 'params' => ['an']],
        'D394' => ['group' => 'declaratii', 'label' => 'D394 - livrari/achizitii nationale', 'params' => ['an', 'luna']],
        'C168' => ['group' => 'declaratii', 'label' => 'C168 - contracte de locatiune inregistrate', 'params' => [], 'optional' => ['an']],
        'Decont pe taxa de valoare adaugata (D300) proiect pilot SAFT' => ['group' => 'declaratii', 'label' => 'D300 proiect pilot SAF-T', 'params' => [], 'optional' => ['an', 'luna']],
    ];

    /**
     * Types the SPV *web form* offers but the web service rejects with "tip raport= … necunoscut"
     * (verified live: C168, Reprezentanti SPV, Certificat inregistrare fiscala). Kept in the catalog
     * so the UI can say "only from the SPV website" instead of failing at ANAF.
     */
    private const WEB_ONLY = [
        'C168', 'Reprezentanti SPV', 'Certificat', 'Certificat TVA', 'Certificat inregistrare fiscala',
        'Certificat de rezidenta fiscala, pentru persoane juridice rezidente in Romania',
        'Certificat de rezidenta fiscala, pentru persoane rezidente in Romania',
        'Certificat atestare activitate sediu permanent/desemnat PJ străine în România',
        'Certificat privind atestarea impozitului plătit în RO de persoane juridice străine',
        'Decont pe taxa de valoare adaugata (D300) proiect pilot SAFT',
    ];

    /** Types offered by the SPV web form that are decisions/notices ANAF issues; requestable as copies, no parameters known. */
    private const NOTICE_TYPES = [
        'Decizie 178 aprobare/respingere privind desemnarea reprezentantului', 'Decizie Grup Profit (174)', 'Decizie Grup Profit (175)',
        'Decizie anulare', 'Decizie anulare TVA', 'Decizie anulare inregistrare în scop de TVA sau a regimului special pentru agricultori',
        'Decizie cerere registru entitati', 'Decizie dom. fiscal PJ', 'Decizie inactivare', 'Decizie inregistrare TVA',
        'Decizie inregistrare din oficiu in scop de TVA', 'Decizie modificare per. fisc. TVA', 'Decizie privind anularea din oficiu a inregistrarii in scopuri de TVA',
        'Decizie radiere TVAI', 'Decizie radiere registrul entitati', 'Decizie reactivare inactivi', 'Decizie reinregistrare TVA',
        'Decizie respingere cerere inregistrare TVA', 'Decizie respingere inactivi',
        'Instiintare privind sistarea inregistrarii, din oficiu, in scopuri de TVA', 'Instiintare radiere TVAI',
        'Invitatie privind inregistrarea, din oficiu, in scopuri de TVA', 'Inștiințare pentru depunere documente suplimentare',
        'NOTIFICARE privind comunicarea datelor de identificare a contribuabililor', 'NOTIFICARE privind contul bancar al entităţii beneficiare',
        'NOTIFICARE privind erorile identificate în cererea de redirecţionare', 'NOTIFICARE privind modul de soluţionare a cererii de redirecţionare a impozit profit',
        'Notificare', 'Notificare Grup Profit', 'Notificare hotărâre judecătorească', 'Notificare modificare per. fisc. TVA',
        'Notificare neconcordanță inactivi', 'Notificare privind inregistrarea, din oficiu, in scop de TVA', 'Notificare rezidenta Z019',
        'Notificarea solutionarii cererii de reinregistrare TVA',
    ];


    /** First year with data, from the SPV web form ("Perioada de la care sunt disponibile date"). */
    private const SINCE = [
        'Bilant anual' => 2011, 'Bilant semestrial' => 2012, 'C168' => 2019, 'Certificat' => 2025, 'Certificat TVA' => 2025,
        'Certificat atestare activitate sediu permanent/desemnat PJ străine în România' => 2025,
        'Certificat de rezidenta fiscala, pentru persoane juridice rezidente in Romania' => 2025,
        'Certificat de rezidenta fiscala, pentru persoane rezidente in Romania' => 2025, 'Certificat inregistrare fiscala' => 2026,
        'Certificat privind atestarea impozitului plătit în RO de persoane juridice străine' => 2025,
        'D100' => 2011, 'D101' => 2011, 'D106' => 2013, 'D112' => 2011, 'D120' => 2011, 'D130' => 2011, 'D180' => 2011, 'D205' => 2012,
        'D208' => 2012, 'D300' => 2011, 'D301' => 2011, 'D311' => 2012, 'D390' => 2011, 'D392' => 2011, 'D393' => 2011, 'D394' => 2011,
        'Duplicat Recipisa' => 2011, 'Istoric bilant' => 2011, 'Istoric declaratii' => 2011, 'Neconcordante D394' => 2016, 'NeconcordanteD394' => 2016,
        'Reprezentanti SPV' => 2014, 'Decizie Grup Profit (174)' => 2022, 'Decizie Grup Profit (175)' => 2022, 'Decizie anulare' => 2025,
        'Decizie anulare TVA' => 2022, 'Decizie cerere registru entitati' => 2022, 'Decizie dom. fiscal PJ' => 2023, 'Decizie inactivare' => 2025,
        'Decizie inregistrare TVA' => 2022, 'Decizie inregistrare din oficiu in scop de TVA' => 2023, 'Decizie modificare per. fisc. TVA' => 2023,
        'Decizie privind anularea din oficiu a inregistrarii in scopuri de TVA' => 2023, 'Decizie radiere TVAI' => 2023,
        'Decizie radiere registrul entitati' => 2022, 'Decizie reactivare inactivi' => 2025, 'Decizie reinregistrare TVA' => 2022,
        'Decizie respingere cerere inregistrare TVA' => 2022, 'Decizie respingere inactivi' => 2025,
        'Decont pe taxa de valoare adaugata (D300) proiect pilot SAFT' => 2025, 'Notificare Grup Profit' => 2022,
        'Notificare privind inregistrarea, din oficiu, in scop de TVA' => 2022, 'Notificare modificare per. fisc. TVA' => 2023,
        'Notificarea solutionarii cererii de reinregistrare TVA' => 2023, 'Instiintare radiere TVAI' => 2023,
    ];

    /** Observations from the SPV web form worth showing next to the field. */
    private const NOTES = [
        'Bilant anual' => 'Luna se alege automat = 12.',
        'Bilant semestrial' => 'Se alege automat luna = 6.',
        'Istoric declaratii' => 'D100, D101, D102, D103, D112, D120, D130, D300, D301, D390, D394, D710, D205 valide pentru anul ales; D205 din 2012.',
        'Istoric bilant' => 'Se introduce doar CUI-ul.',
        'D101' => 'Pentru anul 2010: T3 → luna 9, T4 → luna 11, an → luna 12.',
        'D208' => 'Semestriala: luna 6 pentru semestrul 1, luna 12 pentru semestrul 2.',
        'D390' => 'Pentru 2007-2009 declaratia a fost trimestriala: luna 3, 6, 9 sau 12.',
        'D394' => 'Inainte de 01.2012 a fost semestriala: luna 6 sau 12.',
        'D300' => 'Include si declaratia 305.',
        'D100' => 'Include D100 si D710 valide pentru CUI si perioada.',
        'Situatie Sintetica' => 'Raportul se genereaza pana pe 10 ale lunii, pentru luna anterioara.',
        'Duplicat Recipisa' => 'Raport generat la data solicitarii, nu recipisa originala.',
        'Fisa Rol Simplificata' => 'Doar obligatiile neachitate.',
        'Rezumat Fisa Rol cu XLS atasat' => 'Rezumat in PDF, situatia detaliata in XLS atasat la PDF.',
        'Reprezentanti SPV' => 'Drepturile de reprezentare in SPV si de depunere declaratii pentru persoana juridica.',
        'C168' => 'Duplicat dupa cererea de inregistrare a contractelor de locatiune.',
    ];

    /** Exact wording ANAF checks for `motiv` on "Adeverinte Venit". */
    public const INCOME_CERTIFICATE_REASONS = [
        'Sanatate', 'Cresa', 'Gradinita', 'Scoala', 'Liceu', 'Facultate', 'Alocatia pentru copiii nou nascuti', 'Trusou nou nascuti',
        'Alocatia de stat pentru copii', 'Indemnizatie ajutor stimulent pentru cresterea copilului', 'Sprijin financiar acordat la constituirea familiei',
        'Alocatia pentru sustinerea familiei', 'Alocatia familiala complementara', 'Somaj si stimularea fortei de munca', 'Ajutor social', 'Pensie',
        'Stimulent de insertie', 'Ajutoare pentru incalzirea locuintei', 'Ajutoare financiare pentru persoane aflate in extrema dificultate',
        'Cheltuieli cu inmormantarea persoanelor din familiile beneficiare de ajutor social', 'Ajutoare de urgenta in caz de calamitati naturale',
        'Indemnizatia Bugetul personal complementar pentru persoana cu handicap', 'Alocatia de plasament', 'Indemnizatia pentru insotitor',
        'Alocatia lunara de hrana pentru copiii cu handicap de tip HIV SIDA', 'Ajutor anual pentru veteranii de razboi',
        'Institutie financiar bancara asigurare etc.', 'Executor judecatoresc', 'Autoritati straine', 'Altele',
    ];

    /** @return list<array{type: string, group: string, label: string, params: list<string>, optional: list<string>, since: ?int, note: ?string}> */
    public function types(): array
    {
        $out = [];
        foreach (self::TYPES as $type => $def) {
            $out[] = ['type' => $type, 'group' => $def['group'], 'label' => $def['label'], 'params' => $def['params'], 'optional' => $def['optional'] ?? [], 'since' => self::SINCE[$type] ?? null, 'note' => self::NOTES[$type] ?? null, 'wsSupported' => $this->isWsSupported($type)];
        }
        foreach (self::NOTICE_TYPES as $type) {
            $out[] = ['type' => $type, 'group' => 'decizii', 'label' => $type, 'params' => [], 'optional' => ['an'], 'since' => self::SINCE[$type] ?? null, 'note' => null, 'wsSupported' => false];
        }

        return $out;
    }

    /** @return list<string> */
    public function incomeCertificateReasons(): array
    {
        return self::INCOME_CERTIFICATE_REASONS;
    }

    public function has(string $type): bool
    {
        return isset(self::TYPES[$type]) || in_array($type, self::NOTICE_TYPES, true);
    }

    /** Whether ANAF's web service (`cerere`) accepts the type; the rest exist only in the SPV website form. */
    public function isWsSupported(string $type): bool
    {
        return isset(self::TYPES[$type]) && !in_array($type, self::WEB_ONLY, true);
    }

    public const SPV_WEB_URL = 'https://www.anaf.ro/anaf/internet/ANAF/servicii_online/spv/';

    /**
     * Validate the parameters for a type and build the ANAF URL.
     *
     * @param array<string, mixed> $params
     * @return array{url: string, params: array<string, string>}
     * @throws \InvalidArgumentException with a user-facing Romanian message
     */
    public function buildRequest(string $type, string $cif, array $params): array
    {
        $type = trim($type);
        if (!$this->has($type)) {
            throw new \InvalidArgumentException(sprintf('Tip de solicitare necunoscut: "%s".', $type));
        }
        if (!$this->isWsSupported($type)) {
            throw new \InvalidArgumentException(sprintf('"%s" nu poate fi solicitat prin serviciul web ANAF (raspunde "tip raport necunoscut"); se cere doar din formularul SPV de pe anaf.ro.', $type));
        }
        $def = self::TYPES[$type] ?? ['params' => [], 'optional' => ['an']];
        $required = $def['params'];
        $allowed = array_merge($required, $def['optional'] ?? []);

        $clean = [];
        foreach ($allowed as $name) {
            $value = $params[$name] ?? null;
            if ($value === null || $value === '') {
                if (in_array($name, $required, true)) {
                    throw new \InvalidArgumentException(sprintf('Parametrul "%s" este obligatoriu pentru "%s".', $name, $type));
                }
                continue;
            }
            $clean[$name] = $this->validateParam($name, $value, $type);
        }

        $since = self::SINCE[$type] ?? null;
        if ($since !== null && isset($clean['an']) && (int) $clean['an'] < $since) {
            throw new \InvalidArgumentException(sprintf('Pentru "%s" ANAF are date incepand cu anul %d.', $type, $since));
        }
        if (isset($clean['an'], $clean['luna'])) {
            $an = (int) $clean['an'];
            $luna = (int) $clean['luna'];
            if ($type === 'D390' && $an <= 2009 && !in_array($luna, [3, 6, 9, 12], true)) {
                throw new \InvalidArgumentException('Pentru D390 in 2007-2009 luna trebuie sa fie 3, 6, 9 sau 12 (declaratie trimestriala).');
            }
            if ($type === 'D394' && $an < 2012 && !in_array($luna, [6, 12], true)) {
                throw new \InvalidArgumentException('Pentru D394 inainte de 2012 luna trebuie sa fie 6 sau 12 (declaratie semestriala).');
            }
        }
        if (isset($clean['lunai'], $clean['lunas']) && (int) $clean['lunai'] > (int) $clean['lunas']) {
            throw new \InvalidArgumentException('Luna de inceput trebuie sa fie inainte de luna de sfarsit.');
        }

        $query = ['tip' => $type, 'cui' => preg_replace('/\D/', '', $cif) ?? ''];
        if ($query['cui'] === '') {
            throw new \InvalidArgumentException('Compania nu are CUI.');
        }
        $query += $clean;

        return ['url' => self::ANAF_CERERE_URL . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986), 'params' => $clean];
    }

    private function validateParam(string $name, mixed $value, string $type): string
    {
        $s = trim((string) $value);
        switch ($name) {
            case 'an':
                if (!preg_match('/^(19|20)\d{2}$/', $s)) {
                    throw new \InvalidArgumentException('Anul trebuie sa aiba forma AAAA.');
                }
                return $s;
            case 'luna':
            case 'lunai':
            case 'lunas':
                $m = (int) $s;
                if ($m < 1 || $m > 12) {
                    throw new \InvalidArgumentException('Luna trebuie sa fie intre 1 si 12.');
                }
                if ($type === 'D208' && !in_array($m, [6, 12], true)) {
                    throw new \InvalidArgumentException('Pentru D208 luna este 6 (semestrul 1) sau 12 (semestrul 2).');
                }
                return (string) $m;
            case 'motiv':
                if (!in_array($s, self::INCOME_CERTIFICATE_REASONS, true)) {
                    throw new \InvalidArgumentException('Motivul adeverintei de venit trebuie ales exact din lista ANAF.');
                }
                return $s;
            case 'numar_inregistrare':
                if (!preg_match('/^[A-Za-z0-9\-\/]{4,64}$/', $s)) {
                    throw new \InvalidArgumentException('Numarul de inregistrare are forma INTERNT-140000000-2018.');
                }
                return $s;
            case 'cui_pui':
                $d = preg_replace('/\D/', '', $s) ?? '';
                if ($d === '' || strlen($d) > 13) {
                    throw new \InvalidArgumentException('CUI-ul punctului de lucru este invalid.');
                }
                return $d;
        }

        throw new \InvalidArgumentException(sprintf('Parametru necunoscut: %s', $name));
    }
}
