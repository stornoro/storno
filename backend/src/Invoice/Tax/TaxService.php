<?php

namespace App\Invoice\Tax;

class TaxService
{
    private $id;

    public function getServiceTax()
    {
        if (!empty($this->id)) {
            return $this->id;
        }
 
        if ($this->getPercent() !== null) {
            if ($this->getPercent() >= 21) {
                return VatCategoryCode::STANDARD_RATE;
            } elseif ($this->getPercent() <= 21 && $this->getPercent() >= 6) {
                return VatCategoryCode::REVERSE_CHARGE;
            } else {
                return VatCategoryCode::ZERO_RATED;
            }
        }
 
         return null;
    }
}
