<?php

namespace App\Service\EInvoice\Germany;

use App\DTO\Anaf\ValidationError;
use App\DTO\Anaf\ValidationResult;
use App\Entity\Invoice;

class XRechnungValidator
{
    /**
     * Validate an invoice for XRechnung submission.
     * Checks BR-DE rules and EN 16931 requirements.
     */
    public function validate(Invoice $invoice): ValidationResult
    {
        $errors = [];
        $isRefund = $invoice->getParentDocument() !== null;

        $company = $invoice->getCompany();
        $client = $invoice->getClient();

        // Company validations
        if ($company === null) {
            $errors[] = new ValidationError('Invoice has no associated company.', 'business', 'XR-COMP-01');
        } else {
            if (empty($company->getCif())) {
                $errors[] = new ValidationError('Company has no tax ID (CIF/USt-IdNr).', 'business', 'XR-COMP-02');
            }
            if (empty($company->getName())) {
                $errors[] = new ValidationError('Company has no name.', 'business', 'XR-COMP-03');
            }
            if (empty($company->getAddress())) {
                $errors[] = new ValidationError('Company has no street address.', 'business', 'XR-COMP-04');
            }
            if (empty($company->getCity())) {
                $errors[] = new ValidationError('Company has no city.', 'business', 'XR-COMP-05');
            }
            if (empty($company->getCountry())) {
                $errors[] = new ValidationError('Company has no country code.', 'business', 'XR-COMP-06');
            }
            if (empty($company->getEmail())) {
                $errors[] = new ValidationError('[BR-DE-7] Company has no email (required for EndpointID and Contact).', 'business', 'BR-DE-7');
            }
            // [BR-DE-6] Seller contact telephone is mandatory
            if (empty($company->getPhone())) {
                $errors[] = new ValidationError('[BR-DE-6] Company has no phone number (required for seller Contact).', 'business', 'BR-DE-6');
            }
        }

        // [BR-DE-1] BuyerReference is mandatory
        if ($client !== null) {
            $identifiers = $client->getEinvoiceIdentifier('xrechnung');
            $hasLeitwegId = $identifiers !== null && !empty($identifiers['leitwegId']);
            $hasFallback = !empty($client->getClientCode()) || !empty($client->getCui());

            if (!$hasLeitwegId && !$hasFallback) {
                $errors[] = new ValidationError(
                    'Client must have a Leitweg-ID (in e-invoice identifiers), client code, or CUI for BuyerReference (BR-DE-1).',
                    'business',
                    'BR-DE-1'
                );
            }
        }

        // Client/receiver validations
        if ($client !== null) {
            if ($client->getType() === 'company' && empty($client->getCui())) {
                $errors[] = new ValidationError('Client (company) has no tax ID.', 'business', 'XR-CLI-01');
            }
            if (empty($client->getName())) {
                $errors[] = new ValidationError('Client has no name.', 'business', 'XR-CLI-02');
            }
            if (empty($client->getAddress())) {
                $errors[] = new ValidationError('Client has no street address.', 'business', 'XR-CLI-03');
            }
            if (empty($client->getCity())) {
                $errors[] = new ValidationError('Client has no city.', 'business', 'XR-CLI-04');
            }
        } else {
            if (empty($invoice->getReceiverName())) {
                $errors[] = new ValidationError('Invoice has no receiver (client or receiver name).', 'business', 'XR-CLI-05');
            }
            if (empty($invoice->getReceiverCif())) {
                $errors[] = new ValidationError('Invoice has no receiver tax ID.', 'business', 'XR-CLI-06');
            }
        }

        // Invoice metadata validations
        if (empty($invoice->getNumber())) {
            $errors[] = new ValidationError('Invoice has no number.', 'business', 'XR-INV-01');
        }
        if ($invoice->getIssueDate() === null) {
            $errors[] = new ValidationError('Invoice has no issue date.', 'business', 'XR-INV-02');
        }

        // [BR-DE-2] Payment account (IBAN) validation
        if ($company !== null && $company->getBankAccount()) {
            $iban = $company->getBankAccount();
            if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{4,30}$/', strtoupper(str_replace(' ', '', $iban)))) {
                $errors[] = new ValidationError(
                    'Company bank account must be a valid IBAN (BR-DE-2).',
                    'business',
                    'BR-DE-2'
                );
            }
        }

        // Lines validations
        $lines = $invoice->getLines();
        if ($lines->isEmpty()) {
            $errors[] = new ValidationError('Invoice has no lines.', 'business', 'XR-LINE-01');
        } else {
            foreach ($lines as $index => $line) {
                $lineNumber = $index + 1;

                if (empty($line->getDescription())) {
                    $errors[] = new ValidationError(
                        sprintf('Line %d has no description.', $lineNumber),
                        'business',
                        'XR-LINE-02'
                    );
                }

                if ($isRefund) {
                    if (bccomp($line->getQuantity(), '0', 4) === 0) {
                        $errors[] = new ValidationError(
                            sprintf('Line %d has zero quantity.', $lineNumber),
                            'business',
                            'XR-LINE-03'
                        );
                    }
                    if (bccomp($line->getUnitPrice(), '0', 2) === 0) {
                        $errors[] = new ValidationError(
                            sprintf('Line %d has zero unit price.', $lineNumber),
                            'business',
                            'XR-LINE-04'
                        );
                    }
                } else {
                    if (bccomp($line->getUnitPrice(), '0', 2) <= 0) {
                        $errors[] = new ValidationError(
                            sprintf('Line %d has zero or negative unit price.', $lineNumber),
                            'business',
                            'XR-LINE-05'
                        );
                    }
                    if (bccomp($line->getQuantity(), '0', 4) <= 0) {
                        $errors[] = new ValidationError(
                            sprintf('Line %d has zero or negative quantity.', $lineNumber),
                            'business',
                            'XR-LINE-06'
                        );
                    }
                }
            }
        }

        // Total validations
        if ($isRefund) {
            if (bccomp($invoice->getTotal(), '0', 2) === 0) {
                $errors[] = new ValidationError('Credit note total must be non-zero.', 'business', 'XR-TOT-01');
            }
        } else {
            if (bccomp($invoice->getTotal(), '0', 2) <= 0) {
                $errors[] = new ValidationError('Invoice total must be greater than zero.', 'business', 'XR-TOT-02');
            }
        }

        if (empty($errors)) {
            return ValidationResult::valid();
        }

        return ValidationResult::invalid($errors);
    }
}
