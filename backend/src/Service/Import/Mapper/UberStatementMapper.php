<?php

namespace App\Service\Import\Mapper;

/**
 * Uber Fleet Hub / driver "Payments" CSV: one row per transaction (a trip's
 * fare, a tip added later, an adjustment), each carrying the trip id, so the
 * rows of one trip are summed together by the persister.
 *
 * Column names verified from Uber's public reporting documentation: Trip ID,
 * Fare, Tips, Tolls, Service fee, Taxes, Currency; the date column and the
 * Romanian translations are aliases the mapping step can correct.
 */
class UberStatementMapper extends AbstractPlatformStatementMapper
{
    public function getSource(): string
    {
        return 'uber';
    }

    public function getPlatformDefaults(): array
    {
        return ['name' => 'Uber B.V.', 'country' => 'NL', 'cif' => null];
    }

    protected function getHeaderAliases(): array
    {
        return [
            'externalId'   => ['Trip ID', 'Trip UUID', 'Trip id', 'ID cursă', 'ID calatorie', 'Order ID', 'Transaction ID'],
            'date'         => ['Trip date', 'Date', 'Timestamp', 'Trip time', 'Trip Date/Time', 'Data cursei', 'Data', 'Local time', 'Request time', 'Trip completed at'],
            'counterparty' => ['Driver', 'Driver name', 'Șofer', 'Sofer', 'Partner'],
            'description'  => ['Event type', 'Description', 'Item', 'Descriere', 'Trip type', 'Product'],
            'gross'        => ['Fare', 'Trip fare', 'Fare (incl. tax)', 'Tarif', 'Tarif cursă', 'Earnings', 'Gross fare', 'Total fare'],
            'tips'         => ['Tips', 'Tip', 'Bacșiș', 'Bacsis'],
            'tolls'        => ['Tolls', 'Toll', 'Taxe de drum', 'Reimbursements', 'Other earnings'],
            'vat'          => ['Taxes', 'VAT', 'Tax', 'TVA', 'Taxe'],
            'commission'   => ['Service fee', 'Uber service fee', 'Uber fee', 'Comision Uber', 'Taxă de serviciu', 'Taxa de serviciu', 'Booking fee', 'Fees'],
            'commissionVat' => ['Service fee VAT', 'VAT on service fee', 'TVA comision'],
            'payout'       => ['Total', 'Net earnings', 'Payout', 'Net', 'Your earnings', 'Total earnings', 'Sumă netă', 'Suma neta', 'Amount'],
            'currency'     => ['Currency', 'Monedă', 'Moneda'],
            'status'       => ['Status', 'Trip status'],
        ];
    }

    public function getTemplateRows(): array
    {
        return [
            ['Trip ID' => 'a1b2c3d4-0000-4000-8000-000000000001', 'Trip date' => '2026-09-07 08:15:00', 'Driver' => 'Popescu Ion', 'Event type' => 'Trip', 'Fare' => '48.50', 'Tips' => '5.00', 'Tolls' => '0.00', 'Taxes' => '0.00', 'Service fee' => '-12.13', 'Service fee VAT' => '0.00', 'Total' => '41.37', 'Currency' => 'RON', 'Status' => 'completed'],
            ['Trip ID' => 'a1b2c3d4-0000-4000-8000-000000000002', 'Trip date' => '2026-09-08 19:40:00', 'Driver' => 'Popescu Ion', 'Event type' => 'Trip', 'Fare' => '23.00', 'Tips' => '0.00', 'Tolls' => '0.00', 'Taxes' => '0.00', 'Service fee' => '-5.75', 'Service fee VAT' => '0.00', 'Total' => '17.25', 'Currency' => 'RON', 'Status' => 'completed'],
        ];
    }
}
