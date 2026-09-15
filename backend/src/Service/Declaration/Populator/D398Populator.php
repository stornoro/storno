<?php

namespace App\Service\Declaration\Populator;

use App\Entity\Company;
use App\Entity\Invoice;
use App\Enum\InvoiceDirection;
use App\Enum\InvoiceTypeCode;
use App\Repository\InvoiceRepository;
use App\Service\Declaration\DeclarationDataPopulatorInterface;
use App\Service\EuVatRateService;
use App\Service\ExchangeRateService;

/**
 * Populates D398 (declarația specială de TVA — OSS, regimul UE) from the company's sales under
 * the special regime of art. 314–315 Cod fiscal: distance sales of goods and services to
 * consumers in other member states, taxed at the rate of the state of consumption.
 *
 * Quarterly. One <MS> per member state of consumption, one <SUPPLY> per (state, supply type
 * goods / services, VAT rate type standard / reduced, rate). Amounts are in EUR, the only
 * currency the form accepts:
 *
 *  - EUR invoices are taken as they are;
 *  - RON invoices are converted at the ECB reference rate of the last day of the quarter or,
 *    when none was published that day, of the next publication day (art. 315 Cod fiscal;
 *    ExchangeRateService::getRateForDate). While the quarter is still running, or when the
 *    ECB is unreachable, the BNR rate of the day is used instead and the declaration carries
 *    the EUR_RATE_FALLBACK warning so the user regenerates it once the quarter has ended;
 *  - other currencies go to RON with the invoice's exchange rate and then to EUR as above.
 *
 * The VAT rate is the destination state's: a line's own rate is kept when it is one of that
 * state's rates (EuVatRateService, with the list ANAF's validator ships as a fallback), else
 * the standard rate applies; vat_rate_type is 1 for the standard rate, 2 for a reduced one.
 * VAT = base × rate / 100 rounded to 2 decimals, as the validator recomputes it.
 *
 * `rows` holds the header attributes under ANAF's names; `states` the MS blocks with their supplies.
 */
class D398Populator implements DeclarationDataPopulatorInterface
{
    public const EU = D301Populator::EU;

    public const SCHEME_UNION = 1;       // moes_voes_imp: 1 = regimul UE (RO), 2 = regimul non-UE, 3 = regimul de import
    public const SUPPLY_GOODS = 1;
    public const SUPPLY_SERVICES = 2;
    public const TRADE_FROM_MSID = 1;    // supplies from the member state of identification (Romania)
    public const RATE_STANDARD = 1;
    public const RATE_REDUCED = 2;

    /**
     * Rates ANAF's D398 validator knows per member state (standard, then reduced), used when
     * the live EU rate feed is unavailable. The validator refuses a rate outside this list.
     */
    public const VALIDATOR_RATES = [
        'AT' => [20, 10, 13], 'BE' => [21, 12, 6], 'BG' => [20, 9], 'CY' => [19, 5, 9], 'CZ' => [21, 12],
        'DE' => [19, 7], 'DK' => [25], 'EE' => [20, 9], 'ES' => [21, 10, 4], 'FI' => [25.5, 14, 10],
        'FR' => [20, 10, 5.5, 2.1], 'EL' => [24, 13, 6], 'HR' => [25, 5, 13], 'HU' => [27, 18, 5],
        'IE' => [23, 4.8, 9, 13.5], 'IT' => [22, 10, 5, 4], 'LT' => [21, 9, 5], 'LU' => [17, 8, 14, 3],
        'LV' => [21, 12], 'MT' => [18, 5, 7], 'NL' => [21, 9], 'PL' => [23, 8, 5], 'PT' => [23, 13, 6],
        'SE' => [25, 12, 6], 'SI' => [22, 9.5], 'SK' => [23, 19, 5], 'XI' => [20, 5],
    ];

    public function __construct(
        private readonly InvoiceRepository $invoiceRepository,
        private readonly ?EuVatRateService $euVatRates = null,
        private readonly ?ExchangeRateService $exchangeRates = null,
    ) {}

    public function supportsType(string $type): bool
    {
        return $type === 'd398';
    }

    public function populate(Company $company, int $year, int $month, string $periodType): array
    {
        [$from, $to] = D300Populator::periodBounds($year, $month, 'quarterly');
        $invoices = $this->invoiceRepository->findForVatReturn($company, $from, $to);

        $counts = ['issued' => 0, 'oss' => 0, 'domestic' => 0, 'nonEu' => 0, 'skipped' => 0];
        $warnings = [];
        $eurRate = null;
        $eurRateInfo = null;
        $eurRateUsed = false;
        $eurRateMissing = [];
        $ratesOffline = [];
        $ratesUnknown = [];
        $northernIrelandServices = 0;

        /** @var array<string, array{state: string, supply: int, rateType: int, rate: string, base: string}> */
        $groups = [];

        foreach ($invoices as $invoice) {
            if ($invoice->getDirection() !== InvoiceDirection::OUTGOING) {
                continue;
            }
            $counts['issued']++;
            if ($invoice->getInvoiceTypeCode() !== InvoiceTypeCode::SPECIAL_REGIME_ART_314_315->value) {
                continue;
            }
            $counts['oss']++;
            $country = strtoupper(trim((string) ($invoice->getClient()?->getCountry() ?: ($invoice->getBuyerSnapshot()['country'] ?? 'RO')))) ?: 'RO';
            if ($country === 'GR') {
                $country = 'EL';
            }
            if ($country === 'RO') {
                $counts['domestic']++;
                continue;
            }
            if (!in_array($country, self::EU, true)) {
                $counts['nonEu']++;
                continue;
            }

            $rates = $this->countryRates($country, $ratesOffline);
            foreach ($invoice->getLines() as $line) {
                $isService = $line->getProduct()?->isService() ?? false;
                if ($country === 'XI' && $isService) {
                    $northernIrelandServices++; // Northern Ireland is in the EU VAT area for goods only
                    continue;
                }
                $lineRate = (float) ($line->getVatRate() ?? 0);
                if ($rates === null) {
                    if ($lineRate <= 0) {
                        $counts['skipped']++;
                        continue;
                    }
                    $ratesUnknown[] = $country;
                    $rate = $lineRate;
                    $rateType = self::RATE_STANDARD;
                } else {
                    $standard = (float) $rates[0];
                    if ($lineRate > 0 && in_array($lineRate, array_map('floatval', $rates), true)) {
                        $rate = $lineRate;
                    } else {
                        if ($lineRate > 0) {
                            $ratesUnknown[] = $country;
                        }
                        $rate = $standard;
                    }
                    $rateType = abs($rate - $standard) < 0.001 ? self::RATE_STANDARD : self::RATE_REDUCED;
                }

                $base = $this->toEur($line->getLineTotal(), $invoice, $to, $eurRate, $eurRateInfo, $eurRateUsed, $eurRateMissing);
                if ($base === null) {
                    $counts['skipped']++;
                    continue;
                }
                $rateKey = rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.');
                $key = implode('|', [$country, $isService ? self::SUPPLY_SERVICES : self::SUPPLY_GOODS, $rateType, $rateKey]);
                $groups[$key] ??= ['state' => $country, 'supply' => $isService ? self::SUPPLY_SERVICES : self::SUPPLY_GOODS, 'rateType' => $rateType, 'rate' => $rateKey, 'base' => '0.00'];
                $groups[$key]['base'] = bcadd($groups[$key]['base'], $base, 2);
            }
        }

        // MS blocks with their supplies; totals as the validator recomputes them
        $states = [];
        foreach ($groups as $g) {
            if (bccomp($g['base'], '0', 2) <= 0) {
                continue; // the validator refuses a non-positive taxable amount
            }
            $vat = number_format(round((float) $g['base'] * (float) $g['rate'] / 100, 2), 2, '.', '');
            $states[$g['state']] ??= [
                'mscon_state' => $g['state'],
                'vat_total_goods_msid' => '0.00',
                'vat_total_services_msid' => '0.00',
                'vat_total_goods_msest' => '0.00',
                'vat_total_services_msest' => '0.00',
                'grand_total' => '0.00',
                'due_balance' => '0.00',
                'supplies' => [],
            ];
            $states[$g['state']]['supplies'][] = [
                'trade_type' => (string) self::TRADE_FROM_MSID,
                'supply_type' => (string) $g['supply'],
                'vat_rate_type' => (string) $g['rateType'],
                'vat_rate' => $g['rate'],
                'taxable_amount' => $g['base'],
                'vat_amount' => $vat,
            ];
            $totalKey = $g['supply'] === self::SUPPLY_SERVICES ? 'vat_total_services_msid' : 'vat_total_goods_msid';
            $states[$g['state']][$totalKey] = bcadd($states[$g['state']][$totalKey], $vat, 2);
        }
        ksort($states);
        $grandTotalDue = '0.00';
        foreach ($states as &$ms) {
            usort($ms['supplies'], static fn (array $a, array $b): int => [$a['supply_type'], $a['vat_rate_type'], (float) $b['vat_rate']] <=> [$b['supply_type'], $b['vat_rate_type'], (float) $a['vat_rate']]);
            $ms['grand_total'] = bcadd(bcadd($ms['vat_total_goods_msid'], $ms['vat_total_services_msid'], 2), bcadd($ms['vat_total_goods_msest'], $ms['vat_total_services_msest'], 2), 2);
            $ms['due_balance'] = $ms['grand_total']; // no corrections of earlier periods
            if (bccomp($ms['due_balance'], '0', 2) > 0) {
                $grandTotalDue = bcadd($grandTotalDue, $ms['due_balance'], 2);
            }
        }
        unset($ms);
        $states = array_values($states);

        $nil = $states === [] ? 1 : 0;
        $rows = [
            'an_r' => (string) $year,
            'luna_r' => $to->format('n'),
            'd_rec' => '0',
            'moes_voes_imp' => (string) self::SCHEME_UNION,
            'e_int' => '0',
            'period_start_date' => $from->format('d.m.Y'),
            'period_end_date' => $to->format('d.m.Y'),
            'nil_vat_return' => (string) $nil,
            'currency' => 'EUR',
            'vat_id_no' => self::vatIdNo($company),
            'name' => mb_substr((string) $company->getName(), 0, 100),
            'grand_total_vat_due' => $grandTotalDue,
            'totalPlata_A' => (string) (self::SCHEME_UNION + $nil),
        ];

        if (!$company->isVatPayer()) {
            $warnings[] = ['code' => 'COMPANY_NOT_VAT_PAYER', 'message' => 'Compania nu este inregistrata in scopuri de TVA; regimul UE (OSS) cere un cod de TVA valid in Romania.'];
        }
        if ($eurRateUsed && ($eurRateInfo['source'] ?? null) !== 'ecb') {
            $warnings[] = ['code' => 'EUR_RATE_FALLBACK', 'message' => sprintf('Cursul BCE din ultima zi a trimestrului (%s) nu este inca disponibil; facturile in lei au fost convertite la cursul BNR al zilei (%s). Regenerati declaratia dupa incheierea trimestrului.', $to->format('d.m.Y'), $eurRate !== null ? number_format($eurRate, 4, '.', '') : '-')];
        }
        if ($eurRateMissing) {
            $warnings[] = ['code' => 'MISSING_EUR_RATE', 'message' => sprintf('Nu exista curs EUR pentru conversia facturilor %s; liniile nu au fost incluse.', implode(', ', array_unique($eurRateMissing)))];
        }
        if ($ratesOffline) {
            $warnings[] = ['code' => 'EU_RATES_OFFLINE', 'message' => sprintf('Cotele de TVA pentru %s au fost luate din lista validatorului ANAF (sursa online indisponibila).', implode(', ', array_unique($ratesOffline)))];
        }
        if ($ratesUnknown) {
            $warnings[] = ['code' => 'RATE_NOT_IN_LIST', 'message' => sprintf('Linii cu o cota care nu exista in statul de consum (%s); s-a aplicat cota standard a statului.', implode(', ', array_unique($ratesUnknown)))];
        }
        if ($counts['domestic'] > 0) {
            $warnings[] = ['code' => 'DOMESTIC_EXCLUDED', 'message' => sprintf('%d facturi in regim special catre clienti din Romania nu intra in D398.', $counts['domestic'])];
        }
        if ($counts['nonEu'] > 0) {
            $warnings[] = ['code' => 'NON_EU_EXCLUDED', 'message' => sprintf('%d facturi in regim special catre clienti din afara UE nu intra in D398.', $counts['nonEu'])];
        }
        if ($northernIrelandServices > 0) {
            $warnings[] = ['code' => 'XI_SERVICES_EXCLUDED', 'message' => sprintf('%d linii de servicii catre Irlanda de Nord (XI) nu intra in regimul UE.', $northernIrelandServices)];
        }
        if ($nil) {
            $warnings[] = ['code' => 'NO_OPERATIONS', 'message' => 'Nicio vanzare in regim special (art. 314-315) catre consumatori din alte state membre in trimestru; declaratia se depune fara operatiuni.'];
        }

        return [
            'period' => ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')],
            'rows' => $rows,
            'states' => $states,
            'totals' => ['vatDue' => $grandTotalDue, 'currency' => 'EUR'],
            'eurRate' => $eurRateUsed ? $eurRateInfo : null,
            'invoiceCounts' => $counts,
            'warnings' => $warnings,
        ];
    }

    /** The company's VAT identification for the union scheme: its VAT code, or RO + CIF. */
    public static function vatIdNo(Company $company): string
    {
        $code = strtoupper(preg_replace('/\s+/', '', (string) $company->getVatCode()) ?? '');
        if ($code !== '' && str_starts_with($code, 'RO')) {
            return $code;
        }
        return 'RO' . $company->getCif();
    }

    /** @return list<float|int>|null standard rate first, then the reduced ones */
    private function countryRates(string $country, array &$offline): ?array
    {
        $live = null;
        try {
            $live = $this->euVatRates?->getAllRates($country === 'EL' ? 'GR' : $country) ?? $this->euVatRates?->getAllRates($country);
        } catch (\Throwable) {
            $live = null;
        }
        if (is_array($live) && isset($live['standard'])) {
            $rates = [(float) $live['standard']];
            foreach ($live as $k => $v) {
                if ($k !== 'standard' && is_numeric($v) && (float) $v > 0) {
                    $rates[] = (float) $v;
                }
            }
            return $rates;
        }
        if (isset(self::VALIDATOR_RATES[$country])) {
            $offline[] = $country;
            return self::VALIDATOR_RATES[$country];
        }
        return null;
    }

    /**
     * The EUR rate of the return: ECB, last day of the quarter or the next publication day;
     * the BNR rate of the day while the quarter is running or when the ECB is unreachable.
     *
     * @return array{rate: string, date: string, source: string}|null
     */
    private function eurRate(\DateTimeInterface $quarterEnd): ?array
    {
        try {
            $dated = $this->exchangeRates?->getRateForDate('EUR', $quarterEnd, true);
            if (is_array($dated) && ($dated['rate'] ?? 0) > 0) {
                return ['rate' => number_format((float) $dated['rate'], 4, '.', ''), 'date' => (string) $dated['date'], 'source' => 'ecb'];
            }
        } catch (\Throwable) {
            // fall through to the live rate
        }
        try {
            $live = $this->exchangeRates?->getRate('EUR');
        } catch (\Throwable) {
            $live = null;
        }
        if ($live === null || $live <= 0) {
            return null;
        }
        return ['rate' => number_format($live, 4, '.', ''), 'date' => date('Y-m-d'), 'source' => 'bnr'];
    }

    private function toEur(string $amount, Invoice $invoice, \DateTimeInterface $quarterEnd, ?float &$eurRate, ?array &$eurRateInfo, bool &$eurRateUsed, array &$missing): ?string
    {
        $currency = strtoupper($invoice->getCurrency() ?: 'RON');
        if ($currency === 'EUR') {
            return number_format((float) $amount, 2, '.', '');
        }
        $ron = $amount;
        if ($currency !== 'RON') {
            $rate = $invoice->getExchangeRate();
            $ron = ($rate !== null && bccomp($rate, '0', 6) > 0) ? bcmul($amount, $rate, 4) : $amount;
        }
        if ($eurRateInfo === null) {
            $eurRateInfo = $this->eurRate($quarterEnd);
            $eurRate = $eurRateInfo !== null ? (float) $eurRateInfo['rate'] : null;
        }
        if ($eurRate === null || $eurRate <= 0) {
            $missing[] = (string) $invoice->getNumber();
            return null;
        }
        $eurRateUsed = true;
        return number_format((float) $ron / $eurRate, 2, '.', '');
    }
}
