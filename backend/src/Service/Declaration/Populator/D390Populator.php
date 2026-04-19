<?php

namespace App\Service\Declaration\Populator;

use App\Entity\Company;
use App\Enum\InvoiceDirection;
use App\Repository\InvoiceRepository;
use App\Service\Declaration\DeclarationDataPopulatorInterface;

/**
 * Populates D390 (Declaratie recapitulativa VIES) data from invoice records.
 *
 * Filters invoices to only those where the partner is in an EU country (not RO).
 * Groups by partner EU country + VAT code.
 * Classifies operation type: L (goods delivery), A (goods acquisition),
 * P (services provided), S (services received).
 */
class D390Populator implements DeclarationDataPopulatorInterface
{
    private const EU_COUNTRIES = [
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR',
        'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL',
        'PL', 'PT', 'SK', 'SI', 'ES', 'SE',
    ];

    public function __construct(
        private readonly InvoiceRepository $invoiceRepository,
    ) {}

    public function supportsType(string $type): bool
    {
        return $type === 'd390';
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

        $operations = [];
        $totals = ['nrOPI' => 0, 'bazaL' => '0', 'bazaA' => '0', 'bazaP' => '0', 'bazaS' => '0', 'totalBaza' => '0'];

        foreach ($invoices as $invoice) {
            $direction = $invoice->getDirection();
            if ($direction === null) {
                continue;
            }

            $client = $invoice->getClient();
            $partnerCountry = strtoupper($client?->getCountry() ?? '');

            if ($partnerCountry === 'RO' || $partnerCountry === '' || !in_array($partnerCountry, self::EU_COUNTRIES, true)) {
                continue;
            }

            $isSale = $direction === InvoiceDirection::OUTGOING;
            $partnerCif = $isSale ? $invoice->getReceiverCif() : $invoice->getSenderCif();
            $partnerName = $isSale ? $invoice->getReceiverName() : $invoice->getSenderName();

            if (empty($partnerCif)) {
                continue;
            }

            // Classify operation type based on direction and VAT category
            $hasGoods = false;
            $hasServices = false;
            $invoiceBase = '0';

            foreach ($invoice->getLines() as $line) {
                $vatCat = $line->getVatCategoryCode();
                $lineTotal = $line->getLineTotal();
                $invoiceBase = bcadd($invoiceBase, $lineTotal, 2);

                // K = intra-community goods, AE = reverse charge (often services)
                if ($vatCat === 'K') {
                    $hasGoods = true;
                } else {
                    $hasServices = true;
                }
            }

            // Determine operation type
            if ($isSale) {
                $tip = $hasGoods ? 'L' : 'P'; // L = goods delivery, P = services provided
            } else {
                $tip = $hasGoods ? 'A' : 'S'; // A = goods acquisition, S = services received
            }

            $key = $tip . '_' . $partnerCountry . '_' . $partnerCif;

            if (!isset($operations[$key])) {
                $operations[$key] = [
                    'tip' => $tip,
                    'tara' => $partnerCountry,
                    'codO' => $partnerCif,
                    'denO' => $partnerName ?? '',
                    'baza' => '0',
                ];
            }

            $operations[$key]['baza'] = bcadd($operations[$key]['baza'], $invoiceBase, 2);

            // Update totals
            $totals['nrOPI']++;
            $totalKey = 'baza' . $tip;
            $totals[$totalKey] = bcadd($totals[$totalKey], $invoiceBase, 2);
            $totals['totalBaza'] = bcadd($totals['totalBaza'], $invoiceBase, 2);
        }

        return [
            'operations' => array_values($operations),
            'rezumat' => $totals,
        ];
    }
}
