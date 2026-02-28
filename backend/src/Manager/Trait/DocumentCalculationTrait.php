<?php

namespace App\Manager\Trait;

use App\Entity\DocumentLineInterface;

/**
 * Shared line population and total calculation logic for document managers.
 *
 * Used by InvoiceManager, ProformaInvoiceManager, and RecurringInvoiceManager.
 */
trait DocumentCalculationTrait
{
    /**
     * Populate shared fields on a line entity from request data.
     */
    private function populateLineFields(DocumentLineInterface $line, array $data, int $position): void
    {
        $line->setPosition($position);
        $line->setDescription($data['description'] ?? '');
        $line->setQuantity($data['quantity'] ?? '1.0000');
        $line->setUnitOfMeasure($data['unitOfMeasure'] ?? 'buc');
        $line->setUnitPrice($data['unitPrice'] ?? '0.00');
        $line->setVatRate($data['vatRate'] ?? '21.00');
        $line->setVatCategoryCode($data['vatCategoryCode'] ?? 'S');
        $line->setDiscount($data['discount'] ?? '0.00');
        $line->setDiscountPercent($data['discountPercent'] ?? '0.00');
        $line->setVatIncluded(!empty($data['vatIncluded']));
        $line->setProductCode($data['productCode'] ?? null);
        $line->setLineNote($data['lineNote'] ?? null);
        $line->setBuyerAccountingRef($data['buyerAccountingRef'] ?? null);
        $line->setBuyerItemIdentification($data['buyerItemIdentification'] ?? null);
        $line->setStandardItemIdentification($data['standardItemIdentification'] ?? null);
        $line->setCpvCode($data['cpvCode'] ?? null);

        $this->calculateLineTotals($line);
    }

    /**
     * Calculate lineTotal and vatAmount from the line's current field values.
     */
    private function calculateLineTotals(DocumentLineInterface $line): void
    {
        $qty = (float) $line->getQuantity();
        $price = (float) $line->getUnitPrice();
        $discount = (float) $line->getDiscount();

        if ($line->isVatIncluded()) {
            // Price includes VAT — extract net amount
            $vatRate = (float) $line->getVatRate();
            $grossTotal = ($qty * $price) - $discount;
            $lineNet = $grossTotal / (1 + $vatRate / 100);
            $vatAmount = $grossTotal - $lineNet;
        } else {
            $lineNet = ($qty * $price) - $discount;
            $vatAmount = $lineNet * ((float) $line->getVatRate() / 100);
        }

        $line->setLineTotal(number_format($lineNet, 2, '.', ''));
        $line->setVatAmount(number_format($vatAmount, 2, '.', ''));
    }

    /**
     * Recalculate stored totals on a document entity from its lines.
     *
     * Works with any entity that has getLines(), setSubtotal(), setVatTotal(),
     * setDiscount(), and setTotal() methods (Invoice, ProformaInvoice).
     */
    private function recalculateStoredTotals(object $document): void
    {
        $subtotal = '0.00';
        $vatTotal = '0.00';
        $discountTotal = '0.00';

        foreach ($document->getLines() as $line) {
            $subtotal = bcadd($subtotal, $line->getLineTotal(), 2);
            $vatTotal = bcadd($vatTotal, $line->getVatAmount(), 2);
            $discountTotal = bcadd($discountTotal, $line->getDiscount(), 2);
        }

        $total = bcadd($subtotal, $vatTotal, 2);

        $document->setSubtotal($subtotal);
        $document->setVatTotal($vatTotal);
        $document->setDiscount($discountTotal);
        $document->setTotal($total);
    }
}
