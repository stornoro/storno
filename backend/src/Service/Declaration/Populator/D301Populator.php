<?php

namespace App\Service\Declaration\Populator;

use App\Entity\Company;
use App\Entity\Invoice;
use App\Enum\InvoiceDirection;
use App\Repository\BankAccountRepository;
use App\Repository\InvoiceRepository;
use App\Service\Declaration\DeclarationDataPopulatorInterface;

/**
 * Populates D301 (decontul special de TVA) from the company's received invoices.
 *
 * The form is filed by persons NOT registered for VAT that bought, from abroad, something on
 * which they owe Romanian VAT themselves. One <sectiune> per supplier invoice and operation:
 *
 *  - tip_operatie 1: intra-community acquisitions of goods (supplier in another member state);
 *  - tip_operatie 4: services for which the beneficiary owes the VAT (from the EU and from
 *    outside the EU, art. 307 Cod fiscal);
 *  - tip_operatie 5: sub-total 4.1, the intra-community services of section 4 bought from a
 *    provider registered for VAT in its member state — ANAF's validator requires every 4.1 row
 *    to repeat, value for value, a section 4 row, so those rows are written twice;
 *  - sections 2 (new means of transport) and 3 (excise goods) are never filled by Storno;
 *  - goods from outside the EU are imports: the VAT is paid in customs, so they stay out.
 *
 * VAT is the standard rate (19 % before 2025-08-01, 21 % after) applied to the RON base; base
 * and VAT are whole lei per row, half up. Foreign-currency invoices carry the invoice's own
 * exchange rate (curs_valutar, 4 decimals); currencies the form does not list are converted
 * to RON and reported as RON.
 *
 * `rows` holds the header attributes and the section totals (baza1 … tva5, totalPlata_A) under
 * ANAF's attribute names; `sections` holds the rows of the "date privind obligatia de plata" table.
 */
class D301Populator implements DeclarationDataPopulatorInterface
{
    public const EU = ['AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'GR', 'ES', 'FI', 'FR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'SE', 'SI', 'SK', 'XI'];

    /** Currencies the form accepts in tip_valuta (from ANAF's D301 XSD / validator). */
    public const CURRENCIES = ['EUR', 'USD', 'AUD', 'CAD', 'CHF', 'CZK', 'DKK', 'EGP', 'GBP', 'HUF', 'JPY', 'MDL', 'NOK', 'PLN', 'RON', 'SEK', 'TRY', 'XDR', 'BGN', 'HRK'];

    public const OP_EU_GOODS = 1;
    public const OP_NEW_TRANSPORT = 2;
    public const OP_EXCISE_GOODS = 3;
    public const OP_SERVICES = 4;
    public const OP_EU_SERVICES = 5; // printed as section 4.1

    public function __construct(
        private readonly InvoiceRepository $invoiceRepository,
        private readonly ?BankAccountRepository $bankAccountRepository = null,
    ) {}

    public function supportsType(string $type): bool
    {
        return $type === 'd301';
    }

    public function populate(Company $company, int $year, int $month, string $periodType): array
    {
        [$from, $to] = D300Populator::periodBounds($year, $month, 'monthly');
        $invoices = $this->invoiceRepository->findForVatReturn($company, $from, $to);

        $sections = [];
        $counts = ['received' => 0, 'foreign' => 0, 'imports' => 0, 'domestic' => 0];
        $warnings = [];
        $unsupportedCurrencies = [];
        $missingRates = [];

        foreach ($invoices as $invoice) {
            if ($invoice->getDirection() !== InvoiceDirection::INCOMING) {
                continue;
            }
            $counts['received']++;
            $country = strtoupper(trim((string) ($invoice->getSupplier()?->getCountry() ?: 'RO'))) ?: 'RO';
            if ($country === 'RO') {
                $counts['domestic']++;
                continue;
            }
            $isEu = in_array($country, self::EU, true);

            $goods = '0.00';
            $services = '0.00';
            foreach ($invoice->getLines() as $line) {
                if ($line->getProduct()?->isService() ?? false) {
                    $services = bcadd($services, $line->getLineTotal(), 2);
                } else {
                    $goods = bcadd($goods, $line->getLineTotal(), 2);
                }
            }

            $used = false;
            if (bccomp($goods, '0', 2) > 0) {
                if ($isEu) {
                    $sections[] = $this->section($invoice, self::OP_EU_GOODS, $goods, $unsupportedCurrencies, $missingRates);
                    $used = true;
                } else {
                    $counts['imports']++;
                }
            }
            if (bccomp($services, '0', 2) > 0) {
                $row = $this->section($invoice, self::OP_SERVICES, $services, $unsupportedCurrencies, $missingRates);
                $sections[] = $row;
                $supplier = $invoice->getSupplier();
                if ($isEu && $supplier !== null && ($supplier->isVatPayer() || trim((string) $supplier->getVatCode()) !== '' || trim((string) $supplier->getCif()) !== '')) {
                    $sections[] = ['tip_operatie' => (string) self::OP_EU_SERVICES] + $row;
                }
                $used = true;
            }
            if ($used) {
                $counts['foreign']++;
            }
        }

        usort($sections, static fn (array $a, array $b): int => [(int) $a['tip_operatie'], $a['data_doc_iso'], $a['nr_doc']] <=> [(int) $b['tip_operatie'], $b['data_doc_iso'], $b['nr_doc']]);

        $totals = [];
        for ($type = 1; $type <= 5; $type++) {
            $totals['baza' . $type] = 0;
            $totals['tva' . $type] = 0;
        }
        foreach ($sections as $s) {
            $totals['baza' . $s['tip_operatie']] += (int) $s['baza'];
            $totals['tva' . $s['tip_operatie']] += (int) $s['tva'];
        }
        $totals['totalPlata_A'] = array_sum($totals);

        $header = $this->headerAttributes($company, $year, $month);
        $rows = $header + array_map('strval', $totals);

        if ($company->isVatPayer()) {
            $warnings[] = ['code' => 'COMPANY_IS_VAT_PAYER', 'message' => 'Compania este inregistrata in scopuri de TVA; decontul special (D301) se depune doar de persoanele neinregistrate, achizitiile din strainatate se declara in D300.'];
        }
        if ($header['nume_declarant'] === '' || $header['prenume_declarant'] === '') {
            $warnings[] = ['code' => 'MISSING_REPRESENTATIVE', 'message' => 'Reprezentantul companiei (nume si prenume) lipseste; este obligatoriu in antetul decontului.'];
        }
        if ($header['cont'] === '') {
            $warnings[] = ['code' => 'MISSING_BANK_ACCOUNT', 'message' => 'Compania nu are un cont bancar cu IBAN; decontul cere banca si contul.'];
        }
        if ($unsupportedCurrencies) {
            $warnings[] = ['code' => 'UNSUPPORTED_CURRENCY', 'message' => sprintf('Moneda %s nu exista in formular; facturile au fost convertite in lei cu cursul facturii.', implode(', ', array_unique($unsupportedCurrencies)))];
        }
        if ($missingRates) {
            $warnings[] = ['code' => 'MISSING_EXCHANGE_RATE', 'message' => sprintf('Facturile %s in valuta nu au curs de schimb; au fost luate la curs 1.', implode(', ', array_unique($missingRates)))];
        }
        if ($counts['imports'] > 0) {
            $warnings[] = ['code' => 'IMPORTS_EXCLUDED', 'message' => sprintf('%d facturi de bunuri din afara UE nu intra in decont (TVA se plateste in vama).', $counts['imports'])];
        }
        if (!$sections) {
            $warnings[] = ['code' => 'NO_OPERATIONS', 'message' => 'Nicio achizitie din strainatate cu TVA datorat de beneficiar in perioada; decontul nu se depune pentru luni fara operatiuni.'];
        }

        // Display rows: the key used by the web page is the printed one; drop the sort helper
        $sections = array_map(static function (array $s): array {
            unset($s['data_doc_iso']);
            return $s;
        }, $sections);

        return [
            'period' => ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')],
            'rows' => $rows,
            'sections' => $sections,
            'totals' => [
                'base' => (string) ($totals['baza1'] + $totals['baza2'] + $totals['baza3'] + $totals['baza4']),
                'vat' => (string) ($totals['tva1'] + $totals['tva2'] + $totals['tva3'] + $totals['tva4']),
                'toPay' => (string) ($totals['tva1'] + $totals['tva2'] + $totals['tva3'] + $totals['tva4']),
            ],
            'invoiceCounts' => $counts,
            'warnings' => $warnings,
        ];
    }

    /**
     * One row of the payment table. Amounts in the invoice's currency (val_valuta) with the
     * invoice's exchange rate; base and VAT in whole lei.
     */
    private function section(Invoice $invoice, int $type, string $amount, array &$unsupportedCurrencies, array &$missingRates): array
    {
        $currency = strtoupper($invoice->getCurrency() ?: 'RON');
        $rate = $invoice->getExchangeRate();
        if ($currency === 'RON') {
            $rate = '1';
        } elseif ($rate === null || bccomp($rate, '0', 6) <= 0) {
            $missingRates[] = (string) $invoice->getNumber();
            $rate = '1';
        }
        $ronAmount = bcmul($amount, $rate, 2);
        if (!in_array($currency, self::CURRENCIES, true)) {
            $unsupportedCurrencies[] = $currency;
            $currency = 'RON';
            $amount = $ronAmount;
            $rate = '1';
        }

        $issueDate = $invoice->getIssueDate();
        $base = D300Populator::roundLei($ronAmount);
        $vatRate = self::standardRate($issueDate);
        $vat = D300Populator::roundLei(bcdiv(bcmul((string) $base, $vatRate, 4), '100', 2));

        return [
            'tip_operatie' => (string) $type,
            'nr_doc' => mb_substr(trim((string) $invoice->getNumber()), 0, 20),
            'data_doc' => $issueDate?->format('d.m.Y') ?? '',
            'data_doc_iso' => $issueDate?->format('Y-m-d') ?? '',
            'val_valuta' => number_format((float) $amount, 2, '.', ''),
            'tip_valuta' => $currency,
            'curs_valutar' => number_format((float) $rate, 4, '.', ''),
            'baza' => (string) $base,
            'tva' => (string) $vat,
            'supplier' => (string) ($invoice->getSupplier()?->getName() ?? ''),
            'country' => strtoupper((string) ($invoice->getSupplier()?->getCountry() ?? '')),
            'invoiceId' => $invoice->getId()?->toRfc4122(),
        ];
    }

    /** Standard VAT rate in force at the invoice date (Law 141/2025 raised it to 21 % from 2025-08-01). */
    public static function standardRate(?\DateTimeInterface $date): string
    {
        return ($date?->format('Y-m-d') ?? '2100-01-01') >= '2025-08-01' ? '21' : '19';
    }

    /**
     * Header attributes ANAF's validator insists on. `temei` is 1 / 2 and an initial
     * declaration (d_rec = 0) must not use 1; `pers_inreg` is 1 for persons not registered
     * for VAT, 2 for those registered only for intra-community acquisitions (art. 317).
     */
    private function headerAttributes(Company $company, int $year, int $month): array
    {
        $representative = trim((string) $company->getRepresentative());
        $parts = preg_split('/\s+/', $representative, 2) ?: [];
        $bank = null;
        if ($this->bankAccountRepository !== null) {
            foreach ($this->bankAccountRepository->findByCompany($company) as $account) {
                if ($account->getIban() && ($bank === null || $account->isDefault())) {
                    $bank = $account;
                }
            }
        }

        return [
            'd_rec' => '0',
            'mijl_trans' => '0',
            'temei' => '2',
            'pers_inreg' => (!$company->isVatPayer() && trim((string) $company->getVatCode()) !== '') ? '2' : '1',
            'banca' => $bank?->getBankName() ?? '',
            'cont' => $bank?->getIban() ?? '',
            'nume_declarant' => $parts[0] ?? '',
            'prenume_declarant' => $parts[1] ?? '',
            'functia_declarant' => trim((string) $company->getRepresentativeRole()) ?: ($representative !== '' ? 'Administrator' : ''),
            'nr_evid' => self::paymentReference($year, $month),
        ];
    }

    /**
     * Numărul de evidență a plății (23 digits): "10" + tax code 301 + "01" + period MMYY +
     * due date 25MMYY (the 25th of the next month) + the mijl_trans box (0) + "000" + two
     * check digits equal to the sum of the first 21 digits — the layout the validator enforces.
     */
    public static function paymentReference(int $year, int $month, int $newTransport = 0): string
    {
        $dueMonth = $month + 1;
        $dueYear = $year;
        if ($dueMonth > 12) {
            $dueMonth = 1;
            $dueYear++;
        }
        $body = '1030101'
            . sprintf('%02d%02d', $month, $year % 100)
            . sprintf('25%02d%02d', $dueMonth, $dueYear % 100)
            . ($newTransport ? '1' : '0') . '000';
        $sum = array_sum(array_map('intval', str_split($body)));
        return $body . sprintf('%02d', $sum % 100);
    }
}
