<?php

namespace App\Service\Anaf;

use App\DTO\Anaf\ValidationError;
use App\DTO\Anaf\ValidationResult;
use App\Entity\Invoice;
use Psr\Log\LoggerInterface;

class UblValidator
{
    public function __construct(
        private readonly EFacturaValidator $businessValidator,
        private readonly UblXmlGenerator $xmlGenerator,
        private readonly UblXsdValidator $xsdValidator,
        private readonly SchematronValidator $schematronValidator,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Quick validation: business rules + XSD. Fast, no Java needed.
     */
    public function validateQuick(Invoice $invoice): ValidationResult
    {
        $businessResult = $this->businessValidator->validate($invoice);

        if (!$businessResult->isValid) {
            $this->logValidationFailure($invoice, $businessResult, 'quick');
            return $businessResult;
        }

        try {
            $xml = $this->xmlGenerator->generate($invoice);
        } catch (\DomainException $e) {
            $merged = ValidationResult::merge($businessResult, ValidationResult::invalid([
                new ValidationError($e->getMessage(), 'xml_generation'),
            ]));
            $this->logValidationFailure($invoice, $merged, 'quick');
            return $merged;
        }
        $xsdResult = $this->xsdValidator->validate($xml);

        $merged = ValidationResult::merge($businessResult, $xsdResult);
        if (!$merged->isValid) {
            $this->logValidationFailure($invoice, $merged, 'quick');
        }

        return $merged;
    }

    /**
     * Full validation: business rules + XSD + Schematron. Requires Java for Schematron.
     */
    public function validateFull(Invoice $invoice): ValidationResult
    {
        $businessResult = $this->businessValidator->validate($invoice);

        if (!$businessResult->isValid) {
            $this->logValidationFailure($invoice, $businessResult, 'full');
            return $businessResult;
        }

        try {
            $xml = $this->xmlGenerator->generate($invoice);
        } catch (\DomainException $e) {
            $merged = ValidationResult::merge($businessResult, ValidationResult::invalid([
                new ValidationError($e->getMessage(), 'xml_generation'),
            ]));
            $this->logValidationFailure($invoice, $merged, 'full');
            return $merged;
        }
        $xsdResult = $this->xsdValidator->validate($xml);

        // Skip Schematron if XSD fails (invalid structure causes noise)
        if (!$xsdResult->isValid) {
            $merged = ValidationResult::merge($businessResult, $xsdResult);
            $this->logValidationFailure($invoice, $merged, 'full');
            return $merged;
        }

        // Skip Schematron for XK (Kosovo) — not in ISO 3166-1, ANAF rejects it via BR-CL-14
        if ($this->invoiceHasNonIsoCountry($invoice)) {
            return ValidationResult::merge($businessResult, $xsdResult);
        }

        $docType = $this->detectDocType($xml);
        $schematronResult = $this->schematronValidator->validate($xml, $docType);

        $merged = ValidationResult::merge($businessResult, $xsdResult, $schematronResult);
        if (!$merged->isValid) {
            $this->logValidationFailure($invoice, $merged, 'full');
        }

        return $merged;
    }

    public function isSchematronAvailable(): bool
    {
        return $this->schematronValidator->isAvailable();
    }

    private function logValidationFailure(Invoice $invoice, ValidationResult $result, string $mode): void
    {
        $errorMessages = array_map(
            fn ($e) => sprintf('[%s] %s', $e->source, $e->message),
            $result->errors,
        );

        $this->logger->warning('Invoice validation failed ({mode}): {invoiceNumber} [{invoiceId}]', [
            'mode' => $mode,
            'invoiceId' => (string) $invoice->getId(),
            'invoiceNumber' => $invoice->getNumber(),
            'companyId' => (string) $invoice->getCompany()?->getId(),
            'companyName' => $invoice->getCompany()?->getName(),
            'status' => $invoice->getStatus()->value,
            'errorCount' => count($result->errors),
            'errors' => $errorMessages,
            'warnings' => $result->warnings,
        ]);
    }

    private const NON_ISO_COUNTRY_CODES = ['XK'];

    private function invoiceHasNonIsoCountry(Invoice $invoice): bool
    {
        $countries = [];

        $company = $invoice->getCompany();
        if ($company) {
            $countries[] = $company->getCountry() ?? 'RO';
        }

        $buyerSnapshot = $invoice->getBuyerSnapshot();
        if ($buyerSnapshot) {
            $countries[] = $buyerSnapshot['country'] ?? 'RO';
        } elseif ($invoice->getClient()) {
            $countries[] = $invoice->getClient()->getCountry() ?? 'RO';
        }

        foreach ($countries as $code) {
            if (\in_array($code, self::NON_ISO_COUNTRY_CODES, true)) {
                return true;
            }
        }

        return false;
    }

    private function detectDocType(string $xml): string
    {
        if (str_contains($xml, '<CreditNote') || str_contains($xml, ':CreditNote')) {
            return 'CreditNote';
        }
        return 'Invoice';
    }
}
