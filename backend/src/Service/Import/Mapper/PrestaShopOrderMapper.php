<?php

namespace App\Service\Import\Mapper;

/**
 * PrestaShop admin orders export (Orders → Export, PrestaShop 1.6 – 8):
 * `ID, Reference, New client, Delivery, Customer, Total, Payment, Status, Date`.
 * The total is gross with the currency symbol ("45,00 lei", "€45.00"); without
 * a tax column the VAT is split with the company's default rate. Extended
 * exports (customer e-mail, company, VAT number, products) are recognised
 * through aliases.
 */
class PrestaShopOrderMapper extends AbstractShopOrderMapper
{
    public function getSource(): string
    {
        return 'prestashop';
    }

    protected function getNumberPrefix(): string
    {
        return 'PS';
    }

    protected function getHeaderAliases(): array
    {
        return [
            'number'           => ['ID', 'Order ID', 'id_order', 'ID comandă', 'ID comanda'],
            'orderReference'   => ['Reference', 'Referință', 'Referinta', 'reference'],
            'issueDate'        => ['Date', 'Data', 'date_add', 'Order date', 'Data comenzii'],
            'status'           => ['Status', 'Stare', 'Order status', 'current_state'],
            'receiverName'     => ['Customer', 'Client', 'Customer name', 'Nume client'],
            'receiverCompany'  => ['Company', 'Companie', 'Firma', 'Customer company', 'Billing company'],
            'receiverCif'      => ['VAT number', 'vat_number', 'CUI', 'CIF', 'Cod fiscal', 'DNI', 'Tax ID'],
            'receiverCnp'      => ['CNP'],
            'clientEmail'      => ['Email', 'E-mail', 'Customer email', 'email'],
            'clientPhone'      => ['Phone', 'Telefon', 'phone', 'Phone mobile'],
            'clientAddress'    => ['Address', 'Adresă', 'Adresa', 'address1', 'Billing address'],
            'clientCity'       => ['City', 'Oraș', 'Oras', 'city'],
            'clientCounty'     => ['State', 'Județ', 'Judet', 'state'],
            'clientPostalCode' => ['Postcode', 'Zip/Postal code', 'Cod poștal', 'Cod postal', 'postcode'],
            'clientCountry'    => ['Country', 'Țară', 'Tara', 'country', 'Delivery'],
            'lineItems'        => ['Products', 'Produse', 'Items', 'Product name'],
            'subtotal'         => ['Total products (tax excl.)', 'total_products', 'Total fără TVA', 'Total fara TVA'],
            'vatTotal'         => ['Tax', 'Taxes', 'TVA', 'Total tax', 'VAT'],
            'shippingTotal'    => ['Shipping', 'Total shipping', 'Transport', 'total_shipping'],
            'discount'         => ['Discount', 'Total discounts', 'Reducere', 'total_discounts'],
            'total'            => ['Total', 'Total paid', 'total_paid', 'Total (tax incl.)', 'Total comandă', 'Total comanda'],
            'currency'         => ['Currency', 'Monedă', 'Moneda', 'currency'],
            'paymentMethod'    => ['Payment', 'Plată', 'Plata', 'Payment method', 'payment'],
            'notes'            => ['Message', 'Mesaj', 'Note'],
        ];
    }

    protected function getPaidStatuses(): array
    {
        return ['payment accepted', 'plata acceptata', 'processing in progress', 'in curs de procesare', 'procesare in curs', 'shipped', 'expediat', 'expediata', 'delivered', 'livrat', 'livrata', 'remote payment accepted', 'plata la distanta acceptata', 'paid', 'platit', 'platita', 'complete', 'completed', 'finalizat', 'finalizata'];
    }

    public function getTemplateRows(): array
    {
        return [
            ['ID' => '501', 'Reference' => 'XKBKNABJK', 'New client' => 'Yes', 'Delivery' => 'România', 'Customer' => 'I. Popescu', 'Total' => '165,00 lei', 'Payment' => 'Card', 'Status' => 'Livrat', 'Date' => '2026-09-07 10:12:00'],
            ['ID' => '502', 'Reference' => 'QWERTYUIO', 'New client' => 'No', 'Delivery' => 'România', 'Customer' => 'M. Ionescu', 'Total' => '199,00 lei', 'Payment' => 'Ramburs', 'Status' => 'Plată acceptată', 'Date' => '2026-09-08 14:30:00'],
        ];
    }
}
