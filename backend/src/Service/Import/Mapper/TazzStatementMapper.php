<?php

namespace App\Service\Import\Mapper;

/**
 * Tazz partner portal orders export (Rapoarte → Comenzi → Export): one row
 * per order with the order value, the platform commission (with VAT, the
 * platform is a Romanian company) and the amount due to the partner.
 */
class TazzStatementMapper extends AbstractPlatformStatementMapper
{
    public function getSource(): string
    {
        return 'tazz';
    }

    public function getPlatformDefaults(): array
    {
        return ['name' => 'Tazz', 'country' => 'RO', 'cif' => null];
    }

    protected function getHeaderAliases(): array
    {
        return [
            'externalId'   => ['Nr. comandă', 'Nr comanda', 'Număr comandă', 'Numar comanda', 'ID comandă', 'ID comanda', 'Order ID', 'Order number', 'Comandă', 'Comanda'],
            'date'         => ['Data', 'Data comenzii', 'Data comandă', 'Data comanda', 'Data livrării', 'Data livrarii', 'Date', 'Order date'],
            'counterparty' => ['Client', 'Nume client', 'Customer', 'Restaurant', 'Partener', 'Locație', 'Locatie'],
            'description'  => ['Tip comandă', 'Tip comanda', 'Tip', 'Produse', 'Metodă plată', 'Metoda plata', 'Descriere'],
            'gross'        => ['Total comandă', 'Total comanda', 'Valoare comandă', 'Valoare comanda', 'Valoare', 'Total', 'Valoare produse', 'Total produse', 'Order total', 'Suma'],
            'tips'         => ['Bacșiș', 'Bacsis', 'Tips', 'Tip'],
            'tolls'        => ['Taxă livrare', 'Taxa livrare', 'Ajustări', 'Ajustari', 'Delivery fee'],
            'vat'          => ['TVA', 'TVA comandă', 'TVA comanda', 'VAT'],
            'commission'   => ['Comision', 'Comision Tazz', 'Comision platformă', 'Comision platforma', 'Commission', 'Valoare comision'],
            'commissionVat' => ['TVA comision', 'Comision TVA', 'Commission VAT'],
            'payout'       => ['De încasat', 'De incasat', 'Suma de plată', 'Suma de plata', 'Net', 'Total net', 'Payout', 'De plată', 'De plata'],
            'currency'     => ['Monedă', 'Moneda', 'Currency'],
            'status'       => ['Status', 'Stare', 'Stare comandă', 'Stare comanda'],
        ];
    }

    public function getTemplateRows(): array
    {
        return [
            ['Nr. comandă' => 'TZ-500001', 'Data' => '07.09.2026 13:10', 'Client' => 'Client final', 'Tip comandă' => 'Livrare', 'Total comandă' => '76,00', 'Bacșiș' => '0,00', 'Taxă livrare' => '0,00', 'TVA' => '7,67', 'Comision' => '15,20', 'TVA comision' => '3,19', 'De încasat' => '57,61', 'Monedă' => 'RON', 'Status' => 'Livrată'],
            ['Nr. comandă' => 'TZ-500002', 'Data' => '08.09.2026 20:45', 'Client' => 'Client final', 'Tip comandă' => 'Livrare', 'Total comandă' => '120,50', 'Bacșiș' => '5,00', 'Taxă livrare' => '0,00', 'TVA' => '12,16', 'Comision' => '24,10', 'TVA comision' => '5,06', 'De încasat' => '96,34', 'Monedă' => 'RON', 'Status' => 'Livrată'],
        ];
    }
}
