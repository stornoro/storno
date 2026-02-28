<?php

namespace App\Service\EInvoice\France;

use App\DTO\Anaf\ValidationError;
use App\DTO\Anaf\ValidationResult;
use App\Entity\Invoice;

/**
 * Pre-submission validation for French Factur-X / Chorus Pro.
 */
class FacturXValidator
{
    public function validate(Invoice $invoice): ValidationResult
    {
        $errors = [];
        $isRefund = $invoice->getParentDocument() !== null;

        $company = $invoice->getCompany();
        $client = $invoice->getClient();

        // Company validations
        if ($company === null) {
            $errors[] = new ValidationError('Invoice has no associated company.', 'business', 'FX-COMP-01');
        } else {
            if (empty($company->getCif())) {
                $errors[] = new ValidationError('Company has no SIREN/SIRET or VAT number.', 'business', 'FX-COMP-02');
            }
            if (empty($company->getName())) {
                $errors[] = new ValidationError('Company has no name.', 'business', 'FX-COMP-03');
            }
            if (empty($company->getAddress())) {
                $errors[] = new ValidationError('Company has no address.', 'business', 'FX-COMP-04');
            }
            if (empty($company->getCity())) {
                $errors[] = new ValidationError('Company has no city.', 'business', 'FX-COMP-05');
            }
            if (empty($company->getEmail())) {
                $errors[] = new ValidationError('Company has no email (required for electronic address).', 'business', 'FX-COMP-06');
            }
        }

        // Client validations
        if ($client !== null) {
            if ($client->getType() === 'company' && empty($client->getCui())) {
                $errors[] = new ValidationError('Client (company) has no SIREN/SIRET or VAT number.', 'business', 'FX-CLI-01');
            }
            if (empty($client->getName())) {
                $errors[] = new ValidationError('Client has no name.', 'business', 'FX-CLI-02');
            }
            if (empty($client->getAddress())) {
                $errors[] = new ValidationError('Client has no address.', 'business', 'FX-CLI-03');
            }
        } else {
            if (empty($invoice->getReceiverName())) {
                $errors[] = new ValidationError('Invoice has no receiver name.', 'business', 'FX-CLI-04');
            }
        }

        // Invoice metadata
        if (empty($invoice->getNumber())) {
            $errors[] = new ValidationError('Invoice has no number.', 'business', 'FX-INV-01');
        }
        if ($invoice->getIssueDate() === null) {
            $errors[] = new ValidationError('Invoice has no issue date.', 'business', 'FX-INV-02');
        }

        // Lines validations
        $lines = $invoice->getLines();
        if ($lines->isEmpty()) {
            $errors[] = new ValidationError('Invoice has no lines.', 'business', 'FX-LINE-01');
        } else {
            foreach ($lines as $index => $line) {
                $lineNumber = $index + 1;

                if (empty($line->getDescription())) {
                    $errors[] = new ValidationError(
                        sprintf('Line %d has no description.', $lineNumber),
                        'business',
                        'FX-LINE-02'
                    );
                }

                if (!$isRefund) {
                    if (bccomp($line->getQuantity(), '0', 4) <= 0) {
                        $errors[] = new ValidationError(
                            sprintf('Line %d has zero or negative quantity.', $lineNumber),
                            'business',
                            'FX-LINE-03'
                        );
                    }
                    if (bccomp($line->getUnitPrice(), '0', 2) <= 0) {
                        $errors[] = new ValidationError(
                            sprintf('Line %d has zero or negative unit price.', $lineNumber),
                            'business',
                            'FX-LINE-04'
                        );
                    }
                }
            }
        }

        // Total
        if (!$isRefund && bccomp($invoice->getTotal(), '0', 2) <= 0) {
            $errors[] = new ValidationError('Invoice total must be greater than zero.', 'business', 'FX-TOT-01');
        }

        if (empty($errors)) {
            return ValidationResult::valid();
        }

        return ValidationResult::invalid($errors);
    }
}
