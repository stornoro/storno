<?php

namespace App\Service\Declaration\Populator;

use App\Entity\Company;
use App\Entity\Invoice;
use App\Entity\TaxDeclaration;
use App\Enum\DeclarationType;
use App\Enum\InvoiceDirection;
use App\Enum\InvoiceTypeCode;
use App\Repository\BankAccountRepository;
use App\Repository\InvoiceRepository;
use App\Repository\TaxDeclarationRepository;
use App\Service\Declaration\D300\D300Layout;
use App\Service\Declaration\DeclarationDataPopulatorInterface;

/**
 * Populates D300 (decontul de TVA) from the company's invoices.
 *
 * The rules are the ones the VAT journals apply when the decont is filled by hand:
 *
 * - sales are taken by issue date; purchases by the date they were recorded (received)
 *   and, when the supplier's invoice is dated before the period, they go to the
 *   regularisation row (rd. 33 "Regularizări taxă dedusă") instead of rd. 24 / 25;
 * - lines at a rate the form no longer has (19 / 9 / 5 % from 2026) are regularisations:
 *   rd. 16 for sales, rd. 33 for purchases;
 * - reverse-charge purchases (domestic AE, intra-community goods, services from abroad)
 *   are self-assessed: the same base and VAT appear on the collected side (rd. 5 / 7 / 12)
 *   and on the deductible side (rd. 20 / 22 / 26);
 * - a sale to our own CUI (autofactura) is a collected-VAT regularisation (rd. 16);
 * - every row is rounded to whole lei, half up, after grouping; totals are sums of rows.
 *
 * Output `rows` is keyed by ANAF's XML attribute names (R9_1 = base, R9_2 = VAT, …) so the
 * XML generator writes them as they are; see D300Layout for the attribute ↔ printed row map.
 */
class D300Populator implements DeclarationDataPopulatorInterface
{
    private const EU = ['AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'GR', 'ES', 'FI', 'FR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'SE', 'SI', 'SK', 'XI'];

    public function __construct(
        private readonly InvoiceRepository $invoiceRepository,
        private readonly TaxDeclarationRepository $taxDeclarationRepository,
        private readonly ?BankAccountRepository $bankAccountRepository = null,
    ) {}

    public function supportsType(string $type): bool
    {
        return $type === 'd300';
    }

    public function populate(Company $company, int $year, int $month, string $periodType): array
    {
        [$from, $to] = self::periodBounds($year, $month, $periodType);
        $layout = D300Layout::forPeriodStart($from);

        $invoices = $this->invoiceRepository->findForVatReturn($company, $from, $to);

        /** @var array<string, string> $sum attribute → 2-decimal sum */
        $sum = [];
        $add = function (string $attr, string $amount) use (&$sum): void {
            $sum[$attr] = bcadd($sum[$attr] ?? '0.00', $amount, 2);
        };

        $counts = ['issued' => 0, 'received' => 0, 'regularisations' => 0, 'unmapped' => 0];
        $ownCif = preg_replace('/\D/', '', (string) $company->getCif());
        $fromYmd = $from->format('Y-m-d');

        foreach ($invoices as $invoice) {
            $direction = $invoice->getDirection();
            if ($direction === null) {
                continue;
            }
            $isSale = $direction === InvoiceDirection::OUTGOING;
            $counts[$isSale ? 'issued' : 'received']++;

            $issueYmd = $invoice->getIssueDate()?->format('Y-m-d') ?? $fromYmd;
            $datedBeforePeriod = $issueYmd < $fromYmd;

            $counterpartyCountry = $isSale
                ? strtoupper($invoice->getClient()?->getCountry() ?: ($invoice->getBuyerSnapshot()['country'] ?? 'RO'))
                : strtoupper($invoice->getSupplier()?->getCountry() ?: 'RO');
            $counterpartyCountry = $counterpartyCountry ?: 'RO';
            $isEu = $counterpartyCountry !== 'RO' && in_array($counterpartyCountry, self::EU, true);
            $isForeign = $counterpartyCountry !== 'RO';
            $selfInvoice = $isSale && $ownCif !== '' && preg_replace('/\D/', '', (string) ($invoice->getReceiverCif() ?? $invoice->getClient()?->getCui() ?? '')) === $ownCif;
            $typeCode = $invoice->getInvoiceTypeCode();

            foreach ($invoice->getLines() as $line) {
                $base = $this->toRon($line->getLineTotal(), $invoice);
                $vat = $this->toRon($line->getVatAmount(), $invoice);
                $rate = self::normalizeRate($line->getVatRate());
                $cat = strtoupper((string) $line->getVatCategoryCode());
                $isService = $line->getProduct()?->isService() ?? false;

                if ($isSale) {
                    $placed = $this->placeSale($add, $layout, $cat, $rate, $base, $vat, $isEu, $isForeign, $selfInvoice, $typeCode, $isService);
                } else {
                    $placed = $this->placePurchase($add, $layout, $cat, $rate, $base, $vat, $isEu, $isForeign, $datedBeforePeriod, $isService);
                }
                if ($placed === 'regularisation') {
                    $counts['regularisations']++;
                } elseif ($placed === null) {
                    $counts['unmapped']++;
                }
            }
        }

        // Whole lei per row, half up (as ANAF's form and the accounting programs do), then totals as sums of rows
        $rows = [];
        foreach ($sum as $attr => $amount) {
            $rows[$attr] = self::roundLei($amount);
        }

        $rowTotal = function (array $prefixes, int $col) use ($rows): int {
            $t = 0;
            foreach ($prefixes as $p) {
                $t += (int) ($rows[$p . '_' . $col] ?? 0);
            }
            return $t;
        };
        $rows['R17_1'] = $rowTotal(D300Layout::collectedRows($layout), 1);
        $rows['R17_2'] = $rowTotal(D300Layout::collectedRows($layout), 2);
        $rows['R27_1'] = $rowTotal(D300Layout::deductibleRows($layout), 1);
        $rows['R27_2'] = $rowTotal(D300Layout::deductibleRows($layout), 2);
        $rows['R28_2'] = $rows['R27_2'];                       // sub-total dedusă (no pro-rata)
        $rows['R29_2'] = (int) ($rows['R29_2'] ?? 0);         // TVA restituită cumpărătorilor străini
        $rows['R30_1'] = (int) ($rows['R30_1'] ?? 0);         // regularizări taxă dedusă (base)
        $rows['R30_2'] = (int) ($rows['R30_2'] ?? 0);
        $rows['R31_2'] = (int) ($rows['R31_2'] ?? 0);         // ajustări pro-rata
        $rows['R32_2'] = $rows['R28_2'] + $rows['R29_2'] + $rows['R30_2'] + $rows['R31_2']; // TOTAL TAXA DEDUSA
        $rows['R33_2'] = max($rows['R32_2'] - $rows['R17_2'], 0); // suma negativă în perioadă
        $rows['R34_2'] = max($rows['R17_2'] - $rows['R32_2'], 0); // taxă de plată în perioadă
        $rows['R35_2'] = 0;                                    // sold de plată din decontul precedent, neachitat (unknown: payments are not tracked)
        $rows['R36_2'] = 0;                                    // diferențe stabilite de organele fiscale
        $rows['R37_2'] = $rows['R34_2'] + $rows['R35_2'] + $rows['R36_2'];
        $rows['R38_2'] = $this->previousNegativeBalance($company, $year, $month, $periodType);
        $rows['R39_2'] = 0;
        $rows['R40_2'] = $rows['R33_2'] + $rows['R38_2'] + $rows['R39_2'];
        $rows['R41_2'] = max($rows['R37_2'] - $rows['R40_2'], 0); // sold TVA de plată la sfârșit
        $rows['R42_2'] = max($rows['R40_2'] - $rows['R37_2'], 0); // sold sumă negativă la sfârșit

        // Sub-row consistency the validator checks: rd.12 ≥ Σ rd.12.x, rd.26 ≥ Σ rd.26.x
        foreach (['R12' => ['R12_1', 'R12_2', 'R12_3', 'R72', 'R73'], 'R25' => ['R25_1', 'R25_2', 'R25_3', 'R76', 'R77']] as $parent => $subs) {
            foreach ([1, 2] as $col) {
                $t = 0;
                foreach ($subs as $s) {
                    $t += (int) ($rows[$s . '_' . $col] ?? 0);
                }
                $rows[$parent . '_' . $col] = max((int) ($rows[$parent . '_' . $col] ?? 0), $t);
            }
        }

        ksort($rows, SORT_NATURAL);
        $rows = array_map('strval', $rows);
        $header = $this->headerAttributes($company, $periodType, $year, $month);
        $rows = $header + $rows;

        // What the ANAF validator will refuse without: say it before the user files
        $warnings = [];
        if ($header['caen'] === '') {
            $warnings[] = ['code' => 'MISSING_CAEN', 'message' => 'Codul CAEN al companiei lipseste; decontul nu poate fi validat fara el (Companie → Setari → Cod CAEN).'];
        }
        if ($header['nume_declar'] === '' || $header['prenume_declar'] === '') {
            $warnings[] = ['code' => 'MISSING_REPRESENTATIVE', 'message' => 'Reprezentantul companiei (nume si prenume) lipseste; este obligatoriu in antetul decontului.'];
        }
        if ($header['cont'] === '') {
            $warnings[] = ['code' => 'MISSING_BANK_ACCOUNT', 'message' => 'Compania nu are un cont bancar cu IBAN; decontul cere banca si contul.'];
        }
        if ($counts['unmapped'] > 0) {
            $warnings[] = ['code' => 'UNMAPPED_LINES', 'message' => sprintf('%d linii nu intra in decont (neimpozabile, regim special art. 311/312).', $counts['unmapped'])];
        }

        return [
            'layout' => $layout,
            'period' => ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')],
            'rows' => $rows,
            'totals' => [
                'collected' => $rows['R17_2'],
                'deductible' => $rows['R32_2'],
                'net' => (string) ((int) $rows['R17_2'] - (int) $rows['R32_2']),
                'toPay' => $rows['R41_2'],
                'toRecover' => $rows['R42_2'],
            ],
            'invoiceCounts' => $counts,
            'warnings' => $warnings,
        ];
    }

    /**
     * Header attributes ANAF's validator insists on (every one must exist, even when empty):
     * the declarant, the bank account, the CAEN code, the checkboxes and the payment reference.
     */
    private function headerAttributes(Company $company, string $periodType, int $year, int $month): array
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
            'tip_decont' => $periodType === 'quarterly' ? 'T' : 'L',
            'bifa_interne' => '0',       // metoda simplificată pentru operațiuni interne
            'depusReprezentant' => '0',
            'temei' => '0',              // temei legal după anularea rezervei verificării ulterioare (0 = nu e cazul, 2 = art. 105 alin. 6 CPF)
            'nume_declar' => $parts[0] ?? '',
            'prenume_declar' => $parts[1] ?? '',
            'functie_declar' => trim((string) $company->getRepresentativeRole()) ?: ($representative !== '' ? 'Administrator' : ''),
            'banca' => $bank?->getBankName() ?? '',
            'cont' => $bank?->getIban() ?? '',
            'caen' => (string) ($company->getCaenCode() ?? ''),
            'pro_rata' => '0',
            'bifa_cereale' => 'N',       // the four "bifa" boxes take D / N
            'bifa_mob' => 'N',
            'bifa_disp' => 'N',
            'bifa_cons' => 'N',
            'solicit_ramb' => 'N',
            'nr_evid' => self::paymentReference($year, $month, $periodType),
        ];
    }

    /**
     * Numărul de evidență a plății (23 digits) the form fills in by itself: "10" + tax code
     * (301 monthly decont, 302 quarterly) + "01" + period MMYY + due date 25MMYY (the 25th of
     * the month after the period) + "0000" + two check digits equal to the sum of the first
     * 21 digits — the layout and the checksum the ANAF validator enforces.
     */
    public static function paymentReference(int $year, int $month, string $periodType): string
    {
        $dueMonth = $month + 1;
        $dueYear = $year;
        if ($dueMonth > 12) {
            $dueMonth = 1;
            $dueYear++;
        }
        $body = '10' . ($periodType === 'quarterly' ? '302' : '301') . '01'
            . sprintf('%02d%02d', $month, $year % 100)
            . sprintf('25%02d%02d', $dueMonth, $dueYear % 100)
            . '0000';
        $sum = array_sum(array_map('intval', str_split($body)));
        return $body . sprintf('%02d', $sum % 100);
    }

    /**
     * @return 'row'|'regularisation'|null what happened to the line
     */
    private function placeSale(callable $add, string $layout, string $cat, string $rate, string $base, string $vat, bool $isEu, bool $isForeign, bool $selfInvoice, ?string $typeCode, bool $isService): ?string
    {
        if ($selfInvoice) {
            $add('R16_1', $base);
            $add('R16_2', $vat);
            return 'regularisation';
        }

        // Regimes decided by the invoice type
        switch ($typeCode) {
            case InvoiceTypeCode::REVERSE_CHARGE->value:
                $add('R13_1', $base);
                return 'row';
            case InvoiceTypeCode::SPECIAL_REGIME_ART_314_315->value:
                $add('R64_1', $base);
                $add('R64_2', $vat);
                return 'row';
            case InvoiceTypeCode::NON_TRANSFER->value:
            case InvoiceTypeCode::SERVICES_ART_278->value:
                $add('R3_1', $base);
                if ($isEu && $typeCode === InvoiceTypeCode::SERVICES_ART_278->value) {
                    $add('R3_1_1', $base);
                }
                return 'row';
            case InvoiceTypeCode::EXEMPT_WITH_DEDUCTION->value:
                $add('R14_1', $base);
                return 'row';
            case InvoiceTypeCode::NON_TAXABLE->value:
            case InvoiceTypeCode::SERVICES_ART_311->value:
            case InvoiceTypeCode::SALES_ART_312->value:
                return null; // outside the decont, or VAT on the margin that the invoice does not show
        }

        switch ($cat) {
            case 'AE':
                $add('R13_1', $base);
                return 'row';
            case 'K':
                if ($isService) {
                    $add('R3_1', $base);
                    $add('R3_1_1', $base);
                } else {
                    $add('R1_1', $base);
                }
                return 'row';
            case 'G':
                $add('R3_1', $base);
                return 'row';
            case 'O':
                return null;
            case 'E':
                $add('R15_1', $base);
                return 'row';
            case 'Z':
                if ($isForeign) {
                    // 0 % to a foreign customer without an explicit category: EU goods → rd.1, everything else → rd.3
                    $add($isEu && !$isService ? 'R1_1' : 'R3_1', $base);
                    if ($isEu && $isService) {
                        $add('R3_1_1', $base);
                    }
                } else {
                    $add('R14_1', $base);
                }
                return 'row';
        }

        // Standard-rated (S) — by rate; a 0 % S line to a foreign customer is treated like Z
        if (bccomp($rate, '0', 2) === 0) {
            return $this->placeSale($add, $layout, 'Z', $rate, $base, $vat, $isEu, $isForeign, false, null, $isService);
        }
        $row = D300Layout::salesRow($layout, $rate);
        if ($row === null) {
            $add('R16_1', $base);
            $add('R16_2', $vat);
            return 'regularisation';
        }
        $add($row . '_1', $base);
        $add($row . '_2', $vat);
        return 'row';
    }

    private function placePurchase(callable $add, string $layout, string $cat, string $rate, string $base, string $vat, bool $isEu, bool $isForeign, bool $datedBeforePeriod, bool $isService): ?string
    {
        $selfAssessedRate = bccomp($rate, '0', 2) > 0 ? $rate : D300Layout::standardRate($layout);
        $selfAssessedVat = bcdiv(bcmul($base, $selfAssessedRate, 4), '100', 2);

        if ($isEu) {
            // Intra-community: goods → rd.5 + 5.1 and rd.20 + 20.1; services → rd.7 + 7.1 and rd.22 + 22.1
            [$col, $ded] = $isService ? ['R7', 'R20'] : ['R5', 'R18'];
            foreach ([$col, $col . '_1', $ded, $ded . '_1'] as $r) {
                $add($r . '_1', $base);
                $add($r . '_2', $selfAssessedVat);
            }
            return 'row';
        }
        if ($isForeign) {
            if ($isService) {
                // Services from outside the EU: beneficiary pays the VAT (art. 307), rd.7 and rd.22 without the .1 sub-rows
                foreach (['R7', 'R20'] as $r) {
                    $add($r . '_1', $base);
                    $add($r . '_2', $selfAssessedVat);
                }
                return 'row';
            }
            // Imported goods: the VAT is paid in customs (DVI), the supplier invoice itself is non-taxable
            $add('R26_1', $base);
            return 'row';
        }

        if ($cat === 'AE') {
            // Domestic reverse charge: both sides, with the rate's sub-rows
            $rows = D300Layout::reverseChargeRows($layout, $selfAssessedRate);
            foreach (['R12', 'R25'] as $r) {
                $add($r . '_1', $base);
                $add($r . '_2', $selfAssessedVat);
            }
            if ($rows !== null) {
                foreach ($rows as $r) {
                    $add($r . '_1', $base);
                    $add($r . '_2', $selfAssessedVat);
                }
            }
            return 'row';
        }
        if (in_array($cat, ['E', 'Z', 'O', 'G', 'K'], true) || bccomp($rate, '0', 2) === 0) {
            $add('R26_1', $base);
            return 'row';
        }

        $row = $datedBeforePeriod ? null : D300Layout::purchasesRow($layout, $rate);
        if ($row === null) {
            $add('R30_1', $base);
            $add('R30_2', $vat);
            return 'regularisation';
        }
        $add($row . '_1', $base);
        $add($row . '_2', $vat);
        return 'row';
    }

    /** Line amounts in RON (foreign-currency invoices carry their BNR rate). */
    private function toRon(string $amount, Invoice $invoice): string
    {
        if ($invoice->getCurrency() === 'RON') {
            return $amount;
        }
        $rate = $invoice->getExchangeRate();
        return $rate && bccomp($rate, '0', 6) > 0 ? bcmul($amount, $rate, 2) : $amount;
    }

    private static function normalizeRate(?string $rate): string
    {
        $r = (float) ($rate ?? '0');
        return abs($r - round($r)) < 0.005 ? (string) (int) round($r) : rtrim(rtrim(number_format($r, 2, '.', ''), '0'), '.');
    }

    /** Half-up rounding to whole lei, the way the form is filled by hand. */
    public static function roundLei(string $amount): int
    {
        $x = (float) $amount;
        $floor = floor($x);
        if (abs(($x - $floor) - 0.5) < 1e-9) {
            return (int) ceil($x);
        }
        return (int) round($x);
    }

    /** @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} */
    public static function periodBounds(int $year, int $month, string $periodType): array
    {
        if ($periodType === 'quarterly') {
            $startMonth = ((int) ceil($month / 3) - 1) * 3 + 1;
            $from = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $startMonth));
            return [$from, $from->modify('+2 months')->modify('last day of this month')->setTime(23, 59, 59)];
        }
        $from = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        return [$from, $from->modify('last day of this month')->setTime(23, 59, 59)];
    }

    /** rd. 42 of the previous period's decont (negative VAT carried forward when no refund was requested). */
    private function previousNegativeBalance(Company $company, int $year, int $month, string $periodType): int
    {
        $step = $periodType === 'quarterly' ? 3 : 1;
        $prevMonth = $month - $step;
        $prevYear = $year;
        if ($prevMonth < 1) {
            $prevMonth += 12;
            $prevYear--;
        }
        $previous = $this->taxDeclarationRepository->findByPeriod($company, DeclarationType::D300, $prevYear, $prevMonth);
        $latest = null;
        foreach ($previous as $candidate) {
            if ($candidate instanceof TaxDeclaration) {
                $latest = $candidate;
            }
        }
        if ($latest === null) {
            return 0;
        }
        $rows = $latest->getData()['rows'] ?? [];
        if (($rows['solicit_ramb'] ?? 'N') === 'D') {
            return 0;
        }
        return (int) ($rows['R42_2'] ?? 0);
    }
}
