<?php

namespace App\Service\Import\Mapper;

/**
 * Glovo Manager Portal orders export (Orders → Download / the periodic
 * "Orders report" e-mailed to partners): one row per order with the order
 * total collected from the customer, Glovo's commission (with its VAT) and
 * the amount paid out to the partner.
 *
 * The partner's counterparty is the Glovo entity named on the export
 * (Spanish for most Romanian partners, the Romanian SRL for others): set
 * `platformName` / `platformCif` / `platformCountry` in the import options
 * when the default does not match the statement.
 */
class GlovoStatementMapper extends AbstractPlatformStatementMapper
{
    public function getSource(): string
    {
        return 'glovo';
    }

    public function getPlatformDefaults(): array
    {
        return ['name' => 'Glovoapp23 S.L.', 'country' => 'ES', 'cif' => null];
    }

    protected function getHeaderAliases(): array
    {
        return [
            'externalId'   => ['Order ID', 'Order code', 'Order number', 'ID comandă', 'ID comanda', 'Cod comandă', 'Cod comanda', 'Order'],
            'date'         => ['Order date', 'Date', 'Activation time', 'Delivery time', 'Data', 'Data comenzii', 'Created at', 'Order time'],
            'counterparty' => ['Customer', 'Customer name', 'Client', 'Store', 'Store name', 'Restaurant', 'Store address'],
            'description'  => ['Order type', 'Type', 'Products', 'Produse', 'Descriere', 'Payment method'],
            'gross'        => ['Order total', 'Total', 'Gross', 'Gross amount', 'Total (gross)', 'Products total', 'Valoare comandă', 'Valoare comanda', 'Total comandă', 'Total comanda', 'Valoare', 'Order value'],
            'tips'         => ['Tips', 'Tip', 'Bacșiș', 'Bacsis'],
            'tolls'        => ['Delivery fee', 'Service fee refund', 'Adjustments', 'Ajustări', 'Ajustari'],
            'vat'          => ['VAT', 'Order VAT', 'TVA', 'TVA comandă', 'TVA comanda', 'Tax'],
            'commission'   => ['Commission', 'Glovo commission', 'Commission amount', 'Comision', 'Comision Glovo', 'Fee'],
            'commissionVat' => ['Commission VAT', 'VAT on commission', 'TVA comision', 'Commission tax'],
            'payout'       => ['Payout', 'Net payout', 'Net amount', 'Amount to pay', 'Total to pay', 'Net', 'De plată', 'De plata', 'Sumă netă', 'Suma neta'],
            'currency'     => ['Currency', 'Monedă', 'Moneda'],
            'status'       => ['Status', 'Order status', 'Stare'],
        ];
    }

    public function getTemplateRows(): array
    {
        return [
            ['Order ID' => 'GLV-100001', 'Order date' => '2026-09-07 12:31', 'Customer' => 'Client final', 'Order type' => 'Delivery', 'Order total' => '89.00', 'Tips' => '0.00', 'Delivery fee' => '0.00', 'VAT' => '8.98', 'Commission' => '26.70', 'Commission VAT' => '5.61', 'Payout' => '56.69', 'Currency' => 'RON', 'Status' => 'Delivered'],
            ['Order ID' => 'GLV-100002', 'Order date' => '2026-09-07 19:02', 'Customer' => 'Client final', 'Order type' => 'Delivery', 'Order total' => '45.50', 'Tips' => '2.00', 'Delivery fee' => '0.00', 'VAT' => '4.59', 'Commission' => '13.65', 'Commission VAT' => '2.87', 'Payout' => '30.98', 'Currency' => 'RON', 'Status' => 'Delivered'],
        ];
    }
}
