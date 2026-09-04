<?php

declare(strict_types=1);

namespace App\Service\Declaration\Forms;

/**
 * D212 — Declarația unică (income tax and social contributions of natural persons),
 * scenario "venituri din chirii" (rent income, categ_venit 1015): chapter I.1 with one
 * entry per contract, the obligations summary and the CASS section when the income
 * reaches the thresholds. Schema mfp:anaf:dgti:d212:declaratie:v11 (campaigns 2025 and
 * 2026 as verified with ANAF's validator). Calculations follow ANAF's own web form
 * (anaf.ro/declaratii/duf): 20 % forfait, 10 % tax, CASS on 6/12/24 minimum wages.
 *
 * Deliberately out of scope for now: income from other categories (PFA, dividends as
 * declared income, capital gains), non-residents, rectifications of other chapters,
 * tenants that are legal entities (they withhold the tax at source).
 */
final class D212Form implements DeclarationFormInterface
{
    public const NAMESPACE = 'mfp:anaf:dgti:d212:declaratie:v11';
    private const CATEG_CHIRII = '1015';
    private const FORFAIT_PCT = 20;
    private const TAX_PCT = 10;
    private const CASS_PCT = 10;

    /** Filing year (an_r) → gross minimum wage used for the CASS thresholds of the income year (an_r − 1). */
    private const SALARIU_MINIM = ['2025' => 3300, '2026' => 4050];

    public function type(): string
    {
        return 'D212';
    }

    public function summary(): array
    {
        return [
            'type' => 'D212',
            'title' => 'D212 — Declarația unică (venituri din chirii)',
            'titleEn' => 'D212 — Single tax return of natural persons (rent income scenario)',
            'description' => 'Impozitul pe veniturile din chirii realizate anul trecut (cap. I.1, cotă forfetară 20 %, impozit 10 %) și CASS când venitul depășește 6 salarii minime. Alte categorii de venit nu sunt încă acoperite.',
            'descriptionEn' => 'Income tax on last year\'s rent income (chapter I.1, 20 % forfait, 10 % tax) and the health contribution when the income reaches 6 minimum wages. Other income categories are not covered yet.',
        ];
    }

    public function spec(): array
    {
        $years = array_keys(self::SALARIU_MINIM);

        return [
            'type' => 'D212',
            'scenario' => 'chirii',
            'title' => $this->summary()['title'],
            'titleEn' => $this->summary()['titleEn'],
            'legalBasis' => 'Codul fiscal art. 84 (venituri din cedarea folosinței bunurilor: venit net = venit brut − 20 % cotă forfetară, impozit 10 %), art. 170 (CASS pe plafoane de 6/12/24 salarii minime brute), art. 122 (Declarația unică, termen 25 mai a anului următor).',
            'xml' => [
                'root' => 'd212',
                'namespace' => self::NAMESPACE,
                'period' => 'an_r = filing year (' . implode(' or ', $years) . '), luna_r = 12; the income declared is the one realised in an_r − 1',
                'structure' => '<d212 …identification + chapter flags…><oblig_realizat …summary + CASS…/><cap11 …one per rent contract (categ_venit 1015)…/></d212>',
                'notes' => [
                    'totalPlata_A is the sum of the CNP digits (rule R4).',
                    'The ANAF validator checks the arithmetic (net = gross − expenses, tax = net × 10 %) but not the CASS thresholds: Storno computes them from the minimum wage of the income year, exactly like ANAF\'s web form.',
                    'nume_c: letters and "-" only; adresa_c: Latin letters, digits, comma, dot, dash and spaces (diacritics are transliterated).',
                ],
            ],
            'input' => [
                'an' => ['type' => 'integer', 'required' => true, 'enum' => array_combine($years, array_map(fn ($y) => 'income of ' . ((int) $y - 1) . ', minimum wage ' . self::SALARIU_MINIM[$y] . ' RON', $years)), 'hint' => 'Filing year (an_r). 2026 = income realised in 2025.'],
                'rectificativa' => ['type' => 'boolean', 'default' => false, 'hint' => 'true when correcting a D212 already filed for the same year (d_rec=1, rectif1=1)'],
                'contribuabil' => [
                    'type' => 'object', 'required' => true,
                    'fields' => [
                        'nume' => ['type' => 'string', 'required' => true, 'maxLength' => 250, 'hint' => 'Nume, inițiala tatălui, prenume, e.g. "POPESCU I ION"; letters and dashes only'],
                        'cnp' => ['type' => 'string', 'required' => true, 'pattern' => '[1-8]\\d{12} with a valid control digit', 'hint' => 'Never invent or pad it'],
                        'adresa' => ['type' => 'string', 'required' => true, 'maxLength' => 200, 'hint' => 'Domicile, free text (diacritics are converted)'],
                        'telefon' => ['type' => 'string', 'hint' => 'digits only'],
                        'email' => ['type' => 'string', 'maxLength' => 200],
                        'iban' => ['type' => 'string', 'hint' => 'Bank account for refunds (cont_bancar)'],
                    ],
                ],
                'imputernicit' => ['type' => 'object', 'hint' => 'Representative filing on behalf of the taxpayer (optional)', 'fields' => ['denumire' => ['type' => 'string', 'maxLength' => 60], 'cif' => ['type' => 'string', 'hint' => 'CNP or CUI'], 'adresa' => ['type' => 'string', 'maxLength' => 200], 'telefon' => ['type' => 'string'], 'email' => ['type' => 'string']]],
                'chirii' => [
                    'type' => 'array', 'required' => true, 'minItems' => 1,
                    'hint' => 'One entry per rental contract (property) with a natural-person tenant. Rent paid by a legal entity is taxed at source by the tenant and must not be listed here.',
                    'items' => [
                        'numarContract' => ['type' => 'string', 'required' => true, 'maxLength' => 15, 'hint' => 'Contract number as registered at ANAF (C168)'],
                        'dataContract' => ['type' => 'date', 'required' => true, 'hint' => 'DD.MM.YYYY or YYYY-MM-DD'],
                        'adresaBun' => ['type' => 'string', 'required' => true, 'maxLength' => 250, 'hint' => 'Description and address of the rented property (descriere_sediu_bun)'],
                        'deLa' => ['type' => 'date', 'required' => true, 'hint' => 'First day of the rental in the income year (data_incep); 01.01 when the contract was already running'],
                        'panaLa' => ['type' => 'date', 'required' => true, 'hint' => 'Last day in the income year (data_sf); 31.12 when the contract continues'],
                        'venitBrut' => ['type' => 'integer', 'required' => true, 'hint' => 'Gross rent for the income year in RON, whole lei (rent in foreign currency converted at the BNR rate of each payment)'],
                        'chiriasPersoanaJuridica' => ['type' => 'boolean', 'default' => false, 'hint' => 'true → refused: the tenant withholds the tax (art. 84 alin. 2); count that income only in alteVenituriCass.cedareaFolosinteiRetinutaLaSursa'],
                    ],
                ],
                'alteVenituriCass' => [
                    'type' => 'object', 'hint' => 'Other net incomes of the same year that count towards the CASS thresholds (art. 170), when the person had them',
                    'fields' => [
                        'drepturiProprietateIntelectuala' => ['type' => 'integer', 'hint' => 'cass_ven_dpi'],
                        'asocieri' => ['type' => 'integer', 'hint' => 'income from associations with legal entities (cass_ven_asc)'],
                        'cedareaFolosinteiRetinutaLaSursa' => ['type' => 'integer', 'hint' => 'net rent received from legal-entity tenants (taxed at source, still counts for CASS; added to cass_ven_cfb)'],
                        'investitii' => ['type' => 'integer', 'hint' => 'dividends, interest, capital gains (cass_ven_inv)'],
                        'agricole' => ['type' => 'integer', 'hint' => 'cass_ven_asp'],
                        'alteSurse' => ['type' => 'integer', 'hint' => 'cass_ven_alt'],
                    ],
                ],
                'cassRetinuta' => ['type' => 'integer', 'hint' => 'CASS already withheld by an income payer (cass_retinut), rarely used'],
            ],
            'calculations' => [
                'per contract' => 'chelt_deduc = floor(venitBrut × 20 %); venit_net_anual = venitBrut − chelt_deduc; impozit11 = round(venit_net_anual × 10 %)',
                'summary' => 'oblimpoz_real_total = Σ impozit11; impozit_venit_plus = dif_de_plata (+ CASS)',
                'CASS' => 'total = Σ venit_net_anual + alteVenituriCass; below 6 × minimum wage → no CASS (bifa132=0); 6–12 → base 6 × wage; 12–24 → 12 × wage; ≥ 24 → 24 × wage; cass_datorat = base × 10 %',
                'minimum wage' => self::SALARIU_MINIM,
            ],
            'rules' => [
                ['code' => 'R4', 'source' => 'ANAF DUKIntegrator', 'message' => 'totalPlata_A = sum of the CNP digits (computed by Storno).'],
                ['code' => 'BR-CNP', 'source' => 'ANAF DUKIntegrator', 'message' => 'CNP with a valid control digit; never invented.'],
                ['code' => 'D212-PERIOD', 'source' => 'ANAF structure', 'message' => 'data_incep / data_sf must fall in the income year (an_r − 1); data_incep ≤ data_sf.'],
                ['code' => 'D212-ARITH', 'source' => 'ANAF DUKIntegrator', 'message' => 'venit_net_anual = venit_brut − chelt_deduc; impozit11 = venit_recalculat × 10 %; oblimpoz_real_total = Σ impozit11; dif_de_plata = impozit_venit_plus + cass_plus.'],
                ['code' => 'D212-CASS', 'source' => 'Codul fiscal art. 170 + ANAF web form', 'message' => 'CASS thresholds at 6, 12 and 24 gross minimum wages of the income year; the validator does not check them, Storno does.'],
                ['code' => 'D212-PJ', 'source' => 'Codul fiscal art. 84', 'message' => 'Rent from a legal-entity tenant is taxed at source by the tenant and is not declared in chapter I.1 (it still counts towards CASS).'],
                ['code' => 'D212-SCOPE', 'source' => 'Storno', 'message' => 'Only the rent scenario is built; other chapters are not written. Non-residents are not supported.'],
            ],
            'validation' => ['duk' => 'POST /api/v1/public/declarations/forms/D212/build validates with ANAF DUKIntegrator (D212Validator.jar); ANAF publishes no online validator for D212.'],
            'filing' => [
                '1' => 'declaration_build → valid=true (fix issues otherwise)',
                '2' => 'declaration_pdf (no attachment needed) → the PDF with the XML embedded',
                '3' => 'Storno Agent: agent_submit_declaration_pdf with the qualified certificate → ANAF index; or upload the PDF in SPV (persoane fizice: user/parolă) → Depunere declarații',
                '4' => 'anaf_declaration_status(index, cnp) → recipisa; pay the amount in dif_de_plata by 25 May (impozit + CASS)',
            ],
            'example' => $this->example(),
        ];
    }

    /** @return array<string, mixed> */
    public function example(): array
    {
        return [
            'an' => 2026,
            'contribuabil' => ['nume' => 'POPESCU I ION', 'cnp' => '1800101400016', 'adresa' => 'Bucuresti, Bld. Iuliu Maniu nr. 7, bl. 1, ap. 16', 'email' => 'ion@example.com'],
            'chirii' => [[
                'numarContract' => '2', 'dataContract' => '01.12.2023',
                'adresaBun' => 'Apartament, Bucuresti, Bld. Iuliu Maniu nr. 7, bl. 1, ap. 16',
                'deLa' => '01.01.2025', 'panaLa' => '31.12.2025',
                'venitBrut' => 60000,
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

        $an = (string) (int) ($input['an'] ?? 0);
        $salMin = self::SALARIU_MINIM[$an] ?? null;
        if ($salMin === null) {
            $err('D212-SCOPE', 'an', sprintf('Anul de depunere %s nu este suportat (disponibil: %s).', $an, implode(', ', array_keys(self::SALARIU_MINIM))));
            $salMin = 0;
        }
        $incomeYear = (int) $an - 1;

        $c = is_array($input['contribuabil'] ?? null) ? $input['contribuabil'] : [];
        $cnp = $this->digits($c['cnp'] ?? null) ?? '';
        if (!preg_match('/^[1-8]\d{12}$/', $cnp)) {
            $err('BR-CNP', 'contribuabil.cnp', 'CNP-ul lipsește sau nu are 13 cifre (nerezidenții cu NIF nu sunt suportați).');
        } elseif (!$this->cnpChecksumOk($cnp)) {
            $err('BR-CNP', 'contribuabil.cnp', 'CNP-ul are cifră de control greșită.');
        }
        $nume = $this->str($c['nume'] ?? null);
        if ($nume === null) {
            $err('STORNO-REQ', 'contribuabil.nume', 'Numele contribuabilului este obligatoriu.');
        } elseif (!preg_match('/^[\p{L} \-]+$/u', $nume)) {
            $err('STORNO-REQ', 'contribuabil.nume', 'nume_c acceptă doar litere, spații și "-".');
        }
        $adresa = $this->ascii($this->str($c['adresa'] ?? null));
        if ($adresa === null) {
            $err('STORNO-REQ', 'contribuabil.adresa', 'Adresa contribuabilului este obligatorie.');
        } elseif (!preg_match('/^[A-Za-z0-9,.\- ]+$/', $adresa)) {
            $err('STORNO-REQ', 'contribuabil.adresa', 'adresa_c acceptă doar litere latine, cifre, virgulă, punct, minus și spații.');
        }
        if ($adresa !== null && $adresa !== $this->str($c['adresa'] ?? null)) {
            $warn('STORNO-ASCII', 'contribuabil.adresa', 'Diacriticele din adresă au fost transliterate (cerință ANAF).');
        }

        $chirii = is_array($input['chirii'] ?? null) ? array_values($input['chirii']) : [];
        if ($chirii === []) {
            $err('STORNO-REQ', 'chirii', 'Cel puțin un contract de închiriere este obligatoriu.');
        }

        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;
        $root = $doc->createElementNS(self::NAMESPACE, 'd212');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $doc->appendChild($root);
        $summary = $doc->createElementNS(self::NAMESPACE, 'oblig_realizat');
        $root->appendChild($summary);

        $totalImpozit = 0;
        $totalNet = 0;
        foreach ($chirii as $i => $ch) {
            $p = "chirii[$i]";
            $ch = is_array($ch) ? $ch : [];
            if (!empty($ch['chiriasPersoanaJuridica'])) {
                $err('D212-PJ', "$p.chiriasPersoanaJuridica", 'Chiria plătită de o persoană juridică se impozitează prin reținere la sursă de către chiriaș și nu se declară în cap. I.1; trece venitul net în alteVenituriCass.cedareaFolosinteiRetinutaLaSursa dacă vrei să conteze la CASS.');
                continue;
            }
            $brut = isset($ch['venitBrut']) && is_numeric($ch['venitBrut']) ? (int) round((float) $ch['venitBrut']) : null;
            if ($brut === null || $brut < 0) {
                $err('STORNO-REQ', "$p.venitBrut", 'venitBrut (lei, întreg, ≥ 0) este obligatoriu.');
                $brut = 0;
            }
            foreach (['numarContract' => 15, 'adresaBun' => 250] as $k => $max) {
                $v = $this->str($ch[$k] ?? null);
                if ($v === null) {
                    $err('STORNO-REQ', "$p.$k", "$k este obligatoriu.");
                } elseif (mb_strlen($v) > $max) {
                    $err('STORNO-REQ', "$p.$k", "$k depășește $max caractere.");
                }
            }
            $dataContract = $this->date($ch['dataContract'] ?? null, "$p.dataContract", $err);
            $deLa = $this->date($ch['deLa'] ?? null, "$p.deLa", $err);
            $panaLa = $this->date($ch['panaLa'] ?? null, "$p.panaLa", $err);
            foreach (['deLa' => $deLa, 'panaLa' => $panaLa] as $k => $d) {
                if ($d === null) {
                    $err('STORNO-REQ', "$p.$k", "$k este obligatoriu.");
                } elseif ((int) substr($d, 6) !== $incomeYear) {
                    $err('D212-PERIOD', "$p.$k", sprintf('%s trebuie să fie în anul veniturilor (%d).', $k, $incomeYear));
                }
            }
            if ($deLa !== null && $panaLa !== null && $this->iso($deLa) > $this->iso($panaLa)) {
                $err('D212-PERIOD', "$p.deLa", 'deLa trebuie să fie înainte de panaLa.');
            }
            if ($dataContract !== null && $deLa !== null && $this->iso($dataContract) > $this->iso($deLa)) {
                $warn('D212-PERIOD', "$p.dataContract", 'Data contractului este după începutul perioadei declarate; verifică.');
            }

            $chelt = intdiv($brut * self::FORFAIT_PCT, 100);
            $net = $brut - $chelt;
            $impozit = (int) round($net * self::TAX_PCT / 100);
            $totalImpozit += $impozit;
            $totalNet += $net;

            $el = $doc->createElementNS(self::NAMESPACE, 'cap11');
            $root->appendChild($el);
            $this->attrs($el, [
                'scutire' => '0',
                'reg' => '0',
                'categ_venit' => self::CATEG_CHIRII,
                'det_ven_net' => '2',
                'forma_org' => '1',
                'descriere_sediu_bun' => $this->str($ch['adresaBun'] ?? null),
                'nr_doc_autoriz' => $this->str($ch['numarContract'] ?? null),
                'data_doc_autoriz' => $dataContract,
                'data_incep' => $deLa,
                'data_sf' => $panaLa,
                'venit_brut' => (string) $brut,
                'chelt_deduc' => (string) $chelt,
                'venit_net_anual' => (string) $net,
                'venit_recalculat' => (string) $net,
                'impozit11' => (string) $impozit,
            ]);
        }

        // CASS (art. 170): thresholds on the minimum wage of the income year, like ANAF's web form
        $alte = is_array($input['alteVenituriCass'] ?? null) ? $input['alteVenituriCass'] : [];
        $int = fn ($v) => isset($v) && is_numeric($v) ? max(0, (int) round((float) $v)) : 0;
        $cassVen = [
            'cass_ven_dpi' => $int($alte['drepturiProprietateIntelectuala'] ?? null),
            'cass_ven_asc' => $int($alte['asocieri'] ?? null),
            'cass_ven_cfb' => $totalNet + $int($alte['cedareaFolosinteiRetinutaLaSursa'] ?? null),
            'cass_ven_inv' => $int($alte['investitii'] ?? null),
            'cass_ven_asp' => $int($alte['agricole'] ?? null),
            'cass_ven_alt' => $int($alte['alteSurse'] ?? null),
        ];
        $cassTotal = array_sum($cassVen);
        $tier = 0;
        $base = 0;
        if ($salMin > 0 && $cassTotal >= 6 * $salMin) {
            $tier = $cassTotal < 12 * $salMin ? 1 : ($cassTotal < 24 * $salMin ? 2 : 3);
            $base = [1 => 6, 2 => 12, 3 => 24][$tier] * $salMin;
        }
        $cassDatorat = (int) round($base * self::CASS_PCT / 100);
        $cassRetinut = min($int($input['cassRetinuta'] ?? null), $cassDatorat);
        $cassPlus = $cassDatorat - $cassRetinut;

        $summaryAttrs = [
            'oblimpoz_real_total' => (string) $totalImpozit,
            'oblimpoz_real_dif_deplata' => $totalImpozit > 0 ? (string) $totalImpozit : null,
            'impozit_venit_plus' => $totalImpozit > 0 ? (string) $totalImpozit : null,
        ];
        if ($tier > 0) {
            $summaryAttrs += [
                'bifa_cass_datorat_dpi' => '1',
                'bifa_cass_real' => (string) $tier,
            ] + array_map(fn ($v) => (string) $v, $cassVen) + [
                'cass_total_ven' => (string) $cassTotal,
                'cass_baza' => (string) $base,
                'cass_datorat' => (string) $cassDatorat,
                'cass_retinut' => $cassRetinut > 0 ? (string) $cassRetinut : null,
                'cass_dif_plus' => (string) $cassPlus,
                'oblcass_real_difPlus_dpi' => (string) $cassPlus,
                'cass_plus' => (string) $cassPlus,
            ];
        }
        $difPlata = $totalImpozit + ($tier > 0 ? $cassPlus : 0);
        $summaryAttrs['dif_de_plata'] = $difPlata > 0 ? (string) $difPlata : null;
        $this->attrs($summary, $summaryAttrs);

        $rectif = !empty($input['rectificativa']);
        $imp = is_array($input['imputernicit'] ?? null) ? $input['imputernicit'] : [];
        $this->attrs($root, [
            'an_r' => $an,
            'luna_r' => '12',
            'd_rec' => $rectif ? '1' : '0',
            'rectif1' => $rectif ? '1' : '0',
            'rectif2' => '0',
            'totalPlata_A' => (string) array_sum(array_map('intval', str_split($cnp !== '' ? $cnp : '0'))),
            'bifa_succesor' => '0',
            'anulare_litA' => '0',
            'anulare_litB' => '0',
            'bifa_conformare' => '0',
            'bifa111' => '1',
            'bifa112' => '0',
            'bifa113' => '0',
            'bifa121' => '0',
            'bifa122' => '0',
            'bifa131' => '0',
            'bifa132' => $tier > 0 ? '1' : '0',
            'bifa14' => '0',
            'bifa15' => '0',
            'bifa18' => '0',
            'nume_c' => $nume,
            'adresa_c' => $adresa,
            'telefon_c' => $this->digits($c['telefon'] ?? null),
            'email_c' => $this->str($c['email'] ?? null),
            'cif' => $cnp,
            'nerezident' => '0',
            'cont_bancar' => $this->str($c['iban'] ?? null) !== null ? strtoupper(str_replace(' ', '', (string) $c['iban'])) : null,
            'den_i' => $this->str($imp['denumire'] ?? null),
            'cif_i' => $this->digits($imp['cif'] ?? null),
            'adresa_i' => $this->ascii($this->str($imp['adresa'] ?? null)),
            'telefon_i' => $this->digits($imp['telefon'] ?? null),
            'email_i' => $this->str($imp['email'] ?? null),
        ]);

        if ($tier > 0) {
            $issues[] = ['level' => 'info', 'code' => 'D212-CASS', 'field' => 'alteVenituriCass', 'message' => sprintf('Venitul net total %d lei ≥ %d × %d lei: CASS datorată %d lei (baza %d lei, plafon %d salarii minime).', $cassTotal, 6, $salMin, $cassDatorat, $base, [1 => 6, 2 => 12, 3 => 24][$tier])];
        } elseif ($salMin > 0 && $totalNet > 0) {
            $issues[] = ['level' => 'info', 'code' => 'D212-CASS', 'field' => 'alteVenituriCass', 'message' => sprintf('Venitul net total %d lei este sub 6 × %d lei: nu se datorează CASS. Dacă persoana a avut și alte venituri (dividende, dobânzi, alte chirii), trece-le în alteVenituriCass — pot depăși pragul.', $cassTotal, $salMin)];
        }
        $issues[] = ['level' => 'info', 'code' => 'D212-TOTAL', 'field' => '', 'message' => sprintf('Impozit pe venit %d lei%s; total de plată %d lei până la 25 mai %s.', $totalImpozit, $tier > 0 ? sprintf(' + CASS %d lei', $cassPlus) : '', $difPlata, $an)];

        return new FormBuildResult($doc->saveXML() ?: '', $issues);
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
        $d = preg_replace('/\D+/', '', $s) ?? '';

        return $d === '' ? null : $d;
    }

    private function ascii(?string $s): ?string
    {
        if ($s === null) {
            return null;
        }
        $map = ['ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't', 'Ă' => 'A', 'Â' => 'A', 'Î' => 'I', 'Ș' => 'S', 'Ş' => 'S', 'Ț' => 'T', 'Ţ' => 'T'];
        $out = strtr($s, $map);
        $conv = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $out);

        return $conv !== false && $conv !== '' ? $conv : $out;
    }

    private function cnpChecksumOk(string $d): bool
    {
        $w = '279146358279';
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $d[$i] * (int) $w[$i];
        }
        $c = $sum % 11;
        $c = $c === 10 ? 1 : $c;

        return (int) $d[12] === $c;
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

    /** DD.MM.YYYY → YYYYMMDD for comparisons */
    private function iso(string $d): string
    {
        return substr($d, 6, 4) . substr($d, 3, 2) . substr($d, 0, 2);
    }
}
