<?php

namespace App\Service\Report;

use App\Entity\Company;
use App\Enum\InvoiceDirection;
use App\Repository\InvoiceRepository;

class VatReportService
{
    public function __construct(
        private readonly InvoiceRepository $invoiceRepository,
    ) {}

    public function generate(Company $company, int $year, int $month): array
    {
        $dateFrom = new \DateTime(sprintf('%04d-%02d-01', $year, $month));
        $dateTo = (clone $dateFrom)->modify('last day of this month');

        $invoices = $this->invoiceRepository->findByCompanyFiltered($company, [
            'dateFrom' => $dateFrom->format('Y-m-d'),
            'dateTo' => $dateTo->format('Y-m-d'),
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
