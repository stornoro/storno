<?php

namespace App\Service\Anaf;

use App\DTO\Anaf\ValidationError;
use App\DTO\Anaf\ValidationResult;
use App\Entity\Client;
use App\Entity\Company;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Enum\DocumentStatus;
use App\Enum\DocumentType;
use App\Enum\InvoiceDirection;
use App\Exception\PublicStornoGeneratorException;
use Psr\Log\LoggerInterface;

/**
 * Builds a storno (negative) invoice XML for the public, unauthenticated
 * "generator factura storno" tool on the landing site.
 *
 * Nothing is persisted: the request payload is turned into transient
 * Company / Client / Invoice entities, run through the same UblXmlGenerator
 * the product uses for ANAF submissions, then validated (XSD + Schematron).
 *
 * The output mirrors InvoiceManager::createStorno(): an Invoice (type 380)
 * with negated quantities and a BillingReference to the original document.
 */
final class PublicStornoGenerator
{
    public const MAX_LINES = 50;

    /** VAT rates accepted for Romanian invoices, including the pre-Aug-2025 ones. */
    private const ALLOWED_VAT_RATES = ['0.00', '5.00', '9.00', '11.00', '19.00', '21.00'];

    private const ALLOWED_VAT_CATEGORIES = ['S', 'Z', 'E', 'AE', 'O', 'K', 'G'];

    private const ALLOWED_UNITS = ['buc', 'kg', 'l', 'm', 'ora', 'zi', 'luna', 'set', 'pachet'];

    public function __construct(
        private readonly UblXmlGenerator $xmlGenerator,
        private readonly UblXsdValidator $xsdValidator,
        private readonly SchematronValidator $schematronValidator,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{
     *     xml: string,
     *     valid: bool,
     *     errors: list<array<string, string>>,
     *     warnings: list<string>,
     *     schematronChecked: bool,
     *     totals: array{subtotal: string, vatTotal: string, total: string, currency: string},
     *     filename: string
     * }
     *
     * @throws PublicStornoGeneratorException when the payload is not usable
     */
    public function generate(array $payload): array
    {
        $errors = [];

        $seller = $this->normalizeSeller($payload['seller'] ?? null, $errors);
        $buyer = $this->normalizeBuyer($payload['buyer'] ?? null, $errors);
        $original = $this->normalizeDocumentRef($payload['original'] ?? null, 'original', $errors, required: true);
        $storno = $this->normalizeDocumentRef($payload['storno'] ?? null, 'storno', $errors, required: false);
        $lines = $this->normalizeLines($payload['lines'] ?? null, $seller, $errors);

        $currency = strtoupper(trim((string) ($payload['currency'] ?? 'RON')));
        if ($currency !== 'RON') {
            $errors['currency'] = 'Generatorul public accepta doar facturi in RON.';
        }

        if ($errors !== []) {
            throw new PublicStornoGeneratorException($errors);
        }

        $invoice = $this->buildInvoice($seller, $buyer, $original, $storno, $lines, $currency);

        $xml = $this->xmlGenerator->generate($invoice);

        $xsdResult = $this->xsdValidator->validate($xml);
        $result = $xsdResult;
        $schematronChecked = false;

        if ($xsdResult->isValid) {
            try {
                if ($this->schematronValidator->isAvailable()) {
                    $schematronResult = $this->schematronValidator->validate($xml, 'Invoice');
                    $result = ValidationResult::merge($xsdResult, $schematronResult);
                    $schematronChecked = true;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Public storno generator: Schematron validation unavailable', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $warnings = $result->warnings;
        if (!$schematronChecked) {
            $warnings[] = 'Validarea Schematron (regulile CIUS-RO) nu a putut rula. XML-ul a trecut doar validarea de structura (XSD).';
        }

        return [
            'xml' => $xml,
            'valid' => $result->isValid,
            'errors' => array_map(static fn (ValidationError $e) => $e->toArray(), $result->errors),
            'warnings' => array_values($warnings),
            'schematronChecked' => $schematronChecked,
            'totals' => [
                'subtotal' => $invoice->getSubtotal(),
                'vatTotal' => $invoice->getVatTotal(),
                'total' => $invoice->getTotal(),
                'currency' => $currency,
            ],
            'filename' => $this->buildFilename($invoice->getNumber() ?? 'storno'),
        ];
    }

    /**
     * @param array<string, mixed> $seller
     * @param array<string, mixed> $buyer
     * @param array{number: string, issueDate: \DateTimeImmutable} $original
     * @param array{number: string, issueDate: \DateTimeImmutable, notes: ?string} $storno
     * @param list<array<string, mixed>> $lines
     */
    private function buildInvoice(array $seller, array $buyer, array $original, array $storno, array $lines, string $currency): Invoice
    {
        $company = new Company();
        $company->setName($seller['name']);
        $company->setCif((int) $seller['cif']);
        $company->setVatPayer($seller['vatPayer']);
        $company->setRegistrationNumber($seller['registrationNumber']);
        $company->setAddress($seller['address']);
        $company->setCity($seller['city']);
        $company->setState($seller['county']);
        $company->setCountry($seller['country']);
        $company->setEmail($seller['email']);
        $company->setPhone($seller['phone']);
        $company->setBankAccount($seller['bankAccount']);
        $company->setBankName($seller['bankName']);
        $company->setDefaultCurrency($currency);

        $client = new Client();
        $client->setType($buyer['type']);
        $client->setName($buyer['name']);
        $client->setCui($buyer['cui']);
        $client->setCnp($buyer['cnp']);
        $client->setIsVatPayer($buyer['vatPayer']);
        $client->setRegistrationNumber($buyer['registrationNumber']);
        $client->setAddress($buyer['address']);
        $client->setCity($buyer['city']);
        $client->setCounty($buyer['county']);
        $client->setCountry($buyer['country']);

        $parent = new Invoice();
        $parent->setNumber($original['number']);
        $parent->setIssueDate($original['issueDate']);
        $parent->setDocumentType(DocumentType::INVOICE);

        $invoice = new Invoice();
        $invoice->setCompany($company);
        $invoice->setClient($client);
        $invoice->snapshotBuyer($client);
        $invoice->setDocumentType(DocumentType::INVOICE);
        $invoice->setStatus(DocumentStatus::DRAFT);
        $invoice->setDirection(InvoiceDirection::OUTGOING);
        $invoice->setCurrency($currency);
        $invoice->setNumber($storno['number']);
        $invoice->setIssueDate($storno['issueDate']);
        $invoice->setParentDocument($parent);
        $invoice->setPlatitorTva($seller['vatPayer']);
        $invoice->setSenderName($seller['name']);
        $invoice->setSenderCif($seller['cif']);
        $invoice->setReceiverName($buyer['name']);
        $invoice->setReceiverCif($buyer['cui'] ?? $buyer['cnp']);
        $invoice->setNotes($storno['notes'] ?? sprintf(
            'Storno factura #%s din %s',
            $original['number'],
            $original['issueDate']->format('d.m.Y'),
        ));

        $subtotal = '0.00';
        $vatTotal = '0.00';
        $position = 1;

        foreach ($lines as $line) {
            $invoiceLine = new InvoiceLine();
            $invoiceLine->setPosition($position++);
            $invoiceLine->setDescription($line['description']);
            $invoiceLine->setQuantity($line['quantity']);
            $invoiceLine->setUnitOfMeasure($line['unitOfMeasure']);
            $invoiceLine->setUnitPrice($line['unitPrice']);
            $invoiceLine->setVatRate($line['vatRate']);
            $invoiceLine->setVatCategoryCode($line['vatCategoryCode']);
            $invoiceLine->setVatIncluded($line['vatIncluded']);
            $invoiceLine->setLineTotal($line['lineTotal']);
            $invoiceLine->setVatAmount($line['vatAmount']);

            $subtotal = bcadd($subtotal, $line['lineTotal'], 2);
            $vatTotal = bcadd($vatTotal, $line['vatAmount'], 2);

            $invoice->addLine($invoiceLine);
        }

        $invoice->setSubtotal($subtotal);
        $invoice->setVatTotal($vatTotal);
        $invoice->setDiscount('0.00');
        $invoice->setTotal(bcadd($subtotal, $vatTotal, 2));

        return $invoice;
    }

    /**
     * @param array<string, string> $errors
     *
     * @return array<string, mixed>
     */
    private function normalizeSeller(mixed $raw, array &$errors): array
    {
        if (!is_array($raw)) {
            $errors['seller'] = 'Datele furnizorului lipsesc.';

            return [];
        }

        $cif = $this->normalizeCif($raw['cif'] ?? null);
        if ($cif === null) {
            $errors['seller.cif'] = 'CUI-ul furnizorului este invalid (2-10 cifre, cu sau fara RO).';
        }

        $seller = [
            'name' => $this->requireString($raw, 'name', 'seller.name', 'Denumirea furnizorului este obligatorie.', $errors, 200),
            'cif' => $cif ?? '',
            'vatPayer' => $this->toBool($raw['vatPayer'] ?? true),
            'registrationNumber' => $this->optionalString($raw, 'registrationNumber', 100),
            'address' => $this->requireString($raw, 'address', 'seller.address', 'Adresa furnizorului este obligatorie.', $errors, 150),
            'city' => $this->requireString($raw, 'city', 'seller.city', 'Orasul furnizorului este obligatoriu.', $errors, 50),
            'county' => $this->requireString($raw, 'county', 'seller.county', 'Judetul furnizorului este obligatoriu.', $errors, 50),
            'country' => $this->normalizeCountry($raw['country'] ?? 'RO'),
            'email' => $this->optionalString($raw, 'email', 100),
            'phone' => $this->optionalString($raw, 'phone', 100),
            'bankAccount' => $this->optionalString($raw, 'bankAccount', 50),
            'bankName' => $this->optionalString($raw, 'bankName', 100),
        ];

        if ($seller['email'] !== null && !filter_var($seller['email'], \FILTER_VALIDATE_EMAIL)) {
            $errors['seller.email'] = 'Emailul furnizorului este invalid.';
        }

        return $seller;
    }

    /**
     * @param array<string, string> $errors
     *
     * @return array<string, mixed>
     */
    private function normalizeBuyer(mixed $raw, array &$errors): array
    {
        if (!is_array($raw)) {
            $errors['buyer'] = 'Datele clientului lipsesc.';

            return [];
        }

        $type = ($raw['type'] ?? 'company') === 'individual' ? 'individual' : 'company';
        $cui = null;
        $cnp = null;

        if ($type === 'company') {
            $cui = $this->normalizeCif($raw['cui'] ?? null);
            if ($cui === null) {
                $errors['buyer.cui'] = 'CUI-ul clientului este invalid (2-10 cifre, cu sau fara RO).';
            }
        } else {
            $cnp = preg_replace('/\D/', '', (string) ($raw['cnp'] ?? ''));
            if ($cnp === '' || strlen($cnp) !== 13) {
                $errors['buyer.cnp'] = 'CNP-ul clientului trebuie sa aiba 13 cifre.';
            }
        }

        $vatPayer = array_key_exists('vatPayer', $raw)
            ? $this->toBool($raw['vatPayer'])
            : ($type === 'company');

        return [
            'type' => $type,
            'name' => $this->requireString($raw, 'name', 'buyer.name', 'Denumirea clientului este obligatorie.', $errors, 200),
            'cui' => $cui,
            'cnp' => $cnp,
            'vatPayer' => $vatPayer,
            'registrationNumber' => $this->optionalString($raw, 'registrationNumber', 100),
            'address' => $this->requireString($raw, 'address', 'buyer.address', 'Adresa clientului este obligatorie.', $errors, 150),
            'city' => $this->requireString($raw, 'city', 'buyer.city', 'Orasul clientului este obligatoriu.', $errors, 50),
            'county' => $this->requireString($raw, 'county', 'buyer.county', 'Judetul clientului este obligatoriu.', $errors, 50),
            'country' => $this->normalizeCountry($raw['country'] ?? 'RO'),
        ];
    }

    /**
     * @param array<string, string> $errors
     *
     * @return array{number: string, issueDate: \DateTimeImmutable, notes: ?string}
     */
    private function normalizeDocumentRef(mixed $raw, string $prefix, array &$errors, bool $required): array
    {
        $raw = is_array($raw) ? $raw : [];

        $number = trim((string) ($raw['number'] ?? ''));
        if ($number === '') {
            if ($required) {
                $errors[$prefix . '.number'] = 'Numarul facturii initiale este obligatoriu.';
            } else {
                $number = 'STORNO-' . date('Ymd');
            }
        } elseif (mb_strlen($number) > 30) {
            $errors[$prefix . '.number'] = 'Numarul facturii poate avea cel mult 30 de caractere.';
        } elseif (!preg_match('/\d/', $number)) {
            // [BR-RO-010] the number must contain at least one digit
            $errors[$prefix . '.number'] = 'Numarul facturii trebuie sa contina cel putin o cifra.';
        }

        $rawDate = trim((string) ($raw['issueDate'] ?? ''));
        $issueDate = null;
        if ($rawDate === '') {
            if ($required) {
                $errors[$prefix . '.issueDate'] = 'Data facturii initiale este obligatorie.';
            } else {
                $issueDate = new \DateTimeImmutable('today');
            }
        } else {
            $issueDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $rawDate) ?: null;
            if ($issueDate === null || $issueDate->format('Y-m-d') !== $rawDate) {
                $errors[$prefix . '.issueDate'] = 'Data trebuie sa fie in formatul AAAA-LL-ZZ.';
            }
        }

        $notes = $this->optionalString($raw, 'notes', 300);

        return [
            'number' => $number,
            'issueDate' => $issueDate ?? new \DateTimeImmutable('today'),
            'notes' => $notes,
        ];
    }

    /**
     * @param array<string, mixed> $seller
     * @param array<string, string> $errors
     *
     * @return list<array<string, mixed>>
     */
    private function normalizeLines(mixed $raw, array $seller, array &$errors): array
    {
        if (!is_array($raw) || $raw === []) {
            $errors['lines'] = 'Factura trebuie sa contina cel putin o linie.';

            return [];
        }

        if (count($raw) > self::MAX_LINES) {
            $errors['lines'] = sprintf('Generatorul public accepta cel mult %d linii.', self::MAX_LINES);

            return [];
        }

        $sellerIsVatPayer = (bool) ($seller['vatPayer'] ?? true);
        $lines = [];

        foreach (array_values($raw) as $index => $line) {
            $n = $index + 1;
            if (!is_array($line)) {
                $errors["lines.$index"] = sprintf('Linia %d este invalida.', $n);
                continue;
            }

            $description = trim((string) ($line['description'] ?? ''));
            if ($description === '') {
                $errors["lines.$index.description"] = sprintf('Linia %d nu are descriere.', $n);
            }

            $quantity = $this->toDecimal($line['quantity'] ?? null, 4);
            if ($quantity === null || bccomp($quantity, '0', 4) <= 0) {
                $errors["lines.$index.quantity"] = sprintf('Linia %d: cantitatea trebuie sa fie pozitiva (asa cum apare pe factura initiala).', $n);
            }

            $unitPrice = $this->toDecimal($line['unitPrice'] ?? null, 2);
            if ($unitPrice === null || bccomp($unitPrice, '0', 2) <= 0) {
                $errors["lines.$index.unitPrice"] = sprintf('Linia %d: pretul unitar trebuie sa fie pozitiv.', $n);
            }

            $vatRate = $this->toDecimal($line['vatRate'] ?? ($sellerIsVatPayer ? '21' : '0'), 2);
            if ($vatRate === null || !in_array($vatRate, self::ALLOWED_VAT_RATES, true)) {
                $errors["lines.$index.vatRate"] = sprintf('Linia %d: cota TVA trebuie sa fie una dintre 0, 5, 9, 11, 19 sau 21.', $n);
                $vatRate = '0.00';
            }
            if (!$sellerIsVatPayer && bccomp($vatRate, '0', 2) !== 0) {
                $errors["lines.$index.vatRate"] = sprintf('Linia %d: un furnizor neplatitor de TVA nu poate factura cu TVA.', $n);
            }

            $category = strtoupper(trim((string) ($line['vatCategoryCode'] ?? '')));
            if ($category === '') {
                $category = !$sellerIsVatPayer ? 'O' : (bccomp($vatRate, '0', 2) === 0 ? 'Z' : 'S');
            } elseif (!in_array($category, self::ALLOWED_VAT_CATEGORIES, true)) {
                $errors["lines.$index.vatCategoryCode"] = sprintf('Linia %d: categoria TVA este invalida.', $n);
                $category = 'S';
            }

            $unit = mb_strtolower(trim((string) ($line['unitOfMeasure'] ?? 'buc')));
            if (!in_array($unit, self::ALLOWED_UNITS, true)) {
                $unit = 'buc';
            }

            $vatIncluded = $this->toBool($line['vatIncluded'] ?? false);

            if ($quantity === null || $unitPrice === null) {
                continue;
            }

            // Mirror InvoiceManager::createStorno(): negate the quantity, keep the price.
            $negatedQty = bcmul($quantity, '-1', 4);
            $qty = (float) $negatedQty;
            $price = (float) $unitPrice;
            $rate = (float) $vatRate;

            if ($vatIncluded) {
                $gross = $qty * $price;
                $net = $gross / (1 + $rate / 100);
                $vat = $gross - $net;
            } else {
                $net = $qty * $price;
                $vat = $net * ($rate / 100);
            }

            $lines[] = [
                'description' => mb_substr($description, 0, 200),
                'quantity' => $negatedQty,
                'unitOfMeasure' => $unit,
                'unitPrice' => $unitPrice,
                'vatRate' => $vatRate,
                'vatCategoryCode' => $category,
                'vatIncluded' => $vatIncluded,
                'lineTotal' => number_format($net, 2, '.', ''),
                'vatAmount' => number_format($vat, 2, '.', ''),
            ];
        }

        return $lines;
    }

    private function normalizeCif(mixed $raw): ?string
    {
        $digits = strtoupper(trim((string) $raw));
        $digits = preg_replace('/^RO/', '', $digits) ?? '';
        $digits = preg_replace('/\s+/', '', $digits) ?? '';

        if (!preg_match('/^\d{2,10}$/', $digits)) {
            return null;
        }

        return ltrim($digits, '0') ?: null;
    }

    private function normalizeCountry(mixed $raw): string
    {
        $code = strtoupper(trim((string) $raw));

        return preg_match('/^[A-Z]{2}$/', $code) ? $code : 'RO';
    }

    /**
     * @param array<string, mixed> $raw
     * @param array<string, string> $errors
     */
    private function requireString(array $raw, string $key, string $errorKey, string $message, array &$errors, int $maxLength): string
    {
        $value = trim((string) ($raw[$key] ?? ''));
        if ($value === '') {
            $errors[$errorKey] = $message;
        }

        return mb_substr($value, 0, $maxLength);
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function optionalString(array $raw, string $key, int $maxLength): ?string
    {
        $value = trim((string) ($raw[$key] ?? ''));

        return $value === '' ? null : mb_substr($value, 0, $maxLength);
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, \FILTER_VALIDATE_BOOLEAN, \FILTER_NULL_ON_FAILURE) ?? false;
    }

    private function toDecimal(mixed $value, int $scale): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = str_replace(',', '.', trim((string) $value));
        if (!is_numeric($normalized)) {
            return null;
        }

        return number_format((float) $normalized, $scale, '.', '');
    }

    private function buildFilename(string $number): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', $number) ?? 'storno';

        return 'storno-' . trim($safe, '-') . '.xml';
    }
}
