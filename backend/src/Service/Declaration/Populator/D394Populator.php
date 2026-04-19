<?php

namespace App\Service\Declaration\Populator;

use App\Entity\Company;
use App\Entity\Invoice;
use App\Enum\InvoiceDirection;
use App\Repository\InvoiceRepository;
use App\Service\Declaration\DeclarationDataPopulatorInterface;

/**
 * Populates D394 (Declaratie informativa privind livrarile/prestarile si achizitiile)
 * data from invoice records.
 *
 * ANAF XSD structure for D394:
 *  - op1: operations with partners (livrari/achizitii per partner)
 *    - tip: 'L' (livrari/sales) or 'A' (achizitii/purchases)
 *    - tip_partener: 1=PJ Romania, 2=PF Romania, 3=UE, 4=Extra-UE
 *    - cuiP, denP, taraP, locP, judP: partner identification
 *    - nrFact: invoice count
 *    - baza, tva: taxable base and VAT amount per VAT rate (cota)
 *  - op2: operations without partners (internal operations, adjustments)
 *  - rezumat1: summary of op1 by operation type
 *  - rezumat2: summary of op2
 *  - informatii: additional info fields
 *  - serieFacturi: invoice series ranges used
 */
class D394Populator implements DeclarationDataPopulatorInterface
{
    // D394 VAT rates recognized by ANAF
    private const VAT_RATES = [0, 5, 9, 19];

    public function __construct(
        private readonly InvoiceRepository $invoiceRepository,
    ) {}

    public function supportsType(string $type): bool
    {
        return $type === 'd394';
    }

    public function populate(Company $company, int $year, int $month, string $periodType): array
    {
        $dateFrom = new \DateTime(sprintf('%04d-%02d-01', $year, $month));
        $dateTo = (clone $dateFrom)->modify('last day of this month');

        $invoices = $this->invoiceRepository->findByCompanyFiltered($company, [
            'dateFrom' => $dateFrom->format('Y-m-d'),
            'dateTo' => $dateTo->format('Y-m-d'),
            'excludeCancelled' => true,
        ], 10000);

        $salesPartners = [];
        $purchasesPartners = [];
        $incompleteInvoices = [];
        $seriesUsed = [];

        foreach ($invoices as $invoice) {
            $direction = $invoice->getDirection();
            if ($direction === null) {
                continue;
            }

            $isSale = $direction === InvoiceDirection::OUTGOING;
            $partnerCif = $isSale ? $invoice->getReceiverCif() : $invoice->getSenderCif();
            $partnerName = $isSale ? $invoice->getReceiverName() : $invoice->getSenderName();

            if (empty($partnerCif)) {
                $incompleteInvoices[] = (string) $invoice->getId();
                continue;
            }

            $client = $invoice->getClient();
            $partnerCountry = $client?->getCountry() ?? 'RO';
            $partnerCity = $client?->getCity() ?? '';
            $partnerCounty = $client?->getCounty() ?? '';
            $tipPartener = $this->classifyPartnerType($partnerCif, $partnerCountry);

            $bucket = $isSale ? 'sales' : 'purchases';
            $target = &${$bucket === 'sales' ? 'salesPartners' : 'purchasesPartners'};

            if (!isset($target[$partnerCif])) {
                $target[$partnerCif] = [
                    'partnerCif' => $partnerCif,
                    'partnerName' => $partnerName ?? '',
                    'partnerCountry' => $partnerCountry,
                    'partnerCity' => $partnerCity,
                    'partnerCounty' => $partnerCounty,
                    'tipPartener' => $tipPartener,
                    'invoiceCount' => 0,
                    'byRate' => [],
                    'total' => ['taxableBase' => '0.00', 'vatAmount' => '0.00'],
                ];
            }

            $target[$partnerCif]['invoiceCount']++;

            // Track invoice series
            $number = $invoice->getNumber();
            if ($number) {
                $series = $this->extractSeries($number);
                if ($series && !isset($seriesUsed[$series])) {
                    $seriesUsed[$series] = [
                        'serie' => $series,
                        'firstNumber' => $number,
                        'lastNumber' => $number,
                        'count' => 0,
                    ];
                }
                if ($series) {
                    $seriesUsed[$series]['lastNumber'] = $number;
                    $seriesUsed[$series]['count']++;
                }
            }

            foreach ($invoice->getLines() as $line) {
                $rate = $this->normalizeVatRate((float) $line->getVatRate());
                $rateKey = (string) $rate;

                if (!isset($target[$partnerCif]['byRate'][$rateKey])) {
                    $target[$partnerCif]['byRate'][$rateKey] = [
                        'taxableBase' => '0.00',
                        'vatAmount' => '0.00',
                        'cota' => $rate,
                    ];
                }

                $target[$partnerCif]['byRate'][$rateKey]['taxableBase'] = bcadd(
                    $target[$partnerCif]['byRate'][$rateKey]['taxableBase'],
                    $line->getLineTotal(),
                    2
                );
                $target[$partnerCif]['byRate'][$rateKey]['vatAmount'] = bcadd(
                    $target[$partnerCif]['byRate'][$rateKey]['vatAmount'],
                    $line->getVatAmount(),
                    2
                );

                $target[$partnerCif]['total']['taxableBase'] = bcadd(
                    $target[$partnerCif]['total']['taxableBase'],
                    $line->getLineTotal(),
                    2
                );
                $target[$partnerCif]['total']['vatAmount'] = bcadd(
                    $target[$partnerCif]['total']['vatAmount'],
                    $line->getVatAmount(),
                    2
                );
            }

            unset($target);
        }

        // Compute totals
        $totalSales = ['taxableBase' => '0.00', 'vatAmount' => '0.00'];
        foreach ($salesPartners as $partner) {
            $totalSales['taxableBase'] = bcadd($totalSales['taxableBase'], $partner['total']['taxableBase'], 2);
            $totalSales['vatAmount'] = bcadd($totalSales['vatAmount'], $partner['total']['vatAmount'], 2);
        }

        $totalPurchases = ['taxableBase' => '0.00', 'vatAmount' => '0.00'];
        foreach ($purchasesPartners as $partner) {
            $totalPurchases['taxableBase'] = bcadd($totalPurchases['taxableBase'], $partner['total']['taxableBase'], 2);
            $totalPurchases['vatAmount'] = bcadd($totalPurchases['vatAmount'], $partner['total']['vatAmount'], 2);
        }

        // Build rezumat (summary by VAT rate)
        $rezumatSales = $this->buildRezumat($salesPartners);
        $rezumatPurchases = $this->buildRezumat($purchasesPartners);

        return [
            'sales' => array_values($salesPartners),
            'purchases' => array_values($purchasesPartners),
            'totals' => [
                'sales' => $totalSales,
                'purchases' => $totalPurchases,
            ],
            'rezumat' => [
                'sales' => $rezumatSales,
                'purchases' => $rezumatPurchases,
            ],
            'serieFacturi' => array_values($seriesUsed),
            'incompleteInvoices' => $incompleteInvoices,
        ];
    }

    /**
     * Classify partner type per ANAF D394 tip_partener:
     *  1 = Persoana juridica Romania (PJ)
     *  2 = Persoana fizica Romania (PF)
     *  3 = Partener UE
     *  4 = Partener Extra-UE
     */
    private function classifyPartnerType(string $cif, string $country): int
    {
        $country = strtoupper($country);

        if ($country !== 'RO' && $country !== '') {
            $euCountries = [
                'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR',
                'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL',
                'PL', 'PT', 'SK', 'SI', 'ES', 'SE',
            ];

            return in_array($country, $euCountries, true) ? 3 : 4;
        }

        // Romania - check if PJ or PF
        $cleanCif = preg_replace('/[^0-9]/', '', $cif);

        // CIF starting with digits and length <= 10 typically indicates PJ
        // CNP (13 digits) indicates PF
        if (strlen($cleanCif) === 13) {
            return 2; // PF
        }

        return 1; // PJ
    }

    /**
     * Normalize VAT rate to ANAF-recognized values.
     */
    private function normalizeVatRate(float $rate): int
    {
        foreach (self::VAT_RATES as $recognized) {
            if (abs($rate - $recognized) < 0.5) {
                return $recognized;
            }
        }

        return (int) round($rate);
    }

    /**
     * Extract invoice series from invoice number (e.g., "STO001" → "STO").
     */
    private function extractSeries(string $number): ?string
    {
        if (preg_match('/^([A-Za-z]+)/', $number, $matches)) {
            return strtoupper($matches[1]);
        }

        return null;
    }

    /**
     * Build rezumat (summary) grouped by VAT rate.
     */
    private function buildRezumat(array $partners): array
    {
        $byRate = [];

        foreach ($partners as $partner) {
            foreach ($partner['byRate'] as $rateKey => $amounts) {
                if (!isset($byRate[$rateKey])) {
                    $byRate[$rateKey] = [
                        'cota' => (int) $rateKey,
                        'taxableBase' => '0.00',
                        'vatAmount' => '0.00',
                        'partnerCount' => 0,
                    ];
                }

                $byRate[$rateKey]['taxableBase'] = bcadd($byRate[$rateKey]['taxableBase'], $amounts['taxableBase'], 2);
                $byRate[$rateKey]['vatAmount'] = bcadd($byRate[$rateKey]['vatAmount'], $amounts['vatAmount'], 2);
            }
        }

        // Count unique partners per rate
        foreach ($partners as $partner) {
            foreach ($partner['byRate'] as $rateKey => $_) {
                $byRate[$rateKey]['partnerCount']++;
            }
        }

        return array_values($byRate);
    }
}
