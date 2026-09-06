<?php

declare(strict_types=1);

namespace App\Service\Declaration\Forms;

/**
 * C168 — "Cerere de înregistrare a contractelor de locațiune" (OPANAF 114/2019, schema v3):
 * a landlord (locator) registers, amends or terminates a rental contract at ANAF.
 *
 * Input is a plain JSON structure (declarant, designated landlord, contracts with
 * tenants and co-owners); the builder writes the ANAF XML (namespace
 * mfp:anaf:dgti:c168:declaratie:v3) and applies the rules learned from real filings:
 * the DUKIntegrator validator's own checks plus the stricter rules ANAF's web form
 * (anaf.ro/declaratii/c168) enforces (BR-C168-…), which the DUK jar does not.
 */
final class C168Form implements DeclarationFormInterface
{
    public const NAMESPACE = 'mfp:anaf:dgti:c168:declaratie:v3';
    private const XSD = __DIR__ . '/../../../../resources/declarations/c168_17022025.xsd';

    private const TIP_LOCATOR = ['1' => 'Proprietar', '2' => 'Uzufructuar', '3' => 'Alt deținător legal'];
    private const ACTIUNI = ['inregistrare', 'modificare', 'incetare'];

    /** Romanian counties as ANAF codes (SIRUTA-like; 40 = București, 51 = Călărași, 52 = Giurgiu). */
    private const JUDETE = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 28, 29, 30, 31, 32, 33, 34, 35, 36, 37, 38, 39, 40, 41, 42, 43, 44, 45, 46, 51, 52];

    public function type(): string
    {
        return 'C168';
    }

    public function summary(): array
    {
        return [
            'type' => 'C168',
            'title' => 'C168 — Cerere de înregistrare a contractelor de locațiune',
            'titleEn' => 'C168 — Registration of rental (lease) contracts',
            'description' => 'Înregistrarea, modificarea sau încetarea unui contract de închiriere la ANAF de către locator (proprietar). Necesită atașarea contractului scanat (zip).',
            'descriptionEn' => 'A landlord registers, amends or terminates a rental contract at ANAF. The scanned contract must be attached as a zip.',
        ];
    }

    public function spec(): array
    {
        $adresa = [
            'tara' => ['type' => 'string', 'default' => 'RO', 'hint' => 'ISO 3166-1 alpha-2. RO → address in Romania (RS=1) and the coded fields below are required; anything else → abroad (RS=2), free text.'],
            'judet' => ['type' => 'string', 'requiredWhen' => 'tara=RO', 'hint' => 'County code from anaf_nomenclator_judete (e.g. 40 = București)'],
            'localitate' => ['type' => 'string', 'requiredWhen' => 'tara=RO', 'hint' => 'Locality code from anaf_nomenclator_localitati(judet)'],
            'localitateNume' => ['type' => 'string', 'requiredWhen' => 'tara=RO', 'hint' => 'Locality name as returned by the nomenclator (e.g. "6 Sector - Mun. București")'],
            'strada' => ['type' => 'string', 'requiredWhen' => 'tara=RO', 'hint' => 'Street code from anaf_nomenclator_strazi(judet, localitate)'],
            'stradaNume' => ['type' => 'string', 'requiredWhen' => 'tara=RO', 'hint' => 'Street name as returned by the nomenclator'],
            'numar' => ['type' => 'string', 'required' => true, 'maxLength' => 10, 'hint' => 'Street number; "FN" when there is none'],
            'detalii' => ['type' => 'string', 'maxLength' => 200, 'hint' => 'Bloc/scară/etaj/apartament, e.g. "bl. 1, sc. A, et. 2, ap. 16"'],
            'codPostal' => ['type' => 'string', 'maxLength' => 6, 'hint' => 'Postal code. Required for the rented property (BR-C168-0041) and recommended everywhere else.'],
        ];
        $persoana = fn (string $rol) => [
            'denumire' => ['type' => 'string', 'required' => true, 'maxLength' => 200, 'hint' => "Nume și prenume ($rol persoană fizică) sau denumirea (persoană juridică)"],
            'cif' => ['type' => 'string', 'pattern' => '[1-9]\\d{12} (CNP) | [1-9]\\d{1,9} (CUI/NIF)', 'hint' => 'CNP for natural persons, CUI for companies. Never invent or pad it: ANAF rejects placeholders and a wrong identifier is a false declaration.'],
            'adresa' => ['type' => 'object', 'fields' => $adresa],
            'telefon' => ['type' => 'string', 'hint' => 'digits only'],
            'email' => ['type' => 'string', 'maxLength' => 200],
        ];

        return [
            'type' => 'C168',
            'title' => $this->summary()['title'],
            'titleEn' => $this->summary()['titleEn'],
            'legalBasis' => 'OPANAF 114/2019 (contractele de locațiune încheiate de persoane fizice se înregistrează la ANAF în 30 de zile de la încheiere/modificare/încetare; art. 120 Cod fiscal pentru veniturile din chirii).',
            'xml' => [
                'root' => 'c168',
                'namespace' => self::NAMESPACE,
                'xsd' => 'c168_17022025.xsd (ANAF, 17.02.2025), bundled: GET /api/v1/public/declarations/forms/C168?xsd=1',
                'period' => 'an = year of the request, luna is always 12',
                'structure' => '<c168 …declarant + designated landlord…><contract …one per contract…><locatar …tenant(s)…/><locator …owner(s)…/></contract></c168>',
                'attributes' => $this->xsdAttributes(),
            ],
            'input' => [
                'an' => ['type' => 'integer', 'required' => true, 'hint' => 'Year of the request (2023–2100)'],
                'rectificativa' => ['type' => 'boolean', 'default' => false, 'hint' => 'true for a corrective request (d_rec=1)'],
                'declarant' => [
                    'type' => 'object', 'required' => true,
                    'fields' => [
                        'nume' => ['type' => 'string', 'required' => true, 'maxLength' => 75, 'hint' => 'Last name of the person signing (nume_decl)'],
                        'prenume' => ['type' => 'string', 'required' => true, 'maxLength' => 75],
                        'calitate' => ['type' => 'string', 'required' => true, 'maxLength' => 50, 'default' => 'Locator', 'hint' => 'Capacity: Locator, Proprietar, Împuternicit, Administrator …'],
                    ],
                ],
                'locator' => [
                    'type' => 'object', 'required' => true,
                    'hint' => 'The designated landlord who files the request (section A). Co-owners go in contracte[].locatori.',
                    'fields' => [
                        'tip' => ['type' => 'integer', 'required' => true, 'enum' => self::TIP_LOCATOR, 'default' => 1],
                        'denumire' => $persoana('locator')['denumire'],
                        'cif' => $persoana('locator')['cif'] + ['required' => true],
                        'adresa' => ['type' => 'object', 'required' => true, 'fields' => $adresa],
                        'telefon' => $persoana('locator')['telefon'],
                        'email' => $persoana('locator')['email'],
                        'organFiscal' => ['type' => 'string', 'hint' => 'Competent fiscal office code (ufisc), 6 digits, ONLY for a NIF (non-resident, cif starting with 9); leave empty for CNP/CUI. Codes from anaf_nomenclator_judete → organe fiscale'],
                    ],
                ],
                'contracte' => [
                    'type' => 'array', 'required' => true, 'minItems' => 1, 'maxItems' => 100,
                    'items' => [
                        'id' => ['type' => 'integer', 'default' => 'position (1, 2, …)'],
                        'actiune' => ['type' => 'string', 'required' => true, 'enum' => ['inregistrare' => 'register a new contract', 'modificare' => 'amend a registered contract (bifa_modif=1, needs modificare{})', 'incetare' => 'terminate a registered contract (bifa_incet=1, needs incetare{})']],
                        'cotaVenit' => ['type' => 'number', 'default' => 100, 'hint' => 'Share of the rent income declared by the designated landlord in % (proc_n4). Must equal the sum of locatori[].cotaVenit (BR-C168-00991).'],
                        'numar' => ['type' => 'string', 'required' => true, 'maxLength' => 20, 'hint' => 'Contract number (nr_I)'],
                        'data' => ['type' => 'date', 'required' => true, 'hint' => 'Contract date, DD.MM.YYYY or YYYY-MM-DD (data_I)'],
                        'deLa' => ['type' => 'date', 'required' => true, 'hint' => 'Start of the rental period (per1_C)'],
                        'panaLa' => ['type' => 'date', 'hint' => 'End of the rental period (per2_C); omit for open-ended contracts'],
                        'bun' => [
                            'type' => 'object', 'required' => true,
                            'fields' => [
                                'tip' => ['type' => 'string', 'required' => true, 'enum' => ['imobil' => 'real estate (bifa_bun=1): adresa required', 'mobil' => 'movable good (bifa_bun=2): descriere required']],
                                'adresa' => ['type' => 'object', 'requiredWhen' => 'tip=imobil', 'fields' => $adresa],
                                'descriere' => ['type' => 'string', 'requiredWhen' => 'tip=mobil', 'maxLength' => 200],
                            ],
                        ],
                        'chirie' => ['type' => 'object', 'required' => true, 'fields' => ['suma' => ['type' => 'integer', 'required' => true, 'hint' => 'Monthly rent, whole units (chirie1)'], 'moneda' => ['type' => 'string', 'required' => true, 'hint' => 'ISO 4217: RON, EUR, USD …']]],
                        'modificare' => ['type' => 'object', 'requiredWhen' => 'actiune=modificare', 'fields' => ['numar' => ['type' => 'string', 'maxLength' => 20, 'hint' => 'Addendum number (nr_M)'], 'data' => ['type' => 'date'], 'deLa' => ['type' => 'date', 'hint' => 'New period start (per1_M)'], 'panaLa' => ['type' => 'date'], 'chirie' => ['type' => 'object', 'fields' => ['suma' => ['type' => 'integer'], 'moneda' => ['type' => 'string']]]]],
                        'incetare' => ['type' => 'object', 'requiredWhen' => 'actiune=incetare', 'fields' => ['numar' => ['type' => 'string', 'required' => true, 'maxLength' => 20, 'hint' => 'Number of the termination document; the contract number when it ended at term (nr_S)'], 'data' => ['type' => 'date', 'required' => true, 'hint' => 'Date of the termination document (data_S)'], 'deLa' => ['type' => 'date', 'required' => true, 'hint' => 'Termination date (per1_S)'], 'panaLa' => ['type' => 'date', 'hint' => 'Usually the same date (per2_S)'], 'motiv' => ['type' => 'string', 'maxLength' => 200, 'hint' => 'Reason: "Încetare la termen", "Reziliere prin acordul părților" …']]],
                        'locatari' => ['type' => 'array', 'required' => true, 'minItems' => 1, 'items' => ['id' => ['type' => 'integer', 'default' => 'position']] + $persoana('locatar') + ['adresa' => ['type' => 'object', 'required' => true, 'fields' => $adresa]]],
                        'locatori' => [
                            'type' => 'array', 'hint' => 'Owners of the good. Omit it and Storno adds the designated landlord with 100 %; list every co-owner when the good is shared.',
                            'items' => ['id' => ['type' => 'integer', 'default' => 'position'], 'desemnat' => ['type' => 'boolean', 'hint' => 'true for the designated landlord (d_decl=1); exactly one per contract'], 'tip' => ['type' => 'integer', 'enum' => self::TIP_LOCATOR, 'default' => 'locator.tip'], 'calitate' => ['type' => 'integer', 'default' => 1, 'hint' => 'calit_locator_P; 1 is what ANAF accepts for owners in real filings'], 'fractie' => ['type' => 'object', 'fields' => ['numarator' => ['type' => 'integer'], 'numitor' => ['type' => 'integer']], 'hint' => 'Ownership fraction (fractie_n1P / fractie_n2P); alternative to cotaBun, never both (R78)'], 'cotaBun' => ['type' => 'number', 'default' => 100, 'hint' => 'Share of the good in % (proc_n3P); omitted when fractie is given'], 'cotaVenit' => ['type' => 'number', 'default' => 100, 'hint' => 'Share of the rent income in % (proc_n4P)'], 'organFiscal' => ['type' => 'string']] + $persoana('locator') + ['adresa' => ['type' => 'object', 'required' => true, 'fields' => $adresa]],
                        ],
                    ],
                ],
            ],
            'rules' => [
                ['code' => 'STORNO-REQ', 'source' => 'Storno', 'message' => 'Required fields must be present; dates are DD.MM.YYYY; CIF/CNP must match the ANAF pattern.'],
                ['code' => 'DUK-ADDRESS', 'source' => 'ANAF DUKIntegrator', 'message' => 'For a Romanian address the county, locality code, street code and number are required (localit/strada names come from the nomenclator).'],
                ['code' => 'DUK-BUN', 'source' => 'ANAF DUKIntegrator', 'message' => 'bifa_bun=1 (imobil) requires the property address; bifa_bun=2 (mobil) requires the description.'],
                ['code' => 'BR-C168-00991', 'source' => 'ANAF web form (also checked online by Storno)', 'message' => 'Procentul calculat trebuie să fie egal cu suma cotelor locatorilor din contract (±0.1): contract.cotaVenit = Σ locatori[].cotaVenit.'],
                ['code' => 'BR-C168-0041', 'source' => 'ANAF web form', 'message' => 'Pentru imobil, trebuie completate toate câmpurile de adresă: județ, localitate, bloc/scară/etaj/ap și cod poștal.'],
                ['code' => 'BR-C168-005911 / G000', 'source' => 'ANAF web form + back-office processing', 'message' => 'The tenant CNP/CIF is mandatory. The portal upload passes DUK without it, but ANAF rejects the request in processing (recipisa: "Sectiunea B DATE DESPRE LOCATAR: CNP/NIF/CIF neprecizat"). Get it from the contract or the tenant; never invent it.'],
                ['code' => 'R_MULTI_C168', 'source' => 'ANAF back-office processing', 'message' => 'Only one C168 per landlord CIF and period (an/luna=12) can be in processing at a time: a second upload is rejected ("Exista deja o declaratie C168 in curs de prelucrare pentru aceasta perioada"). Put every contract to register/amend/terminate in ONE request (contracte[]), or wait for the recipisa of the previous one before filing the next.'],
                ['code' => 'BR-CNP-0002', 'source' => 'ANAF DUKIntegrator + web form', 'message' => 'Every CNP (locator, chiriaș, coproprietar) must have a correct control digit; ANAF rejects invented or padded CNPs.'],
                ['code' => 'BR-C168-0031 / BR-C168-0088 / R77 / R9.2', 'source' => 'ANAF DUKIntegrator + web form', 'message' => 'Organul fiscal competent (ufisc) is filled only for a NIF (13 digits starting with 9, non-residents) and must be empty for a CNP or CUI. Storno drops it otherwise.'],
                ['code' => 'R78', 'source' => 'ANAF DUKIntegrator', 'message' => 'fractie (n1/n2) and cotaBun (proc_n3P) exclude each other; give one of them per co-owner.'],
                ['code' => 'ATTACHMENT', 'source' => 'ANAF', 'message' => 'The PDF must embed a zip with the contract (registration), the addendum (amendment) or the termination document / sworn statement (termination). declaration_pdf builds the zip from the attachments you pass.'],
            ],
            'validation' => [
                'duk' => 'POST /api/v1/public/declarations/forms/C168/build validates the XML with ANAF DUKIntegrator (C168Validator.jar).',
                'anafOnline' => 'The same call sends the XML to ANAF\'s online validator (anaf.ro/declaratii/c168/api/proxy-validare) which applies the BR-C168 rules; result in validation.anafOnline.',
            ],
            'filing' => [
                '1' => 'declaration_build → fix issues until valid=true',
                '2' => 'declaration_pdf with the contract scan (PDF/JPG) as attachment → DUK PDF with embedded XML + zip',
                '3' => 'Storno Agent: agent_submit_declaration_pdf (qualified certificate) → ANAF index; or upload the PDF manually in SPV (persoane fizice: SPV cu user/parolă → Depunere declarații). One C168 per period at a time (R_MULTI_C168): file all contracts together.',
                '4' => 'anaf_declaration_status(index, cif) until ok/nok; the recipisa lands in the SPV inbox (spv_documents_list)',
            ],
            'example' => $this->example(),
        ];
    }

    /** @return array<string, mixed> */
    public function example(): array
    {
        $adresaProprietar = ['tara' => 'RO', 'judet' => '40', 'localitate' => '6', 'localitateNume' => '6 Sector - Mun. Bucuresti', 'strada' => '412', 'stradaNume' => 'Bld. Iuliu Maniu', 'numar' => '7', 'detalii' => 'bl. 1, sc. A, et. 2, ap. 16', 'codPostal' => '061072'];

        return [
            'an' => 2026,
            'declarant' => ['nume' => 'POPESCU', 'prenume' => 'ION', 'calitate' => 'Locator'],
            'locator' => ['tip' => 1, 'denumire' => 'POPESCU ION', 'cif' => '1800101400016', 'adresa' => $adresaProprietar, 'email' => 'ion@example.com'],
            'contracte' => [[
                'actiune' => 'incetare',
                'cotaVenit' => 100,
                'numar' => '2', 'data' => '01.12.2023', 'deLa' => '01.12.2023', 'panaLa' => '01.12.2024',
                'bun' => ['tip' => 'imobil', 'adresa' => $adresaProprietar],
                'chirie' => ['suma' => 450, 'moneda' => 'EUR'],
                'incetare' => ['numar' => '2', 'data' => '01.12.2023', 'deLa' => '01.12.2024', 'panaLa' => '01.12.2024', 'motiv' => 'Încetare la termen - chiriașul nu a continuat contractul'],
                'locatari' => [['denumire' => 'IONESCU MARIA', 'cif' => '2850505400014', 'adresa' => ['tara' => 'RO', 'judet' => '40', 'localitate' => '6', 'localitateNume' => '6 Sector - Mun. Bucuresti', 'strada' => '412', 'stradaNume' => 'Bld. Iuliu Maniu', 'numar' => '9', 'detalii' => 'bl. 2, ap. 4', 'codPostal' => '061072']]],
            ]],
        ];
    }

    public function build(array $input): FormBuildResult
    {
        $issues = [];
        $err = function (string $code, string $field, string $message) use (&$issues): void {
            $issues[] = ['level' => 'error', 'code' => $code, 'field' => $field, 'message' => $message];
        };
        $warn = function (string $code, string $field, string $message) use (&$issues): void {
            $issues[] = ['level' => 'warning', 'code' => $code, 'field' => $field, 'message' => $message];
        };

        $an = (int) ($input['an'] ?? 0);
        if ($an < 2023 || $an > 2100) {
            $err('STORNO-REQ', 'an', 'Anul cererii lipsește sau este în afara intervalului 2023–2100.');
        }
        $declarant = is_array($input['declarant'] ?? null) ? $input['declarant'] : [];
        foreach (['nume', 'prenume'] as $k) {
            if ($this->str($declarant[$k] ?? null) === null) {
                $err('STORNO-REQ', "declarant.$k", "declarant.$k este obligatoriu.");
            }
        }
        $locator = is_array($input['locator'] ?? null) ? $input['locator'] : [];
        if ($this->str($locator['denumire'] ?? null) === null) {
            $err('STORNO-REQ', 'locator.denumire', 'locator.denumire este obligatoriu.');
        }
        $cif = $this->digits($locator['cif'] ?? null);
        if ($cif === null || !$this->cifOk($cif)) {
            $err('STORNO-REQ', 'locator.cif', 'locator.cif lipsește sau nu are formatul CNP (13 cifre) / CUI (2–10 cifre).');
        } elseif (!$this->cnpChecksumOk($cif)) {
            $err('BR-CNP-0002', 'locator.cif', 'CNP-ul are cifră de control greșită.');
        }
        $tipLocator = (string) (int) ($locator['tip'] ?? 1);
        if (!isset(self::TIP_LOCATOR[$tipLocator])) {
            $err('STORNO-REQ', 'locator.tip', 'locator.tip trebuie să fie 1 (Proprietar), 2 (Uzufructuar) sau 3 (Alt deținător legal).');
        }
        $adrL = $this->address($locator['adresa'] ?? null, 'locator.adresa', 'L', $err, true);

        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;
        $root = $doc->createElementNS(self::NAMESPACE, 'c168');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $doc->appendChild($root);

        $this->attrs($root, [
            'an' => (string) $an,
            'luna' => '12',
            'nume_decl' => $this->str($declarant['nume'] ?? null) ?? '',
            'pren_decl' => $this->str($declarant['prenume'] ?? null) ?? '',
            'func_decl' => $this->str($declarant['calitate'] ?? null) ?? 'Locator',
            'd_rec' => !empty($input['rectificativa']) ? '1' : '0',
            'den_L' => $this->str($locator['denumire'] ?? null) ?? '',
            'cif' => $cif ?? '',
            'tip_locator' => $tipLocator,
        ] + $adrL + [
            'tel_L' => $this->digits($locator['telefon'] ?? null),
            'email_L' => $this->str($locator['email'] ?? null),
            'ufisc_L' => $this->ufisc($cif, $locator['organFiscal'] ?? null, 'locator.organFiscal', $err, $warn),
        ]);

        $contracte = is_array($input['contracte'] ?? null) ? array_values($input['contracte']) : [];
        if ($contracte === []) {
            $err('STORNO-REQ', 'contracte', 'Cel puțin un contract este obligatoriu.');
        }
        foreach ($contracte as $i => $c) {
            $p = "contracte[$i]";
            if (!is_array($c)) {
                $err('STORNO-REQ', $p, 'Contractul trebuie să fie un obiect.');
                continue;
            }
            $actiune = strtolower((string) ($c['actiune'] ?? 'inregistrare'));
            if (!in_array($actiune, self::ACTIUNI, true)) {
                $err('STORNO-REQ', "$p.actiune", 'actiune trebuie să fie inregistrare, modificare sau incetare.');
                $actiune = 'inregistrare';
            }
            $el = $doc->createElementNS(self::NAMESPACE, 'contract');
            $root->appendChild($el);

            $cotaVenit = $this->pct($c['cotaVenit'] ?? 100);
            $bun = is_array($c['bun'] ?? null) ? $c['bun'] : [];
            $bunTip = strtolower((string) ($bun['tip'] ?? 'imobil'));
            $adrC = [];
            if ($bunTip === 'imobil') {
                $adrC = $this->address($bun['adresa'] ?? null, "$p.bun.adresa", 'C', $err, true);
                if (($adrC['RS_C'] ?? '1') === '1' && $this->str($adrC['codp_C'] ?? null) === null) {
                    $err('BR-C168-0041', "$p.bun.adresa.codPostal", 'Pentru imobil, trebuie completate toate câmpurile de adresă: județ, localitate, bloc/scară/etaj/ap și cod poștal.');
                }
                unset($adrC['RS_C']);
            } elseif ($bunTip === 'mobil') {
                if ($this->str($bun['descriere'] ?? null) === null) {
                    $err('DUK-BUN', "$p.bun.descriere", 'Pentru un bun mobil descrierea este obligatorie.');
                }
            } else {
                $err('STORNO-REQ', "$p.bun.tip", 'bun.tip trebuie să fie imobil sau mobil.');
            }
            $chirie = is_array($c['chirie'] ?? null) ? $c['chirie'] : [];
            if (!isset($chirie['suma']) || !is_numeric($chirie['suma']) || $this->str($chirie['moneda'] ?? null) === null) {
                $err('STORNO-REQ', "$p.chirie", 'chirie.suma (număr întreg) și chirie.moneda (ISO 4217) sunt obligatorii.');
            }
            foreach (['numar' => 'nr_I', 'data' => 'data_I', 'deLa' => 'per1_C'] as $k => $attr) {
                if ($this->str($c[$k] ?? null) === null) {
                    $err('STORNO-REQ', "$p.$k", "$k este obligatoriu ($attr).");
                }
            }

            $mod = is_array($c['modificare'] ?? null) ? $c['modificare'] : [];
            $inc = is_array($c['incetare'] ?? null) ? $c['incetare'] : [];
            if ($actiune === 'modificare' && $mod === []) {
                $err('STORNO-REQ', "$p.modificare", 'Pentru actiune=modificare completați modificare{numar, data, deLa, panaLa, chirie}.');
            }
            if ($actiune === 'incetare') {
                foreach (['numar', 'data', 'deLa'] as $k) {
                    if ($this->str($inc[$k] ?? null) === null) {
                        $err('STORNO-REQ', "$p.incetare.$k", "incetare.$k este obligatoriu pentru încetare.");
                    }
                }
            }
            $modChirie = is_array($mod['chirie'] ?? null) ? $mod['chirie'] : [];

            $this->attrs($el, [
                'ID_contract' => (string) (int) ($c['id'] ?? ($i + 1)),
                'proc_n4' => $cotaVenit,
                'bifa_modif' => $actiune === 'modificare' ? '1' : '0',
                'bifa_incet' => $actiune === 'incetare' ? '1' : '0',
                'nr_I' => $this->str($c['numar'] ?? null) ?? '',
                'data_I' => $this->date($c['data'] ?? null, "$p.data", $err) ?? '',
                'per1_C' => $this->date($c['deLa'] ?? null, "$p.deLa", $err) ?? '',
                'per2_C' => $this->date($c['panaLa'] ?? null, "$p.panaLa", $err),
                'bifa_bun' => $bunTip === 'mobil' ? '2' : '1',
            ] + $adrC + [
                'mobil' => $bunTip === 'mobil' ? $this->str($bun['descriere'] ?? null) : null,
                'chirie1' => isset($chirie['suma']) && is_numeric($chirie['suma']) ? (string) (int) round((float) $chirie['suma']) : '',
                'moneda1' => strtoupper($this->str($chirie['moneda'] ?? null) ?? ''),
                'nr_M' => $this->str($mod['numar'] ?? null),
                'data_M' => $this->date($mod['data'] ?? null, "$p.modificare.data", $err),
                'per1_M' => $this->date($mod['deLa'] ?? null, "$p.modificare.deLa", $err),
                'per2_M' => $this->date($mod['panaLa'] ?? null, "$p.modificare.panaLa", $err),
                'chirie2' => isset($modChirie['suma']) && is_numeric($modChirie['suma']) ? (string) (int) round((float) $modChirie['suma']) : null,
                'moneda2' => isset($modChirie['moneda']) ? strtoupper((string) $modChirie['moneda']) : null,
                'nr_S' => $this->str($inc['numar'] ?? null),
                'data_S' => $this->date($inc['data'] ?? null, "$p.incetare.data", $err),
                'per1_S' => $this->date($inc['deLa'] ?? null, "$p.incetare.deLa", $err),
                'per2_S' => $this->date($inc['panaLa'] ?? ($inc['deLa'] ?? null), "$p.incetare.panaLa", $err),
                'motiv_S' => $this->str($inc['motiv'] ?? null),
            ]);

            // Tenants
            $locatari = is_array($c['locatari'] ?? null) ? array_values($c['locatari']) : [];
            if ($locatari === []) {
                $err('STORNO-REQ', "$p.locatari", 'Cel puțin un locatar (chiriaș) este obligatoriu.');
            }
            foreach ($locatari as $j => $t) {
                $tp = "$p.locatari[$j]";
                $t = is_array($t) ? $t : [];
                $te = $doc->createElementNS(self::NAMESPACE, 'locatar');
                $el->appendChild($te);
                if ($this->str($t['denumire'] ?? null) === null) {
                    $err('STORNO-REQ', "$tp.denumire", 'Numele/denumirea chiriașului este obligatoriu.');
                }
                $tcif = $this->digits($t['cif'] ?? null);
                if ($tcif !== null && !$this->cifOk($tcif)) {
                    $err('STORNO-REQ', "$tp.cif", 'cif-ul chiriașului nu are formatul CNP/CUI.');
                } elseif ($tcif !== null && !$this->cnpChecksumOk($tcif)) {
                    $err('BR-CNP-0002111', "$tp.cif", 'CNP-ul chiriașului are cifră de control greșită.');
                }
                $adrCh = $this->address($t['adresa'] ?? null, "$tp.adresa", 'Ch', $err, false);
                if ($tcif === null) {
                    // Verified on a real filing (recipisa G000): the portal accepts the upload, then rejects
                    // the request in processing — "CNP/NIF/CIF neprecizat". Without the identifier there is no filing.
                    $err('BR-C168-005911', "$tp.cif", 'CNP-ul/CIF-ul chiriașului este obligatoriu: ANAF respinge cererea la prelucrare (G000 „CNP/NIF/CIF neprecizat”), chiar dacă portalul acceptă încărcarea. Obține CNP-ul din contract sau de la chiriaș; nu îl inventa.');
                }
                $this->attrs($te, [
                    'ID_locatar' => (string) (int) ($t['id'] ?? ($j + 1)),
                    'den_Ch' => $this->str($t['denumire'] ?? null) ?? '',
                    'cif_Ch' => $tcif,
                ] + $adrCh + [
                    'tel_Ch' => $this->digits($t['telefon'] ?? null),
                    'email_Ch' => $this->str($t['email'] ?? null),
                ]);
            }

            // Owners: default to the designated landlord alone
            $locatori = is_array($c['locatori'] ?? null) ? array_values($c['locatori']) : [];
            if ($locatori === []) {
                $locatori = [['desemnat' => true, 'denumire' => $locator['denumire'] ?? null, 'cif' => $locator['cif'] ?? null, 'tip' => $locator['tip'] ?? 1, 'adresa' => $locator['adresa'] ?? null, 'cotaBun' => 100, 'cotaVenit' => $cotaVenit, 'organFiscal' => $locator['organFiscal'] ?? null, 'telefon' => $locator['telefon'] ?? null, 'email' => $locator['email'] ?? null]];
            }
            $sum = 0.0;
            $desemnati = 0;
            foreach ($locatori as $j => $o) {
                $op = "$p.locatori[$j]";
                $o = is_array($o) ? $o : [];
                $oe = $doc->createElementNS(self::NAMESPACE, 'locator');
                $el->appendChild($oe);
                $ocif = $this->digits($o['cif'] ?? null);
                if ($ocif === null || !$this->cifOk($ocif)) {
                    $err('STORNO-REQ', "$op.cif", 'cif-ul locatorului lipsește sau nu are formatul CNP/CUI.');
                } elseif (!$this->cnpChecksumOk($ocif)) {
                    $err('BR-CNP-000211', "$op.cif", 'CNP-ul locatorului are cifră de control greșită.');
                }
                if ($this->str($o['denumire'] ?? null) === null) {
                    $err('STORNO-REQ', "$op.denumire", 'Numele/denumirea locatorului este obligatoriu.');
                }
                $desemnat = !empty($o['desemnat']) || (count($locatori) === 1);
                $desemnati += $desemnat ? 1 : 0;
                $cv = $this->pct($o['cotaVenit'] ?? 100);
                $sum += (float) $cv;
                // R78: the ownership fraction (n1/n2) and the share of the good (%) exclude each other
                $fr = is_array($o['fractie'] ?? null) ? $o['fractie'] : [];
                $hasFractie = isset($fr['numarator'], $fr['numitor']);
                if ($hasFractie && isset($o['cotaBun'])) {
                    $warn('DUK-FRACTIE', "$op.cotaBun", 'fractie și cotaBun se exclud reciproc (R78): a fost păstrată fracția.');
                }
                $adrP = $this->address($o['adresa'] ?? null, "$op.adresa", 'P', $err, false);
                $this->attrs($oe, [
                    'ID_locator' => (string) (int) ($o['id'] ?? ($j + 1)),
                    'd_decl' => $desemnat ? '1' : '0',
                    'den_P' => $this->str($o['denumire'] ?? null) ?? '',
                    'cif_P' => $ocif ?? '',
                    'calit_locator_P' => (string) (int) ($o['calitate'] ?? 1),
                    'tip_locator_P' => (string) (int) ($o['tip'] ?? $tipLocator),
                    'fractie_n1P' => $hasFractie ? (string) (int) $fr['numarator'] : null,
                    'fractie_n2P' => $hasFractie ? (string) (int) $fr['numitor'] : null,
                    'proc_n3P' => $hasFractie ? null : $this->pct($o['cotaBun'] ?? 100),
                    'proc_n4P' => $cv,
                ] + $adrP + [
                    'tel_P' => $this->digits($o['telefon'] ?? null),
                    'email_P' => $this->str($o['email'] ?? null),
                    'ufisc_P' => $this->ufisc($ocif, $o['organFiscal'] ?? null, "$op.organFiscal", $err, $warn),
                ]);
            }
            if (abs($sum - (float) $cotaVenit) > 0.1) {
                $err('BR-C168-00991', "$p.cotaVenit", sprintf('Procentul calculat (%s) trebuie să fie egal cu suma cotelor locatorilor din contract (%s) (±0.1).', $cotaVenit, rtrim(rtrim(number_format($sum, 2, '.', ''), '0'), '.')));
            }
            if ($desemnati !== 1) {
                $err('STORNO-REQ', "$p.locatori", 'Exact un locator trebuie marcat desemnat=true (d_decl=1).');
            }
        }

        return new FormBuildResult($doc->saveXML() ?: '', $issues);
    }

    /**
     * Address attributes for suffix L (landlord), C (property), Ch (tenant), P (owner).
     * Returns RS_<suffix> and the coded fields; callers drop RS_C (the contract has none).
     *
     * @return array<string, string|null>
     */
    private function address(mixed $a, string $path, string $sfx, callable $err, bool $required): array
    {
        $a = is_array($a) ? $a : [];
        if ($a === [] && $required) {
            $err('STORNO-REQ', $path, 'Adresa este obligatorie.');
        }
        $tara = strtoupper($this->str($a['tara'] ?? null) ?? 'RO');
        $ro = $tara === 'RO';
        $out = ['RS_' . $sfx => $ro ? '1' : '2'];
        if ($ro) {
            $judet = $this->digits($a['judet'] ?? null);
            if ($judet === null || !in_array((int) $judet, self::JUDETE, true)) {
                if ($a !== [] || $required) {
                    $err('DUK-ADDRESS', "$path.judet", 'Codul județului lipsește sau nu este un cod ANAF valid (anaf_nomenclator_judete).');
                }
            }
            foreach (['localitate' => 'cod_localit', 'strada' => 'cod_strada'] as $k => $attr) {
                if ($this->digits($a[$k] ?? null) === null && ($a !== [] || $required)) {
                    $err('DUK-ADDRESS', "$path.$k", "$k (cod nomenclator ANAF) este obligatoriu pentru adrese din România.");
                }
            }
            if ($this->str($a['numar'] ?? null) === null && ($a !== [] || $required)) {
                $err('DUK-ADDRESS', "$path.numar", 'Numărul este obligatoriu ("FN" dacă nu există).');
            }
            $out += [
                'judet_' . $sfx => $judet,
                'cod_localit_' . $sfx => $this->digits($a['localitate'] ?? null),
                'localit_' . $sfx => $this->str($a['localitateNume'] ?? null) ?? $this->str($a['localitate'] ?? null),
                'cod_strada_' . $sfx => $this->digits($a['strada'] ?? null),
                'strada_' . $sfx => $this->str($a['stradaNume'] ?? null) ?? $this->str($a['strada'] ?? null),
            ];
        } else {
            $out += [
                'tara_' . $sfx => $tara,
                'localit_' . $sfx => $this->str($a['localitateNume'] ?? ($a['localitate'] ?? null)),
                'strada_' . $sfx => $this->str($a['stradaNume'] ?? ($a['strada'] ?? null)),
            ];
        }
        $out += [
            'nr_' . $sfx => $this->str($a['numar'] ?? null),
            'adresa_' . $sfx => $this->str($a['detalii'] ?? null),
            'codp_' . $sfx => $this->str($a['codPostal'] ?? null),
        ];
        if ($sfx === 'C') {
            unset($out['tara_C']);
        }

        return $out;
    }

    /** @param array<string, string|null> $attrs */
    private function attrs(\DOMElement $el, array $attrs): void
    {
        foreach ($attrs as $name => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $el->setAttribute($name, $value);
        }
    }

    private function str(mixed $v): ?string
    {
        if ($v === null || is_array($v)) {
            return null;
        }
        $s = trim((string) $v);

        return $s === '' ? null : $s;
    }

    private function digits(mixed $v): ?string
    {
        $s = $this->str($v);
        if ($s === null) {
            return null;
        }
        $d = preg_replace('/\D+/', '', strtoupper($s) === $s ? preg_replace('/^RO/', '', $s) : $s) ?? '';

        return $d === '' ? null : $d;
    }

    /**
     * ANAF rules R77 / R9.2 / BR-C168-0031 / BR-C168-0088: the competent fiscal office is
     * filled only for a NIF (13 digits starting with 9, non-residents) and must be empty otherwise.
     */
    private function ufisc(?string $cif, mixed $organFiscal, string $field, callable $err, callable $warn): ?string
    {
        $uf = $this->digits($organFiscal);
        $isNif = $cif !== null && strlen($cif) === 13 && $cif[0] === '9';
        if ($isNif && $uf === null) {
            $err('BR-C168-0088', $field, 'Organul fiscal competent trebuie completat pentru NIF (cif începe cu 9).');
        }
        if (!$isNif && $uf !== null) {
            $warn('BR-C168-0031', $field, 'Organul fiscal competent se completează doar pentru NIF; a fost omis din XML.');

            return null;
        }

        return $uf;
    }

    /** CNP control digit (13 digits starting 1–8); CUIs and NIFs (9…) are not checked here. */
    private function cnpChecksumOk(string $d): bool
    {
        if (strlen($d) !== 13 || $d[0] === '9') {
            return true;
        }
        $w = '279146358279';
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $d[$i] * (int) $w[$i];
        }
        $c = $sum % 11;
        $c = $c === 10 ? 1 : $c;

        return (int) $d[12] === $c;
    }

    private function cifOk(string $d): bool
    {
        return preg_match('/^[1-9]\d{12}$/', $d) === 1 || preg_match('/^[1-9]\d{1,9}$/', $d) === 1;
    }

    private function pct(mixed $v): string
    {
        $f = is_numeric($v) ? (float) $v : 100.0;
        $s = number_format($f, 2, '.', '');

        return rtrim(rtrim($s, '0'), '.');
    }

    private function date(mixed $v, string $field, callable $err): ?string
    {
        $s = $this->str($v);
        if ($s === null) {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) {
            return sprintf('%s.%s.%s', $m[3], $m[2], $m[1]);
        }
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $s, $m)) {
            return sprintf('%02d.%02d.%s', (int) $m[1], (int) $m[2], $m[3]);
        }
        $err('STORNO-REQ', $field, "Data \"$s\" trebuie să fie DD.MM.YYYY sau YYYY-MM-DD.");

        return null;
    }

    /** @return array<string, list<array{name: string, use: string, type: string}>> attributes per element, read from the bundled XSD */
    private function xsdAttributes(): array
    {
        if (!is_file(self::XSD)) {
            return [];
        }
        $doc = new \DOMDocument();
        if (!@$doc->load(self::XSD)) {
            return [];
        }
        $xp = new \DOMXPath($doc);
        $xp->registerNamespace('xs', 'http://www.w3.org/2001/XMLSchema');
        $simple = [];
        foreach ($xp->query('//xs:simpleType[@name]') ?: [] as $st) {
            /** @var \DOMElement $st */
            $simple[$st->getAttribute('name')] = $this->restriction($xp, $st);
        }
        $out = [];
        foreach ($xp->query('//xs:complexType[@name]') ?: [] as $ct) {
            /** @var \DOMElement $ct */
            $name = preg_replace('/Type$/', '', $ct->getAttribute('name')) ?? $ct->getAttribute('name');
            $list = [];
            foreach ($xp->query('xs:attribute | xs:complexContent/*/xs:attribute | xs:simpleContent/*/xs:attribute', $ct) ?: [] as $a) {
                /** @var \DOMElement $a */
                $t = $a->getAttribute('type');
                $desc = $t !== '' ? ($simple[preg_replace('/^\w+:/', '', $t)] ?? $t) : $this->restriction($xp, $a);
                $list[] = ['name' => $a->getAttribute('name'), 'use' => $a->getAttribute('use') ?: 'optional', 'type' => $desc];
            }
            $out[strtolower($name)] = $list;
        }

        return $out;
    }

    private function restriction(\DOMXPath $xp, \DOMElement $ctx): string
    {
        $r = $xp->query('.//xs:restriction', $ctx)?->item(0);
        if (!$r instanceof \DOMElement) {
            return '';
        }
        $parts = [preg_replace('/^\w+:/', '', $r->getAttribute('base')) ?? ''];
        $enum = [];
        foreach ($r->childNodes as $c) {
            if (!$c instanceof \DOMElement) {
                continue;
            }
            if ($c->localName === 'enumeration') {
                $enum[] = $c->getAttribute('value');
            } else {
                $parts[] = $c->localName . '=' . $c->getAttribute('value');
            }
        }
        if ($enum !== []) {
            $parts[] = count($enum) > 12 ? 'enum[' . count($enum) . ' values, see nomenclator]' : 'enum[' . implode(',', $enum) . ']';
        }

        return implode(' ', $parts);
    }
}
