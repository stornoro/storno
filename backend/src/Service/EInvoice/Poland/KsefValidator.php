<?php

namespace App\Service\EInvoice\Poland;

use App\DTO\Anaf\ValidationError;
use App\DTO\Anaf\ValidationResult;
use App\Entity\Invoice;

/**
 * Pre-submission validation for Polish KSeF.
 */
class KsefValidator
{
    public function validate(Invoice $invoice): ValidationResult
    {
        $errors = [];
        $isRefund = $invoice->getParentDocument() !== null;

        $company = $invoice->getCompany();
        $client = $invoice->getClient();

        // Company validations
        if ($company === null) {
            $errors[] = new ValidationError('Invoice has no associated company.', 'business', 'KSEF-COMP-01');
        } else {
            if (empty($company->getCif())) {
                $errors[] = new ValidationError('Company has no NIP (tax ID).', 'business', 'KSEF-COMP-02');
            }
            if (empty($company->getName())) {
                $errors[] = new ValidationError('Company has no name (Nazwa).', 'business', 'KSEF-COMP-03');
            }
            if (empty($company->getAddress())) {
                $errors[] = new ValidationError('Company has no address.', 'business', 'KSEF-COMP-04');
            }
            if (empty($company->getCity())) {
                $errors[] = new ValidationError('Company has no city.', 'business', 'KSEF-COMP-05');
            }
        }

        // Client validations
        if ($client !== null) {
            if ($client->getType() === 'company' && empty($client->getCui())) {
                $errors[] = new ValidationError('Client (company) has no NIP.', 'business', 'KSEF-CLI-01');
            }
            if (empty($client->getName())) {
                $errors[] = new ValidationError('Client has no name.', 'business', 'KSEF-CLI-02');
            }
        } else {
            if (empty($invoice->getReceiverName())) {
                $errors[] = new ValidationError('Invoice has no receiver name.', 'business', 'KSEF-CLI-03');
            }
        }

        // Invoice metadata
        if (empty($invoice->getNumber())) {
            $errors[] = new ValidationError('Invoice has no number (P_2).', 'business', 'KSEF-INV-01');
        }
        if ($invoice->getIssueDate() === null) {
            $errors[] = new ValidationError('Invoice has no issue date (P_1).', 'business', 'KSEF-INV-02');
        }

        // Credit note must reference parent
        if ($isRefund && $invoice->getParentDocument() === null) {
            $errors[] = new ValidationError('Credit note must reference the original invoice.', 'business', 'KSEF-INV-03');
        }

        // Lines validations
        $lines = $invoice->getLines();
        if ($lines->isEmpty()) {
            $errors[] = new ValidationError('Invoice has no lines (FaWiersz required).', 'business', 'KSEF-LINE-01');
        } else {
            foreach ($lines as $index => $line) {
                $lineNumber = $index + 1;

                if (empty($line->getDescription())) {
                    $errors[] = new ValidationError(
                        sprintf('Line %d has no description (P_7).', $lineNumber),
                        'business',
                        'KSEF-LINE-02'
                    );
                }

                if (!$isRefund) {
                    if (bccomp($line->getQuantity(), '0', 4) <= 0) {
                        $errors[] = new ValidationError(
                            sprintf('Line %d has zero or negative quantity.', $lineNumber),
                            'business',
                            'KSEF-LINE-03'
                        );
                    }
                    if (bccomp($line->getUnitPrice(), '0', 2) <= 0) {
                        $errors[] = new ValidationError(
                            sprintf('Line %d has zero or negative unit price.', $lineNumber),
                            'business',
                            'KSEF-LINE-04'
                        );
                    }
                }
            }
        }

        // Total
        if (!$isRefund && bccomp($invoice->getTotal(), '0', 2) <= 0) {
            $errors[] = new ValidationError('Invoice total must be greater than zero.', 'business', 'KSEF-TOT-01');
        }

        if (empty($errors)) {
            return ValidationResult::valid();
        }

        return ValidationResult::invalid($errors);
    }
}
