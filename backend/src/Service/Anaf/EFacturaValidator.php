<?php

namespace App\Service\Anaf;

use App\DTO\Anaf\ValidationError;
use App\DTO\Anaf\ValidationResult;
use App\Entity\Invoice;
use App\Repository\VatRateRepository;
use App\Service\EuVatRateService;

class EFacturaValidator
{
    public function __construct(
        private readonly VatRateRepository $vatRateRepository,
        private readonly EuVatRateService $euVatRateService,
    ) {}

    /**
     * Perform pre-flight validation before submitting an invoice to ANAF e-Factura.
     */
    public function validate(Invoice $invoice): ValidationResult
    {
        $errors = [];
        $isRefund = $invoice->getParentDocument() !== null;

        // Load valid VAT rates from company's configuration
        $company = $invoice->getCompany();
        $validVatRates = ['0.00', '5.00', '9.00', '21.00']; // fallback
        if ($company) {
            $dbRates = $this->vatRateRepository->findActiveByCompany($company);
            if (!empty($dbRates)) {
                $validVatRates = array_map(fn($vr) => $vr->getRate(), $dbRates);
            }
        }

        // Include destination country VAT rates when OSS applies
        $client = $invoice->getClient();
        if (
            $company
            && $company->isOss()
            && $client
            && $client->getCountry() !== 'RO'
            && $client->isViesValid() !== true
        ) {
            $ossRates = $this->euVatRateService->getAllRates($client->getCountry());
            if ($ossRates) {
                foreach ($ossRates as $rateVal) {
                    $formatted = number_format((float) $rateVal, 2, '.', '');
                    if (!in_array($formatted, $validVatRates, true)) {
                        $validVatRates[] = $formatted;
                    }
                }
            }
        }

        // Company validations
        if ($company === null) {
            $errors[] = new ValidationError('Factura nu are o companie asociata.', 'business');
        } else {
            if (empty($company->getCif())) {
                $errors[] = new ValidationError('Compania nu are CUI completat.', 'business');
            }
            if (empty($company->getName())) {
                $errors[] = new ValidationError('Compania nu are denumirea completata.', 'business');
            }
            if (empty($company->getAddress())) {
                $errors[] = new ValidationError('Compania nu are adresa completata.', 'business');
            }
            if (empty($company->getCity())) {
                $errors[] = new ValidationError('Compania nu are orasul completat.', 'business');
            }
            if (empty($company->getCountry())) {
                $errors[] = new ValidationError('Compania nu are tara completata.', 'business');
            }
        }

        // Client/receiver validations
        if ($client !== null) {
            if ($client->getType() === 'company' && empty($client->getCui())) {
                $errors[] = new ValidationError('Clientul (persoana juridica) nu are CUI completat.', 'business');
            }
            if (empty($client->getName())) {
                $errors[] = new ValidationError('Clientul nu are denumirea completata.', 'business');
            }
            if (empty($client->getAddress())) {
                $errors[] = new ValidationError('Clientul nu are adresa completata.', 'business');
            }
            if (empty($client->getCity())) {
                $errors[] = new ValidationError('Clientul nu are orasul completat.', 'business');
            }
        } else {
            // No Client entity — require at least receiver name and CIF on the invoice itself
            if (empty($invoice->getReceiverName())) {
                $errors[] = new ValidationError('Factura nu are un destinatar (client sau nume destinatar).', 'business');
            }
            if (empty($invoice->getReceiverCif())) {
                $errors[] = new ValidationError('Factura nu are CUI-ul destinatarului completat.', 'business');
            }
        }

        // Invoice metadata validations
        if (empty($invoice->getNumber())) {
            $errors[] = new ValidationError('Factura nu are un numar atribuit.', 'business');
        }
        if ($invoice->getIssueDate() === null) {
            $errors[] = new ValidationError('Factura nu are data emiterii completata.', 'business');
        }

        // Lines validations
        $lines = $invoice->getLines();
        if ($lines->isEmpty()) {
            $errors[] = new ValidationError('Factura nu contine nicio linie.', 'business');
        } else {
            foreach ($lines as $index => $line) {
                $lineNumber = $index + 1;

                if (empty($line->getDescription())) {
                    $errors[] = new ValidationError(sprintf('Linia %d nu are descrierea completata.', $lineNumber), 'business');
                }

                if ($isRefund) {
                    // Refund: quantity must be non-zero (typically negative)
                    if (bccomp($line->getQuantity(), '0', 4) === 0) {
                        $errors[] = new ValidationError(sprintf('Linia %d are cantitatea zero.', $lineNumber), 'business');
                    }
                    if (bccomp($line->getUnitPrice(), '0', 2) === 0) {
                        $errors[] = new ValidationError(sprintf('Linia %d are pretul unitar zero.', $lineNumber), 'business');
                    }
                } else {
                    if (bccomp($line->getUnitPrice(), '0', 2) <= 0) {
                        $errors[] = new ValidationError(sprintf('Linia %d are pretul unitar zero sau negativ.', $lineNumber), 'business');
                    }
                    if (bccomp($line->getQuantity(), '0', 4) <= 0) {
                        $errors[] = new ValidationError(sprintf('Linia %d are cantitatea zero sau negativa.', $lineNumber), 'business');
                    }
                }

                if (!in_array($line->getVatRate(), $validVatRates, true)) {
                    $errors[] = new ValidationError(sprintf(
                        'Linia %d are o cota TVA invalida (%s%%). Cotele valide sunt: %s%%.',
                        $lineNumber,
                        $line->getVatRate(),
                        implode('%, ', $validVatRates)
                    ), 'business');
                }
            }
        }

        // Total validations
        if ($isRefund) {
            // Refund: total must be non-zero (typically negative)
            if (bccomp($invoice->getTotal(), '0', 2) === 0) {
                $errors[] = new ValidationError('Totalul facturii de rambursare trebuie sa fie diferit de zero.', 'business');
            }
        } else {
            if (bccomp($invoice->getTotal(), '0', 2) <= 0) {
                $errors[] = new ValidationError('Totalul facturii trebuie sa fie mai mare decat zero.', 'business');
            }
        }

        if (empty($errors)) {
            return ValidationResult::valid();
        }

        return ValidationResult::invalid($errors);
    }
}
