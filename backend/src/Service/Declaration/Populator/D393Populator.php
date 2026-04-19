<?php

namespace App\Service\Declaration\Populator;

use App\Entity\Company;
use App\Enum\InvoiceDirection;
use App\Repository\InvoiceRepository;
use App\Service\Declaration\DeclarationDataPopulatorInterface;

/**
 * Populates D393 (Declaratie informativa VIES servicii) — services only.
 * Similar to D390 but filters to intra-community service operations only (non-K VAT categories).
 */
class D393Populator implements DeclarationDataPopulatorInterface
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
        return $type === 'd393';
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
        $totals = ['nrOPI' => 0, 'bazaP' => '0', 'bazaS' => '0', 'totalBaza' => '0'];

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

            // Only include lines with non-K VAT category (services, not goods)
            $servicesBase = '0';
            foreach ($invoice->getLines() as $line) {
                if ($line->getVatCategoryCode() !== 'K') {
                    $servicesBase = bcadd($servicesBase, $line->getLineTotal(), 2);
                }
            }

            if (bccomp($servicesBase, '0', 2) === 0) {
                continue;
            }

            $tip = $isSale ? 'P' : 'S';
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

            $operations[$key]['baza'] = bcadd($operations[$key]['baza'], $servicesBase, 2);

            $totals['nrOPI']++;
            $totalKey = 'baza' . $tip;
            $totals[$totalKey] = bcadd($totals[$totalKey], $servicesBase, 2);
            $totals['totalBaza'] = bcadd($totals['totalBaza'], $servicesBase, 2);
        }

        return [
            'operations' => array_values($operations),
            'rezumat' => $totals,
        ];
    }
}
