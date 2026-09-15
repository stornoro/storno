<?php

namespace App\Service\Declaration\Populator;

use App\Entity\Company;
use App\Entity\Invoice;
use App\Enum\InvoiceDirection;
use App\Enum\InvoiceTypeCode;
use App\Repository\DocumentSeriesRepository;
use App\Repository\InvoiceRepository;
use App\Service\Declaration\D394\D394Rules;
use App\Service\Declaration\DeclarationDataPopulatorInterface;

/**
 * Populates D394 (declarația informativă privind livrările/prestările și achizițiile
 * efectuate pe teritoriul național) from the company's invoices.
 *
 * The form lists, per partner, the taxable operations of the period, aggregated by
 * operation type and VAT rate:
 *
 * - op1 rows: one per (partner type, partner CUI, operation type, rate). Sales with VAT are
 *   L, sales without VAT (exempt, 0 %) are LS; purchases with VAT from RO VAT payers are A,
 *   without VAT are AS; purchases from RO suppliers not registered for VAT are N (with the
 *   invoice as document). Reverse charge (V / C) and purchases from private individuals need
 *   the goods-code breakdown (op11) Storno does not keep, so they are left out and listed in
 *   `warnings`; intra-community operations and exports belong to D390 / customs and are only
 *   counted in the informative block.
 * - rezumat1: one row per (partner type, rate) with the attribute groups the validator
 *   demands for that pair (D394Rules::rezumat1Groups), sums of the op1 rows;
 * - rezumat2: one row per non-zero rate with the L / A / AI sums of every partner;
 * - serieFacturi: the invoice series used in the period (tip 1 = allocated range,
 *   tip 2 = numbers issued in the period);
 * - informatii: partner counts, the number of invoices issued and — only for companies
 *   applying VAT on collection — the VAT per rate.
 *
 * Every op1 row is rounded to whole lei after grouping; the summaries are sums of rows so
 * the validator's cross-checks hold. `totalPlata_A` is the form's control sum.
 */
class D394Populator implements DeclarationDataPopulatorInterface
{
    private const PF_LABEL = 'PERSOANE FIZICE';

    public function __construct(
        private readonly InvoiceRepository $invoiceRepository,
        private readonly ?DocumentSeriesRepository $documentSeriesRepository = null,
    ) {}

    public function supportsType(string $type): bool
    {
        return $type === 'd394';
    }

    public function populate(Company $company, int $year, int $month, string $periodType): array
    {
        [$from, $to] = D300Populator::periodBounds($year, $month, $periodType);
        $invoices = $this->invoiceRepository->findForVatReturn($company, $from, $to);
        $ownCif = preg_replace('/\D/', '', (string) $company->getCif());

        /** @var array<string, array<string, mixed>> $rows op1 accumulators keyed by (tip, tip_partener, cota, cuiP) */
        $rows = [];
        /** @var array<string, array<string, mixed>> $display per-partner summaries for the web page */
        $display = ['sales' => [], 'purchases' => []];
        /** @var array<string, int> $excluded reason → number of lines or invoices */
        $excluded = [];
        /** @var array<string, int> $issues partner-level problems → number of invoices */
        $issues = [];
        $info = $this->emptyInfo();
        $counts = ['issued' => 0, 'received' => 0, 'declared' => 0, 'excluded' => 0];
        $skippedTypes = ['V' => 0, 'C' => 0, 'PF' => 0];
        /** @var array<string, array{numbers: int[], count: int}> $issuedSeries */
        $issuedSeries = [];
        $unsupportedRates = [];
        $countyVotes = [];
        $hasAffiliated = false;

        foreach ($invoices as $invoice) {
            $direction = $invoice->getDirection();
            if ($direction === null) {
                continue;
            }
            $isSale = $direction === InvoiceDirection::OUTGOING;
            $counts[$isSale ? 'issued' : 'received']++;

            if ($isSale) {
                $this->trackIssuedNumber($invoice, $issuedSeries);
            }

            $typeCode = $invoice->getInvoiceTypeCode();
            if ($typeCode === InvoiceTypeCode::SIMPLIFIED->value) {
                $excluded['simplified'] = ($excluded['simplified'] ?? 0) + 1;
                $counts['excluded']++;
                continue;
            }

            $partner = $this->partner($invoice, $isSale);
            if ($ownCif !== '' && $partner['digits'] === $ownCif) {
                $excluded['own_cui'] = ($excluded['own_cui'] ?? 0) + 1;
                $counts['excluded']++;
                continue;
            }
            if (!$isSale && in_array($partner['tip_partener'], [3, 4], true)) {
                // Intra-community acquisitions and imports are not declared in D394
                $excluded['foreign_purchase'] = ($excluded['foreign_purchase'] ?? 0) + 1;
                $counts['excluded']++;
                continue;
            }
            if ($partner['issue'] !== null) {
                $issues[$partner['issue']] = ($issues[$partner['issue']] ?? 0) + 1;
                $counts['excluded']++;
                continue;
            }
            if ($partner['countyVote'] !== null) {
                $countyVotes[$partner['countyVote']] = ($countyVotes[$partner['countyVote']] ?? 0) + 1;
            }
            $hasAffiliated = $hasAffiliated || $partner['affiliated'];

            $reverseCharge = $typeCode === InvoiceTypeCode::REVERSE_CHARGE->value;
            $invoiceId = (string) ($invoice->getId() ?? spl_object_id($invoice));
            $declaredSomething = false;

            foreach ($invoice->getLines() as $line) {
                $base = $this->toRon($line->getLineTotal(), $invoice);
                $vat = $this->toRon($line->getVatAmount(), $invoice);
                $rate = self::normalizeRate($line->getVatRate());
                $cat = strtoupper((string) $line->getVatCategoryCode());
                $isService = $line->getProduct()?->isService() ?? false;

                if ($isSale) {
                    if ($cat === 'AE' || $reverseCharge) {
                        $skippedTypes['V']++;
                        continue;
                    }
                    if ($cat === 'K') {
                        $info[$isService ? 'PrestIntra' : 'LIntra'] = bcadd($info[$isService ? 'PrestIntra' : 'LIntra'], $base, 2);
                        $excluded['intra_community'] = ($excluded['intra_community'] ?? 0) + 1;
                        continue;
                    }
                    if ($cat === 'G') {
                        $info['Export'] = bcadd($info['Export'], $base, 2);
                        $excluded['export'] = ($excluded['export'] ?? 0) + 1;
                        continue;
                    }
                    if ($cat === 'O') {
                        $excluded['outside_scope'] = ($excluded['outside_scope'] ?? 0) + 1;
                        continue;
                    }
                    if ($rate > 0) {
                        if (!in_array($rate, D394Rules::RATES, true)) {
                            $unsupportedRates[(string) $rate] = true;
                            continue;
                        }
                        $tip = 'L';
                        $cota = $rate;
                        $info['tvaCol'][$cota] = bcadd($info['tvaCol'][$cota] ?? '0.00', $vat, 2);
                        $bucket = $isService ? 'Prest' : 'BUN';
                        $info[$bucket][$cota] = bcadd($info[$bucket][$cota] ?? '0.00', $base, 2);
                    } else {
                        $tip = 'LS';
                        $cota = 0;
                        $vat = '0.00';
                        $bucket = $isService ? 'PrestScutit' : 'valoareScutit';
                        $info[$bucket] = bcadd($info[$bucket], $base, 2);
                    }
                } else {
                    if ($cat === 'AE' || $reverseCharge) {
                        $skippedTypes['C']++;
                        continue;
                    }
                    if ($partner['tip_partener'] === 2) {
                        if ($partner['cuiP'] === null || strlen($partner['cuiP']) === 13) {
                            // purchases from private individuals need op11 (goods codes)
                            $skippedTypes['PF']++;
                            continue;
                        }
                        $tip = 'N';
                        $cota = 0;
                        $base = bcadd($base, $vat, 2); // a supplier not registered for VAT charges none; keep the amount paid
                        $vat = '0.00';
                    } elseif ($rate > 0) {
                        if (!in_array($rate, D394Rules::RATES, true)) {
                            $unsupportedRates[(string) $rate] = true;
                            continue;
                        }
                        $tip = 'A';
                        $cota = $rate;
                        $info['tvaDed'][$cota] = bcadd($info['tvaDed'][$cota] ?? '0.00', $vat, 2);
                        $bucket = $isService ? 'achizitiiS' : 'achizitiiB';
                        $info[$bucket][$cota] = bcadd($info[$bucket][$cota] ?? '0.00', $base, 2);
                    } else {
                        $tip = 'AS';
                        $cota = 0;
                        $vat = '0.00';
                    }
                }

                $key = implode('|', [$tip, $partner['tip_partener'], $cota, (string) $partner['cuiP']]);
                if (!isset($rows[$key])) {
                    $rows[$key] = [
                        'tip' => $tip,
                        'tip_partener' => $partner['tip_partener'],
                        'cota' => $cota,
                        'cuiP' => $partner['cuiP'],
                        'denP' => $partner['denP'],
                        'taraP' => $partner['taraP'],
                        'locP' => $partner['locP'],
                        'judP' => $partner['judP'],
                        'tip_document' => $tip === 'N' ? 1 : null,
                        'baza' => '0.00',
                        'tva' => '0.00',
                        'invoices' => [],
                    ];
                }
                $rows[$key]['baza'] = bcadd($rows[$key]['baza'], $base, 2);
                $rows[$key]['tva'] = bcadd($rows[$key]['tva'], $vat, 2);
                $rows[$key]['invoices'][$invoiceId] = true;
                $declaredSomething = true;

                $this->addDisplay($display[$isSale ? 'sales' : 'purchases'], $partner, $invoiceId, $cota, $base, $vat);
            }

            if ($declaredSomething) {
                $counts['declared']++;
            }
        }

        // PF without an identifier are one row per (tip, cota): the county is the one most of them share
        if ($countyVotes) {
            arsort($countyVotes);
            $county = (string) array_key_first($countyVotes);
            foreach ($rows as &$row) {
                if ($row['tip_partener'] === 2 && $row['cuiP'] === null) {
                    $row['judP'] = $county;
                }
            }
            unset($row);
        }

        $partners = $this->finishRows($rows);
        $rezumat1 = $this->buildRezumat1($partners);
        $rezumat2 = $this->buildRezumat2($partners);
        $hasOperations = $partners !== [];
        $serieFacturi = $hasOperations ? $this->buildSeries($company, $issuedSeries) : [];
        $nrFacturi = 0;
        foreach ($issuedSeries as $s) {
            $nrFacturi += $s['count'];
        }

        $informatii = $this->buildInformatii($company, $partners, $rezumat2, $info, $hasOperations ? $nrFacturi : 0, $serieFacturi !== []);
        $totalPlata = $informatii['nrCui1'] + $informatii['nrCui2'] + $informatii['nrCui3'] + $informatii['nrCui4'];
        foreach ($rezumat2 as $r) {
            $totalPlata += $r['bazaL'] + $r['bazaA'] + $r['bazaAI'];
        }

        $warnings = $this->headerWarnings($company);
        if (($issues['NO_ID'] ?? 0) > 0) {
            $warnings[] = ['code' => 'PARTNER_WITHOUT_ID', 'message' => sprintf('%d facturi nu au CUI-ul / codul de TVA al partenerului si au fost lasate in afara declaratiei.', $issues['NO_ID'])];
        }
        if (($issues['INVALID_CUI'] ?? 0) > 0) {
            $warnings[] = ['code' => 'PARTNER_INVALID_CUI', 'message' => sprintf('%d facturi au un CUI / CNP de partener cu cifra de control gresita; validatorul ANAF le respinge, corecteaza partenerul.', $issues['INVALID_CUI'])];
        }
        if (($issues['NO_COUNTY'] ?? 0) > 0) {
            $warnings[] = ['code' => 'PARTNER_WITHOUT_COUNTY', 'message' => sprintf('%d facturi catre persoane fizice fara CNP nu au judetul clientului; declaratia cere judetul (judP) pentru ele.', $issues['NO_COUNTY'])];
        }
        if ($skippedTypes['V'] > 0) {
            $warnings[] = ['code' => 'REVERSE_CHARGE_SALES_SKIPPED', 'message' => sprintf('%d linii de vanzare cu taxare inversa (tip V) nu au fost declarate: formularul cere codurile de bunuri (op11), pe care Storno nu le tine.', $skippedTypes['V'])];
        }
        if ($skippedTypes['C'] > 0) {
            $warnings[] = ['code' => 'REVERSE_CHARGE_PURCHASES_SKIPPED', 'message' => sprintf('%d linii de achizitie cu taxare inversa (tip C) nu au fost declarate: formularul cere codurile de bunuri (op11).', $skippedTypes['C'])];
        }
        if ($skippedTypes['PF'] > 0) {
            $warnings[] = ['code' => 'INDIVIDUAL_SUPPLIER_SKIPPED', 'message' => sprintf('%d linii de achizitie de la persoane fizice (tip N) nu au fost declarate: formularul cere tipul documentului si codurile de bunuri (op11).', $skippedTypes['PF'])];
        }
        if ($unsupportedRates) {
            $warnings[] = ['code' => 'UNSUPPORTED_RATE', 'message' => sprintf('Linii cu cote de TVA pe care formularul nu le are (%s %%) au fost lasate in afara declaratiei.', implode(', ', array_keys($unsupportedRates)))];
        }
        if ($company->isVatOnCollection() && $hasOperations) {
            $warnings[] = ['code' => 'VAT_ON_COLLECTION_BY_INVOICE', 'message' => 'Compania aplica TVA la incasare: TVA colectata / deductibila pe cote (tvaCol / tvaDed) este cea din facturi, nu cea exigibila la incasare; verifica valorile inainte de depunere.'];
        }

        $totals = ['sales' => ['taxableBase' => '0.00', 'vatAmount' => '0.00'], 'purchases' => ['taxableBase' => '0.00', 'vatAmount' => '0.00']];
        foreach (['sales', 'purchases'] as $side) {
            foreach ($display[$side] as &$p) {
                $totals[$side]['taxableBase'] = bcadd($totals[$side]['taxableBase'], $p['total']['taxableBase'], 2);
                $totals[$side]['vatAmount'] = bcadd($totals[$side]['vatAmount'], $p['total']['vatAmount'], 2);
                unset($p['invoices']);
                $p['byRate'] = array_values($p['byRate']);
            }
            unset($p);
        }

        return [
            'form' => 'D394',
            'period' => ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'type' => $periodType === 'quarterly' ? 'T' : 'L'],
            'header' => $this->buildHeader($company, $year, $month, $periodType, $hasOperations, $hasAffiliated),
            'informatii' => $informatii,
            'partners' => $partners,
            'rezumat1' => $rezumat1,
            'rezumat2' => $rezumat2,
            'serieFacturi' => $serieFacturi,
            'totalPlata_A' => $totalPlata,
            'sales' => array_values($display['sales']),
            'purchases' => array_values($display['purchases']),
            'totals' => $totals,
            'rezumat' => [
                'sales' => $this->rateSummary($display['sales']),
                'purchases' => $this->rateSummary($display['purchases']),
            ],
            'invoiceCounts' => $counts,
            'excluded' => $excluded,
            'warnings' => $warnings,
        ];
    }

    /**
     * Who the invoice is with, in the form's terms.
     *
     * @return array{tip_partener: int, cuiP: ?string, digits: string, denP: string, taraP: ?string, locP: ?string, judP: ?string, issue: ?string, countyVote: ?string, id: string, name: string, affiliated: bool}
     */
    private function partner(Invoice $invoice, bool $isSale): array
    {
        if ($isSale) {
            $client = $invoice->getClient();
            $snapshot = $invoice->getBuyerSnapshot() ?? [];
            $country = strtoupper((string) ($client?->getCountry() ?: ($snapshot['country'] ?? 'RO'))) ?: 'RO';
            $name = (string) ($invoice->getReceiverName() ?: $client?->getName() ?: ($snapshot['name'] ?? ''));
            $cui = (string) ($invoice->getReceiverCif() ?: $client?->getCui() ?: ($snapshot['cui'] ?? ''));
            $cnp = (string) ($client?->getCnp() ?: ($snapshot['cnp'] ?? ''));
            $vatCode = (string) ($client?->getVatCode() ?: ($snapshot['vatCode'] ?? ''));
            $vatPayer = $client ? $client->isVatPayer() : (bool) preg_match('/^RO\d/i', $cui);
            $county = $client?->getCounty() ?: ($snapshot['county'] ?? null);
            $city = $client?->getCity() ?: ($snapshot['city'] ?? null);
            $affiliated = $client?->isAffiliated() ?? false;
        } else {
            $supplier = $invoice->getSupplier();
            $country = strtoupper((string) ($supplier?->getCountry() ?: 'RO')) ?: 'RO';
            $name = (string) ($invoice->getSenderName() ?: $supplier?->getName() ?: '');
            $cui = (string) ($invoice->getSenderCif() ?: $supplier?->getCif() ?: '');
            $cnp = '';
            $vatCode = (string) ($supplier?->getVatCode() ?: '');
            $vatPayer = $supplier ? $supplier->isVatPayer() : ((bool) preg_match('/^RO\d/i', $cui) || $this->hasVat($invoice));
            $county = $supplier?->getCounty();
            $city = $supplier?->getCity();
            $affiliated = $supplier?->isAffiliated() ?? false;
        }

        $digits = preg_replace('/\D/', '', $cui) ?? '';
        $cnpDigits = preg_replace('/\D/', '', $cnp) ?? '';
        $tip = D394Rules::partnerType($country, $vatPayer);
        $denP = mb_substr(mb_strtoupper(trim($name)), 0, 200);
        $out = ['tip_partener' => $tip, 'cuiP' => null, 'digits' => $digits, 'denP' => $denP, 'taraP' => null, 'locP' => null, 'judP' => null, 'issue' => null, 'countyVote' => null, 'id' => $digits ?: $cnpDigits, 'name' => $name, 'affiliated' => $affiliated];

        if ($tip === 1) {
            if ($digits === '') {
                $out['issue'] = 'NO_ID';
            } elseif (!D394Rules::isValidCui($digits)) {
                $out['issue'] = 'INVALID_CUI';
            } else {
                $out['cuiP'] = $digits;
            }
            return $out;
        }
        if ($tip === 2) {
            // A company not registered for VAT keeps its CUI; a private person has a CNP or nothing
            if ($digits !== '' && strlen($digits) !== 13) {
                if (!D394Rules::isValidCui($digits)) {
                    $out['issue'] = 'INVALID_CUI';
                    return $out;
                }
                $out['cuiP'] = $digits;
                return $out;
            }
            $person = strlen($digits) === 13 ? $digits : $cnpDigits;
            if ($person !== '') {
                if (!D394Rules::isValidCnp($person)) {
                    $out['issue'] = 'INVALID_CUI';
                    return $out;
                }
                $out['cuiP'] = $person;
                $out['id'] = $person;
                return $out;
            }
            $judP = D394Rules::countyCode($county);
            if ($judP === null) {
                $out['issue'] = 'NO_COUNTY';
                return $out;
            }
            $out['denP'] = self::PF_LABEL;
            $out['taraP'] = 'RO';
            $out['judP'] = $judP;
            $out['countyVote'] = $judP;
            $out['id'] = '';
            $out['name'] = self::PF_LABEL;
            return $out;
        }

        // EU / non-EU partners are identified by their VAT number, with the country prefix
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $vatCode ?: $cui) ?? '');
        if ($code === '') {
            $out['issue'] = 'NO_ID';
            return $out;
        }
        // identified partners carry no address attributes (the validator refuses them next to a cuiP)
        $out['cuiP'] = mb_substr($code, 0, 50);
        $out['id'] = $out['cuiP'];
        return $out;
    }

    private function hasVat(Invoice $invoice): bool
    {
        foreach ($invoice->getLines() as $line) {
            if (bccomp((string) $line->getVatAmount(), '0', 2) > 0) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string, array{numbers: int[], count: int}> $issuedSeries */
    private function trackIssuedNumber(Invoice $invoice, array &$issuedSeries): void
    {
        $number = trim((string) $invoice->getNumber());
        if ($number === '') {
            return;
        }
        $prefix = $invoice->getDocumentSeries()?->getPrefix();
        if (!$prefix && preg_match('/^([A-Za-z]+)/', $number, $m)) {
            $prefix = $m[1];
        }
        $prefix = strtoupper((string) $prefix);
        $numeric = preg_replace('/\D/', '', substr($number, strlen($prefix))) ?? '';
        if ($numeric === '') {
            $numeric = preg_replace('/\D/', '', $number) ?? '';
        }
        if (!isset($issuedSeries[$prefix])) {
            $issuedSeries[$prefix] = ['numbers' => [], 'count' => 0];
        }
        $issuedSeries[$prefix]['count']++;
        if ($numeric !== '') {
            $issuedSeries[$prefix]['numbers'][] = (int) $numeric;
        }
    }

    /** @param array<string, array<string, mixed>> $rows */
    private function finishRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $baza = D300Populator::roundLei($row['baza']);
            $withVat = in_array($row['tip'], D394Rules::TYPES_WITH_VAT, true);
            $tva = $withVat ? D300Populator::roundLei($row['tva']) : null;
            if ($withVat && $tva !== null && abs(intdiv($baza * $row['cota'], 100) - $tva) > 5) {
                // the validator allows 5 lei of rounding drift between base × rate and the VAT
                $tva = intdiv($baza * $row['cota'], 100);
            }
            $out[] = [
                'tip' => $row['tip'],
                'tip_partener' => $row['tip_partener'],
                'cota' => $row['cota'],
                'cuiP' => $row['cuiP'],
                'denP' => $row['denP'],
                'taraP' => $row['taraP'],
                'locP' => $row['locP'],
                'judP' => $row['judP'],
                'tip_document' => $row['tip_document'],
                'nrFact' => count($row['invoices']),
                'baza' => $baza,
                'tva' => $tva,
            ];
        }
        usort($out, static fn (array $a, array $b): int => [$a['tip_partener'], $a['tip'], $a['cota'], $a['denP']] <=> [$b['tip_partener'], $b['tip'], $b['cota'], $b['denP']]);
        return $out;
    }

    /** @param array<int, array<string, mixed>> $partners */
    private function buildRezumat1(array $partners): array
    {
        $groups = [];
        foreach ($partners as $row) {
            $docN = ($row['tip_partener'] === 2 && $row['cota'] === 0) ? 1 : null;
            $key = $row['tip_partener'] . '|' . $row['cota'] . '|' . ($docN ?? '');
            if (!isset($groups[$key])) {
                $groups[$key] = ['tip_partener' => $row['tip_partener'], 'cota' => $row['cota'], 'document_N' => $docN, 'rows' => []];
            }
            $groups[$key]['rows'][] = $row;
        }
        ksort($groups);

        $out = [];
        foreach ($groups as $g) {
            $r = ['tip_partener' => $g['tip_partener'], 'cota' => $g['cota']];
            foreach (D394Rules::rezumat1Groups($g['tip_partener'], $g['cota']) as $tip) {
                $facturi = 0;
                $baza = 0;
                $tva = 0;
                foreach ($g['rows'] as $row) {
                    if ($row['tip'] !== $tip) {
                        continue;
                    }
                    $facturi += $row['nrFact'];
                    $baza += $row['baza'];
                    $tva += (int) ($row['tva'] ?? 0);
                }
                $r['facturi' . $tip] = $facturi;
                if ($tip === 'N') {
                    $r['document_N'] = 1;
                }
                $r['baza' . $tip] = $baza;
                if (in_array($tip, D394Rules::TYPES_WITH_VAT, true)) {
                    $r['tva' . $tip] = $tva;
                }
            }
            $out[] = $r;
        }
        return $out;
    }

    /** @param array<int, array<string, mixed>> $partners */
    private function buildRezumat2(array $partners): array
    {
        $byRate = [];
        foreach ($partners as $row) {
            if ($row['cota'] === 0) {
                continue;
            }
            $cota = $row['cota'];
            if (!isset($byRate[$cota])) {
                $byRate[$cota] = [
                    'cota' => $cota,
                    'bazaFSLcod' => 0, 'TVAFSLcod' => 0, 'bazaFSL' => 0, 'TVAFSL' => 0, 'bazaFSA' => 0, 'TVAFSA' => 0,
                    'bazaFSAI' => 0, 'TVAFSAI' => 0, 'bazaBFAI' => 0, 'TVABFAI' => 0,
                    'nrFacturiL' => 0, 'bazaL' => 0, 'tvaL' => 0,
                    'nrFacturiA' => 0, 'bazaA' => 0, 'tvaA' => 0,
                    'nrFacturiAI' => 0, 'bazaAI' => 0, 'tvaAI' => 0,
                ];
                if ($cota !== 24) {
                    $byRate[$cota] += ['baza_incasari_i1' => 0, 'tva_incasari_i1' => 0, 'baza_incasari_i2' => 0, 'tva_incasari_i2' => 0];
                }
            }
            $slot = match ($row['tip']) { 'L', 'V' => 'L', 'A', 'C' => 'A', 'AI' => 'AI', default => null };
            if ($slot === null) {
                continue;
            }
            $byRate[$cota]['nrFacturi' . $slot] += $row['nrFact'];
            $byRate[$cota]['baza' . $slot] += $row['baza'];
            $byRate[$cota]['tva' . $slot] += (int) ($row['tva'] ?? 0);
        }
        krsort($byRate);
        return array_values($byRate);
    }

    /** @param array<string, array{numbers: int[], count: int}> $issuedSeries */
    private function buildSeries(Company $company, array $issuedSeries): array
    {
        $allocated = [];
        if ($this->documentSeriesRepository !== null) {
            foreach ($this->documentSeriesRepository->findByCompany($company) as $series) {
                if ($series->getType() !== 'invoice') {
                    continue;
                }
                $allocated[strtoupper((string) $series->getPrefix())] = $series->getCurrentNumber();
            }
        }
        $out = [];
        ksort($issuedSeries);
        foreach ($issuedSeries as $prefix => $s) {
            if ($s['numbers'] === []) {
                continue;
            }
            $first = min($s['numbers']);
            $last = max($s['numbers']);
            $out[] = ['tip' => 1, 'serieI' => $prefix !== '' ? $prefix : null, 'nrI' => '1', 'nrF' => (string) max($allocated[$prefix] ?? 0, $last)];
            $out[] = ['tip' => 2, 'serieI' => $prefix !== '' ? $prefix : null, 'nrI' => (string) $first, 'nrF' => (string) $last];
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private function emptyInfo(): array
    {
        return [
            'tvaCol' => [], 'tvaDed' => [], 'BUN' => [], 'Prest' => [], 'achizitiiB' => [], 'achizitiiS' => [],
            'valoareScutit' => '0.00', 'PrestScutit' => '0.00', 'LIntra' => '0.00', 'PrestIntra' => '0.00', 'Export' => '0.00',
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $partners
     * @param array<int, array<string, mixed>> $rezumat2
     * @param array<string, mixed> $info
     */
    private function buildInformatii(Company $company, array $partners, array $rezumat2, array $info, int $nrFacturi, bool $hasSeries): array
    {
        $distinct = [1 => [], 3 => [], 4 => []];
        $nrCui2 = 0;
        $hasSales = false;
        foreach ($partners as $row) {
            if ($row['tip_partener'] === 2) {
                $nrCui2++;
            } else {
                $distinct[$row['tip_partener']][(string) $row['cuiP']] = true;
            }
            if (in_array($row['tip'], ['L', 'LS', 'V'], true)) {
                $hasSales = true;
            }
        }

        $out = [
            'nrCui1' => count($distinct[1]),
            'nrCui2' => $nrCui2,
            'nrCui3' => count($distinct[3]),
            'nrCui4' => count($distinct[4]),
            'nr_BF_i1' => 0,
            'incasari_i1' => 0,
            'incasari_i2' => 0,
            'nrFacturi_terti' => 0,
            'nrFacturi_benef' => 0,
            'nrFacturi' => $hasSeries ? $nrFacturi : 0,
        ];
        if ($company->isVatOnCollection()) {
            foreach (D394Rules::RATES as $rate) {
                $out['tvaDed' . $rate] = D300Populator::roundLei($info['tvaDed'][$rate] ?? '0.00');
            }
            foreach (D394Rules::RATES as $rate) {
                $out['tvaCol' . $rate] = D300Populator::roundLei($info['tvaCol'][$rate] ?? '0.00');
            }
        }
        foreach ([21, 11, 20, 19, 9, 5] as $rate) {
            $out['tvaDedAI' . $rate] = 0;
        }
        // The refund block ("se solicita rambursarea sumei negative din decont") is written only
        // when solicit = 1; its attributes are 0 / 1 flags saying which kinds of operations occurred
        $flag = static fn (string $amount): int => bccomp($amount, '0', 2) > 0 ? 1 : 0;
        $out['solicit'] = 0;
        $out['achizitiiPE'] = 0;
        $out['achizitiiCR'] = 0;
        $out['achizitiiCB'] = 0;
        $out['achizitiiCI'] = 0;
        $out['achizitiiA'] = 0;
        foreach (D394Rules::RATES as $rate) {
            $out['achizitiiB' . $rate] = $flag($info['achizitiiB'][$rate] ?? '0.00');
        }
        foreach (D394Rules::RATES as $rate) {
            $out['achizitiiS' . $rate] = $flag($info['achizitiiS'][$rate] ?? '0.00');
        }
        $out['importB'] = 0;
        $out['acINecorp'] = 0;
        $out['livrariBI'] = 0;
        foreach (D394Rules::RATES as $rate) {
            $out['BUN' . $rate] = $flag($info['BUN'][$rate] ?? '0.00');
        }
        $out['valoareScutit'] = $flag($info['valoareScutit']);
        $out['BunTI'] = 0;
        foreach (D394Rules::RATES as $rate) {
            $out['Prest' . $rate] = $flag($info['Prest'][$rate] ?? '0.00');
        }
        $out['PrestScutit'] = $flag($info['PrestScutit']);
        $out['LIntra'] = $flag($info['LIntra']);
        $out['PrestIntra'] = $flag($info['PrestIntra']);
        $out['Export'] = $flag($info['Export']);
        $out['livINecorp'] = 0;
        $out['efectuat'] = $hasSales ? 1 : 0;

        return $out;
    }

    private function buildHeader(Company $company, int $year, int $month, string $periodType, bool $hasOperations, bool $hasAffiliated = false): array
    {
        $quarterly = $periodType === 'quarterly';
        $luna = $quarterly ? (int) ceil($month / 3) * 3 : $month;
        $representative = trim((string) $company->getRepresentative());
        $role = trim((string) $company->getRepresentativeRole()) ?: 'Administrator';
        $address = trim(implode(', ', array_filter([$company->getAddress(), $company->getCity(), $company->getState()], static fn ($v) => $v !== null && trim((string) $v) !== '')));
        $phone = substr(preg_replace('/[^0-9+]/', '', (string) $company->getPhone()) ?? '', 0, 15);

        return [
            'luna' => $luna,
            'an' => $year,
            'tip_D394' => $quarterly ? 'T' : 'L',
            'sistemTVA' => $company->isVatOnCollection() ? 1 : 0,
            'op_efectuate' => $hasOperations ? 1 : 0,
            'cui' => (string) $company->getCif(),
            'caen' => (string) ($company->getCaenCode() ?? ''),
            'den' => mb_substr((string) $company->getName(), 0, 200),
            'adresa' => mb_substr($address, 0, 1000),
            'telefon' => $phone,
            'mail' => mb_substr((string) $company->getEmail(), 0, 200),
            'denR' => mb_substr($representative, 0, 200),
            'functie_reprez' => mb_substr($role, 0, 100),
            'adresaR' => mb_substr($address, 0, 1000),
            'tip_intocmit' => 0,
            'den_intocmit' => mb_substr($representative, 0, 75),
            'cif_intocmit' => (string) $company->getCif(),
            'calitate_intocmit' => mb_substr($role, 0, 75),
            'optiune' => 0,
            'prsAfiliat' => $hasAffiliated ? 1 : 0,
        ];
    }

    private function headerWarnings(Company $company): array
    {
        $warnings = [];
        if (!preg_match('/^\d{4}$/', (string) $company->getCaenCode())) {
            $warnings[] = ['code' => 'MISSING_CAEN', 'message' => 'Codul CAEN al companiei lipseste; declaratia nu poate fi validata fara el (Companie → Setari → Cod CAEN).'];
        }
        if (trim((string) $company->getRepresentative()) === '') {
            $warnings[] = ['code' => 'MISSING_REPRESENTATIVE', 'message' => 'Reprezentantul companiei (nume si prenume) lipseste; este obligatoriu in antetul declaratiei.'];
        }
        if (trim((string) $company->getAddress()) === '') {
            $warnings[] = ['code' => 'MISSING_ADDRESS', 'message' => 'Adresa companiei lipseste; este obligatorie in antetul declaratiei.'];
        }
        if (preg_replace('/\D/', '', (string) $company->getPhone()) === '') {
            $warnings[] = ['code' => 'MISSING_PHONE', 'message' => 'Telefonul companiei lipseste; este obligatoriu in antetul declaratiei.'];
        }
        return $warnings;
    }

    /**
     * @param array<string, array<string, mixed>> $bucket
     * @param array<string, mixed> $partner
     */
    private function addDisplay(array &$bucket, array $partner, string $invoiceId, int $cota, string $base, string $vat): void
    {
        $key = $partner['tip_partener'] . '|' . $partner['id'];
        if (!isset($bucket[$key])) {
            $bucket[$key] = [
                'partnerCif' => $partner['id'],
                'partnerName' => $partner['name'],
                'tipPartener' => $partner['tip_partener'],
                'invoiceCount' => 0,
                'invoices' => [],
                'byRate' => [],
                'total' => ['taxableBase' => '0.00', 'vatAmount' => '0.00'],
            ];
        }
        $p = &$bucket[$key];
        if (!isset($p['invoices'][$invoiceId])) {
            $p['invoices'][$invoiceId] = true;
            $p['invoiceCount']++;
        }
        $rateKey = (string) $cota;
        if (!isset($p['byRate'][$rateKey])) {
            $p['byRate'][$rateKey] = ['cota' => $cota, 'taxableBase' => '0.00', 'vatAmount' => '0.00'];
        }
        $p['byRate'][$rateKey]['taxableBase'] = bcadd($p['byRate'][$rateKey]['taxableBase'], $base, 2);
        $p['byRate'][$rateKey]['vatAmount'] = bcadd($p['byRate'][$rateKey]['vatAmount'], $vat, 2);
        $p['total']['taxableBase'] = bcadd($p['total']['taxableBase'], $base, 2);
        $p['total']['vatAmount'] = bcadd($p['total']['vatAmount'], $vat, 2);
        unset($p);
    }

    /** @param array<string, array<string, mixed>> $partners */
    private function rateSummary(array $partners): array
    {
        $byRate = [];
        foreach ($partners as $p) {
            foreach ($p['byRate'] as $rateKey => $amounts) {
                if (!isset($byRate[$rateKey])) {
                    $byRate[$rateKey] = ['cota' => $amounts['cota'], 'taxableBase' => '0.00', 'vatAmount' => '0.00', 'partnerCount' => 0];
                }
                $byRate[$rateKey]['taxableBase'] = bcadd($byRate[$rateKey]['taxableBase'], $amounts['taxableBase'], 2);
                $byRate[$rateKey]['vatAmount'] = bcadd($byRate[$rateKey]['vatAmount'], $amounts['vatAmount'], 2);
                $byRate[$rateKey]['partnerCount']++;
            }
        }
        krsort($byRate, SORT_NUMERIC);
        return array_values($byRate);
    }

    /** Line amounts in RON (foreign-currency invoices carry their BNR rate). */
    private function toRon(?string $amount, Invoice $invoice): string
    {
        $amount = $amount ?? '0.00';
        if ($invoice->getCurrency() === 'RON') {
            return $amount;
        }
        $rate = $invoice->getExchangeRate();
        return $rate && bccomp($rate, '0', 6) > 0 ? bcmul($amount, $rate, 2) : $amount;
    }

    private static function normalizeRate(?string $rate): int
    {
        return (int) round((float) ($rate ?? '0'));
    }
}
