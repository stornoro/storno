<?php

namespace App\Service\EInvoice\Italy;

use App\DTO\Anaf\ValidationError;
use App\DTO\Anaf\ValidationResult;
use App\Entity\Invoice;

/**
 * Pre-submission validation for Italian SDI (Sistema di Interscambio).
 */
class SdiValidator
{
    public function validate(Invoice $invoice): ValidationResult
    {
        $errors = [];
        $isRefund = $invoice->getParentDocument() !== null;

        $company = $invoice->getCompany();
        $client = $invoice->getClient();

        // Company validations
        if ($company === null) {
            $errors[] = new ValidationError('Invoice has no associated company.', 'business', 'SDI-COMP-01');
        } else {
            if (empty($company->getCif())) {
                $errors[] = new ValidationError('Company has no Partita IVA.', 'business', 'SDI-COMP-02');
            }
            if (empty($company->getName())) {
                $errors[] = new ValidationError('Company has no name (Denominazione).', 'business', 'SDI-COMP-03');
            }
            if (empty($company->getAddress())) {
                $errors[] = new ValidationError('Company has no address (Indirizzo).', 'business', 'SDI-COMP-04');
            }
            if (empty($company->getCity())) {
                $errors[] = new ValidationError('Company has no city (Comune).', 'business', 'SDI-COMP-05');
            }
        }

        // Client validations
        if ($client !== null) {
            if ($client->getType() === 'company' && empty($client->getCui())) {
                $errors[] = new ValidationError('Client (company) has no Partita IVA.', 'business', 'SDI-CLI-01');
            }
            if ($client->getType() === 'individual' && empty($client->getCnp())) {
                $errors[] = new ValidationError('Client (individual) has no Codice Fiscale.', 'business', 'SDI-CLI-02');
            }
            if (empty($client->getName())) {
                $errors[] = new ValidationError('Client has no name.', 'business', 'SDI-CLI-03');
            }
            if (empty($client->getAddress())) {
                $errors[] = new ValidationError('Client has no address.', 'business', 'SDI-CLI-04');
            }

            // Routing: Italian companies must have CodiceDestinatario or PEC
            // Foreign recipients get XXXXXXX automatically
            $clientCountry = $client->getCountry();
            $isForeignClient = $clientCountry !== null && strtoupper($clientCountry) !== 'IT';

            if (!$isForeignClient && $client->getType() === 'company') {
                $identifiers = $client->getEinvoiceIdentifier('sdi');
                $hasCodice = $identifiers !== null && !empty($identifiers['codiceDestinatario']);
                $hasPec = $identifiers !== null && !empty($identifiers['pecAddress']);

                if (!$hasCodice && !$hasPec) {
                    $errors[] = new ValidationError(
                        'Italian client must have a Codice Destinatario or PEC address for SDI routing.',
                        'business',
                        'SDI-CLI-05'
                    );
                }
            }
        } else {
            if (empty($invoice->getReceiverName())) {
                $errors[] = new ValidationError('Invoice has no receiver name.', 'business', 'SDI-CLI-06');
            }
        }

        // Invoice metadata
        if (empty($invoice->getNumber())) {
            $errors[] = new ValidationError('Invoice has no number.', 'business', 'SDI-INV-01');
        }
        if ($invoice->getIssueDate() === null) {
            $errors[] = new ValidationError('Invoice has no issue date.', 'business', 'SDI-INV-02');
        }

        // Currency must be EUR (SDI accepts others but EUR is standard for domestic)
        // This is a warning, not a hard error — SDI does accept foreign currencies

        // Lines validations
        $lines = $invoice->getLines();
        if ($lines->isEmpty()) {
            $errors[] = new ValidationError('Invoice has no lines (DettaglioLinee required).', 'business', 'SDI-LINE-01');
        } else {
            foreach ($lines as $index => $line) {
                $lineNumber = $index + 1;

                if (empty($line->getDescription())) {
                    $errors[] = new ValidationError(
                        sprintf('Line %d has no description (Descrizione).', $lineNumber),
                        'business',
                        'SDI-LINE-02'
                    );
                }

                if (!$isRefund) {
                    if (bccomp($line->getQuantity(), '0', 4) <= 0) {
                        $errors[] = new ValidationError(
                            sprintf('Line %d has zero or negative quantity.', $lineNumber),
                            'business',
                            'SDI-LINE-03'
                        );
                    }
                    if (bccomp($line->getUnitPrice(), '0', 2) <= 0) {
                        $errors[] = new ValidationError(
                            sprintf('Line %d has zero or negative unit price.', $lineNumber),
                            'business',
                            'SDI-LINE-04'
                        );
                    }
                }
            }
        }

        // Total validations
        if (!$isRefund && bccomp($invoice->getTotal(), '0', 2) <= 0) {
            $errors[] = new ValidationError('Invoice total must be greater than zero.', 'business', 'SDI-TOT-01');
        }

        if (empty($errors)) {
            return ValidationResult::valid();
        }

        return ValidationResult::invalid($errors);
    }
}
