<?php

namespace App\Service\Import\Mapper;

/**
 * WooCommerce order export (WooCommerce → Orders → Export, or the usual order
 * export plugins): one row per order with the billing columns, the line items
 * in one cell ("Product A x 2 | Product B x 1") or the order total only.
 */
class WooCommerceOrderMapper extends AbstractShopOrderMapper
{
    public function getSource(): string
    {
        return 'woocommerce';
    }

    protected function getNumberPrefix(): string
    {
        return 'WC';
    }

    protected function getHeaderAliases(): array
    {
        return [
            'number'           => ['Order ID', 'order_id', 'ID', 'Order'],
            'orderReference'   => ['Order Number', 'order_number', 'Number'],
            'issueDate'        => ['Order Date', 'order_date', 'Date', 'Date Created', 'date_created', 'Paid Date', 'Data comenzii'],
            'status'           => ['Status', 'Order Status', 'order_status', 'status'],
            'receiverName'     => ['Customer Name', 'Billing Name', 'billing_name', 'Customer', 'Name', 'Nume client'],
            'receiverCompany'  => ['Billing Company', 'billing_company', 'Company', 'Companie facturare', 'Firma'],
            'receiverCif'      => ['VAT Number', 'Billing VAT', 'billing_vat', 'CUI', 'CIF', 'Tax ID', 'billing_cui', 'Cod fiscal', 'Billing Tax ID'],
            'receiverCnp'      => ['CNP', 'billing_cnp', 'Billing CNP'],
            'clientEmail'      => ['Billing Email', 'billing_email', 'Customer Email', 'Email', 'Email (Billing)'],
            'clientPhone'      => ['Billing Phone', 'billing_phone', 'Phone', 'Telefon'],
            'clientAddress'    => ['Billing Address 1', 'billing_address_1', 'Billing Address', 'Address 1 (Billing)', 'Adresă facturare', 'Adresa facturare'],
            'clientCity'       => ['Billing City', 'billing_city', 'City (Billing)', 'Oraș facturare', 'Oras facturare'],
            'clientCounty'     => ['Billing State', 'billing_state', 'State (Billing)', 'Județ facturare', 'Judet facturare'],
            'clientPostalCode' => ['Billing Postcode', 'billing_postcode', 'Billing Zip', 'Postcode (Billing)', 'Cod poștal facturare'],
            'clientCountry'    => ['Billing Country', 'billing_country', 'Country (Billing)', 'Țară facturare', 'Tara facturare'],
            'lineItems'        => ['Line items', 'Line Items', 'Order Items', 'order_items', 'Items', 'Products', 'Product Name', 'Produse'],
            'subtotal'         => ['Order Subtotal', 'order_subtotal', 'Subtotal', 'Cart Subtotal'],
            'vatTotal'         => ['Tax Total', 'Order Tax', 'order_tax', 'tax_total', 'Total Tax', 'Order Total Tax', 'TVA'],
            'shippingTotal'    => ['Shipping Total', 'shipping_total', 'Order Shipping', 'Shipping'],
            'discount'         => ['Discount Total', 'discount_total', 'Cart Discount', 'Order Discount'],
            'total'            => ['Order Total', 'order_total', 'Total', 'Total comandă', 'Total comanda', 'Order Total Amount'],
            'currency'         => ['Currency', 'Order Currency', 'order_currency', 'Monedă', 'Moneda'],
            'paymentMethod'    => ['Payment Method Title', 'Payment Method', 'payment_method_title', 'payment_method', 'Metodă de plată', 'Metoda de plata'],
            'notes'            => ['Customer Note', 'customer_note', 'Order Notes', 'Note'],
        ];
    }

    protected function getPaidStatuses(): array
    {
        return ['completed', 'processing', 'finalizat', 'finalizata', 'in procesare', 'in curs de procesare', 'procesare', 'paid', 'platit', 'platita', 'shipped', 'livrat', 'livrata', 'delivered'];
    }

    protected function combineNameColumns(array $result, array $row): array
    {
        if (empty($result['receiverName'])) {
            $first = $row['Billing First Name'] ?? $row['billing_first_name'] ?? $row['First Name (Billing)'] ?? '';
            $last = $row['Billing Last Name'] ?? $row['billing_last_name'] ?? $row['Last Name (Billing)'] ?? '';
            $name = trim($first . ' ' . $last);
            if ($name !== '') {
                $result['receiverName'] = $name;
            }
        }

        return $result;
    }

    public function getTemplateRows(): array
    {
        return [
            ['Order ID' => '1001', 'Order Number' => '1001', 'Order Date' => '2026-09-07 10:12:00', 'Status' => 'completed', 'Customer Name' => 'Ion Popescu', 'Billing Company' => '', 'VAT Number' => '', 'CNP' => '', 'Billing Email' => 'ion.popescu@example.com', 'Billing Phone' => '0700000000', 'Billing Address 1' => 'Str. Exemplu nr. 1', 'Billing City' => 'București', 'Billing State' => 'B', 'Billing Postcode' => '010101', 'Billing Country' => 'RO', 'Line items' => 'Tricou alb x 2 = 120.00 | Șapcă x 1 = 45.00', 'Order Subtotal' => '136.36', 'Tax Total' => '28.64', 'Shipping Total' => '0.00', 'Discount Total' => '0.00', 'Order Total' => '165.00', 'Currency' => 'RON', 'Payment Method Title' => 'Card', 'Customer Note' => ''],
            ['Order ID' => '1002', 'Order Number' => '1002', 'Order Date' => '2026-09-08 14:30:00', 'Status' => 'processing', 'Customer Name' => 'Maria Ionescu', 'Billing Company' => 'Exemplu SRL', 'VAT Number' => 'RO12345678', 'CNP' => '', 'Billing Email' => 'contact@example.com', 'Billing Phone' => '0700000001', 'Billing Address 1' => 'Bd. Exemplu nr. 10', 'Billing City' => 'Cluj-Napoca', 'Billing State' => 'CJ', 'Billing Postcode' => '400001', 'Billing Country' => 'RO', 'Line items' => 'Hanorac x 1 = 199.00', 'Order Subtotal' => '164.46', 'Tax Total' => '34.54', 'Shipping Total' => '0.00', 'Discount Total' => '0.00', 'Order Total' => '199.00', 'Currency' => 'RON', 'Payment Method Title' => 'Ramburs', 'Customer Note' => ''],
        ];
    }
}
