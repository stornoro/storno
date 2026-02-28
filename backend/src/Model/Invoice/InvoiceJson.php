<?php

namespace App\Model\Invoice;

use App\Model\Invoice\InvoiceAddress;

class InvoiceJson
{


    private InvoiceAddress $seller;
    private InvoiceAddress $buyer;
    private Invoice $invoice;


    /**
     * Get the value of seller
     *
     * @return InvoiceAddress
     */
    public function getSeller(): InvoiceAddress
    {
        return $this->seller;
    }

    /**
     * Set the value of seller
     *
     * @param InvoiceAddress $seller
     *
     * @return self
     */
    public function setSeller(InvoiceAddress $seller): self
    {
        $this->seller = $seller;

        return $this;
    }

    /**
     * Get the value of buyer
     *
     * @return InvoiceAddress
     */
    public function getBuyer(): InvoiceAddress
    {
        return $this->buyer;
    }

    /**
     * Set the value of buyer
     *
     * @param InvoiceAddress $buyer
     *
     * @return self
     */
    public function setBuyer(InvoiceAddress $buyer): self
    {
        $this->buyer = $buyer;

        return $this;
    }

    /**
     * Get the value of invoice
     *
     * @return Invoice
     */
    public function getInvoice(): Invoice
    {
        return $this->invoice;
    }

    /**
     * Set the value of invoice
     *
     * @param Invoice $invoice
     *
     * @return self
     */
    public function setInvoice(Invoice $invoice): self
    {
        $this->invoice = $invoice;

        return $this;
    }
}
