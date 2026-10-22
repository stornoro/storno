<?php

namespace App\Service\Report;

use App\Entity\Company;
use App\Enum\InvoiceDirection;
use App\Repository\InvoiceRepository;
use App\Service\ExchangeRateService;

class VatReportService
{
    /**
     * EU member states other than Romania: the possible states of consumption of the
     * OSS return (the Union scheme). Northern Ireland (XI) is in the VAT area for goods only.
     */
    public const OSS_COUNTRIES = [
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'EL', 'HU', 'IE', 'IT',
        'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'SK', 'SI', 'ES', 'SE', 'XI',
    ];

    /** The single account ANAF collects the special VAT return (D398, OSS) into, as printed on its receipts. */
    public const OSS_PAYMENT = [
        'iban' => 'RO30RNCB0090000695510027',
        'bic' => 'RNCBROBU',
        'bank' => 'Banca Comercială Română',
        'beneficiary' => 'Direcția Generală Regională a Finanțelor Publice București',
        'beneficiaryCif' => '4193117',
        'currency' => 'EUR',
    ];

    public function __construct(
        private readonly InvoiceRepository $invoiceRepository,
        private readonly ExchangeRateService $exchangeRates,
    ) {}

    /**
     * The quarterly OSS (One Stop Shop, Union scheme) view of the sales: VAT charged to
     * consumers in other EU member states at the rate of their country, grouped by state of
     * consumption, rate and invoice currency, and converted to euro — the currency of the
     * special VAT return (D398) — at the ECB reference rate of the last day of the quarter
     * or, when none was published that day, the next publication. The return and the
     * payment are due by the end of the month that follows the quarter.
     *
     * Counts only outgoing invoices to buyers who are not VAT registered (B2C) in an EU
     * country other than Romania, and only their lines that carry VAT; business buyers
     * (reverse charge) and domestic or non-EU sales are left out. Amounts in euro are null
     * when the ECB has no rate yet (a quarter still running or in the future).
     */
    public function generateOss(Company $company, int $year, int $quarter): array
    {
        $from = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, ($quarter - 1) * 3 + 1));
        $to = $from->modify('+3 months -1 day');
        $dueDate = $to->modify('last day of next month');

        // Same selection as the D398 populator: drafts, cancelled, rejected and converted documents are out.
        $invoices = $this->invoiceRepository->findForVatReturn($company, $from, $to->setTime(23, 59, 59));

        $buckets = [];      // country => rate => currency => ['taxableBase', 'vatAmount']
        $perCountry = [];   // country => invoice ids
        $currencies = [];
        foreach ($invoices as $invoice) {
            if ($invoice->getDirection() !== InvoiceDirection::OUTGOING) {
                continue;
            }
            $snapshot = $invoice->getBuyerSnapshot() ?? [];
            $client = $invoice->getClient();
            $country = strtoupper(trim((string) ($snapshot['country'] ?? $client?->getCountry() ?? '')));
            if ($country === 'GR') {
                $country = 'EL'; // ISO code vs the EU VAT code
            }
            $vatPayer = (bool) ($snapshot['isVatPayer'] ?? $client?->isVatPayer() ?? false);
            if ($vatPayer || !in_array($country, self::OSS_COUNTRIES, true)) {
                continue;
            }
            $currency = strtoupper($invoice->getCurrency() ?: 'RON');
            $counted = false;
            foreach ($invoice->getLines() as $line) {
                if (bccomp($line->getVatAmount(), '0', 2) <= 0) {
                    continue;
                }
                $rate = number_format((float) $line->getVatRate(), 2, '.', '');
                $bucket = &$buckets[$country][$rate][$currency];
                $bucket ??= ['taxableBase' => '0.00', 'vatAmount' => '0.00'];
                $bucket['taxableBase'] = bcadd($bucket['taxableBase'], $line->getLineTotal(), 2);
                $bucket['vatAmount'] = bcadd($bucket['vatAmount'], $line->getVatAmount(), 2);
                unset($bucket);
                $counted = true;
            }
            if ($counted) {
                $perCountry[$country][] = $invoice->getId()?->toRfc4122() ?? (string) spl_object_id($invoice);
                $currencies[$currency] = true;
            }
        }

        // Euro per unit of each invoice currency at the quarter-end ECB reference rate.
        $exchange = ['date' => null, 'rates' => [], 'missing' => []];
        $eur = $this->exchangeRates->getRateForDate('EUR', $to, true); // RON per EUR
        foreach (array_keys($currencies) as $currency) {
            if ($currency === 'EUR') {
                $exchange['rates']['EUR'] = ['eurPerUnit' => '1.000000', 'unitsPerEur' => '1.0000'];
                continue;
            }
            $cur = $this->exchangeRates->getRateForDate($currency, $to, true); // RON per unit
            if ($eur === null || $cur === null || $cur['rate'] <= 0) {
                $exchange['missing'][] = $currency;
                continue;
            }
            $exchange['date'] ??= $eur['date'];
            $exchange['rates'][$currency] = [
                'eurPerUnit' => number_format($cur['rate'] / $eur['rate'], 6, '.', ''),
                'unitsPerEur' => number_format($eur['rate'] / $cur['rate'], 4, '.', ''),
            ];
        }
        if ($exchange['date'] === null && $eur !== null && $currencies !== []) {
            $exchange['date'] = $eur['date'];
        }

        ksort($buckets);
        $countries = [];
        $totals = ['taxableBaseEur' => '0.00', 'vatAmountEur' => '0.00', 'invoiceCount' => 0, 'complete' => true];
        foreach ($buckets as $country => $byRate) {
            ksort($byRate, SORT_NUMERIC);
            $row = ['country' => $country, 'rates' => [], 'taxableBaseEur' => '0.00', 'vatAmountEur' => '0.00', 'invoiceCount' => count(array_unique($perCountry[$country] ?? []))];
            $complete = true;
            foreach ($byRate as $rate => $byCurrency) {
                foreach ($byCurrency as $currency => $bucket) {
                    $factor = $exchange['rates'][$currency]['eurPerUnit'] ?? null;
                    $baseEur = $factor === null ? null : bcmul($bucket['taxableBase'], $factor, 2);
                    $vatEur = $factor === null ? null : bcmul($bucket['vatAmount'], $factor, 2);
                    $row['rates'][] = [
                        'vatRate' => $rate,
                        'currency' => $currency,
                        'taxableBase' => $bucket['taxableBase'],
                        'vatAmount' => $bucket['vatAmount'],
                        'taxableBaseEur' => $baseEur,
                        'vatAmountEur' => $vatEur,
                    ];
                    if ($baseEur === null) {
                        $complete = false;
                        continue;
                    }
                    $row['taxableBaseEur'] = bcadd($row['taxableBaseEur'], $baseEur, 2);
                    $row['vatAmountEur'] = bcadd($row['vatAmountEur'], $vatEur, 2);
                }
            }
            if (!$complete) {
                $row['taxableBaseEur'] = $row['vatAmountEur'] = null;
                $totals['complete'] = false;
            } else {
                $totals['taxableBaseEur'] = bcadd($totals['taxableBaseEur'], $row['taxableBaseEur'], 2);
                $totals['vatAmountEur'] = bcadd($totals['vatAmountEur'], $row['vatAmountEur'], 2);
            }
            $totals['invoiceCount'] += $row['invoiceCount'];
            $countries[] = $row;
        }
        if (!$totals['complete']) {
            $totals['taxableBaseEur'] = $totals['vatAmountEur'] = null;
        }

        $vatCode = $company->getVatCode() ?: 'RO' . $company->getCif();

        return [
            'period' => sprintf('%04d-Q%d', $year, $quarter),
            'year' => $year,
            'quarter' => $quarter,
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'ossEnabled' => $company->isOss(),
            'currency' => 'EUR',
            'exchange' => $exchange + ['source' => 'ECB'],
            'countries' => $countries,
            'totals' => $totals,
            'payment' => self::OSS_PAYMENT + [
                'dueDate' => $dueDate->format('Y-m-d'),
                'reference' => sprintf('RO/%s/Q%d.%04d', $vatCode, $quarter, $year),
            ],
        ];
    }

    public function generate(Company $company, int $year, int $month): array
    {
        $dateFrom = new \DateTime(sprintf('%04d-%02d-01', $year, $month));
        $dateTo = (clone $dateFrom)->modify('last day of this month');

        $invoices = $this->invoiceRepository->findByCompanyFiltered($company, [
            'dateFrom' => $dateFrom->format('Y-m-d'),
            'dateTo' => $dateTo->format('Y-m-d'),
            'excludeCancelled' => true,
        ], 10000);

        $summary = [
            'period' => sprintf('%04d-%02d', $year, $month),
            'outgoing' => $this->initVatBuckets(),
            'incoming' => $this->initVatBuckets(),
            'totals' => [
                'outgoing' => ['taxableBase' => '0.00', 'vatAmount' => '0.00', 'total' => '0.00'],
                'incoming' => ['taxableBase' => '0.00', 'vatAmount' => '0.00', 'total' => '0.00'],
            ],
        ];

        foreach ($invoices as $invoice) {
            $direction = $invoice->getDirection() === InvoiceDirection::OUTGOING ? 'outgoing' : 'incoming';

            $subtotal = $invoice->getSubtotal();
            $vatTotal = $invoice->getVatTotal();
            $total = $invoice->getTotal();

            // Aggregate totals
            $summary['totals'][$direction]['taxableBase'] = bcadd($summary['totals'][$direction]['taxableBase'], $subtotal, 2);
            $summary['totals'][$direction]['vatAmount'] = bcadd($summary['totals'][$direction]['vatAmount'], $vatTotal, 2);
            $summary['totals'][$direction]['total'] = bcadd($summary['totals'][$direction]['total'], $total, 2);

            // Break down by VAT rate from invoice lines
            foreach ($invoice->getLines() as $line) {
                $rate = $this->normalizeRate($line->getVatRate());
                if (!isset($summary[$direction][$rate])) {
                    $summary[$direction][$rate] = ['taxableBase' => '0.00', 'vatAmount' => '0.00'];
                }
                $summary[$direction][$rate]['taxableBase'] = bcadd(
                    $summary[$direction][$rate]['taxableBase'],
                    $line->getLineTotal(),
                    2
                );
                $summary[$direction][$rate]['vatAmount'] = bcadd(
                    $summary[$direction][$rate]['vatAmount'],
                    $line->getVatAmount(),
                    2
                );
            }
        }

        // Compute net VAT position
        $summary['netVat'] = bcsub(
            $summary['totals']['outgoing']['vatAmount'],
            $summary['totals']['incoming']['vatAmount'],
            2
        );
        $summary['invoiceCount'] = [
            'outgoing' => count(array_filter($invoices, fn($i) => $i->getDirection() === InvoiceDirection::OUTGOING)),
            'incoming' => count(array_filter($invoices, fn($i) => $i->getDirection() === InvoiceDirection::INCOMING)),
        ];

        return $summary;
    }

    private function initVatBuckets(): array
    {
        return [
            '21.00' => ['taxableBase' => '0.00', 'vatAmount' => '0.00'],
            '9.00' => ['taxableBase' => '0.00', 'vatAmount' => '0.00'],
            '5.00' => ['taxableBase' => '0.00', 'vatAmount' => '0.00'],
            '0.00' => ['taxableBase' => '0.00', 'vatAmount' => '0.00'],
        ];
    }

    private function normalizeRate(string $rate): string
    {
        $numericRate = number_format((float) $rate, 2, '.', '');

        return match (true) {
            $numericRate === '21.00' => '21.00',
            $numericRate === '9.00' => '9.00',
            $numericRate === '5.00' => '5.00',
            $numericRate === '0.00' => '0.00',
            default => $numericRate,
        };
    }
}
