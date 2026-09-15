<?php

namespace App\Service\Import\Mapper;

/**
 * Bolt Fleet Portal ride / earnings report (Reports → Weekly or Daily report,
 * download CSV): one row per ride with the gross price, Bolt's commission and
 * the net amount, so both the sales and the commission side of the statement
 * are covered. The received-invoice CSV (Invoices → export) keeps its own
 * mapper, BoltInvoiceMapper.
 *
 * Column names are the ones of the English and Romanian portal exports as far
 * as they are known; the mapping step corrects renamed columns.
 */
class BoltStatementMapper extends AbstractPlatformStatementMapper
{
    public function getSource(): string
    {
        return 'bolt';
    }

    public function getPlatformDefaults(): array
    {
        return ['name' => 'Bolt Operations OÜ', 'country' => 'EE', 'cif' => null];
    }

    protected function getHeaderAliases(): array
    {
        return [
            'externalId'   => ['Ride ID', 'Order ID', 'Order reference', 'Trip ID', 'ID cursă', 'ID cursa', 'ID comandă', 'Ride id'],
            'date'         => ['Date', 'Ride date', 'Order time', 'Order date', 'Data', 'Data cursei', 'Data călătoriei', 'Data calatoriei', 'Time', 'Completed at'],
            'counterparty' => ['Driver', 'Driver name', 'Șofer', 'Sofer', 'Driver\'s name'],
            'description'  => ['Ride type', 'Category', 'Payment method', 'Metoda de plată', 'Descriere'],
            'gross'        => ['Gross earnings', 'Ride price', 'Price', 'Total price', 'Order price', 'Fare', 'Preț', 'Pret', 'Preț cursă', 'Câștig brut', 'Castig brut', 'Venit brut', 'Total (gross)'],
            'tips'         => ['Tip', 'Tips', 'Bacșiș', 'Bacsis'],
            'tolls'        => ['Toll', 'Tolls', 'Booking fee', 'Taxe de drum', 'Bonus', 'Compensations'],
            'vat'          => ['VAT', 'TVA', 'Tax'],
            'commission'   => ['Bolt commission', 'Commission', 'Bolt fee', 'Comision Bolt', 'Comision', 'Platform fee'],
            'commissionVat' => ['Commission VAT', 'VAT on commission', 'TVA comision'],
            'payout'       => ['Net earnings', 'Net', 'Payout', 'Total', 'Câștig net', 'Castig net', 'Venit net', 'Amount to be paid'],
            'currency'     => ['Currency', 'Monedă', 'Moneda'],
            'status'       => ['Status', 'Ride status', 'Order status'],
        ];
    }

    public function getTemplateRows(): array
    {
        return [
            ['Ride ID' => 'BR-2026-000001', 'Date' => '07.09.2026 09:05', 'Driver' => 'Popescu Ion', 'Ride type' => 'Bolt', 'Gross earnings' => '35,00', 'Tip' => '0,00', 'Toll' => '0,00', 'VAT' => '0,00', 'Bolt commission' => '7,00', 'Commission VAT' => '0,00', 'Net earnings' => '28,00', 'Currency' => 'RON', 'Status' => 'finished'],
            ['Ride ID' => 'BR-2026-000002', 'Date' => '07.09.2026 21:30', 'Driver' => 'Popescu Ion', 'Ride type' => 'Bolt', 'Gross earnings' => '52,40', 'Tip' => '4,00', 'Toll' => '0,00', 'VAT' => '0,00', 'Bolt commission' => '10,48', 'Commission VAT' => '0,00', 'Net earnings' => '45,92', 'Currency' => 'RON', 'Status' => 'finished'],
        ];
    }
}
