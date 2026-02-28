<?php

namespace App\Service\Anaf;

use App\DTO\Anaf\ValidationResult;
use App\Entity\DeliveryNote;
use App\Entity\DeliveryNoteLine;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Two-phase validator for e-Transport delivery note submissions.
 *
 * Phase 1: Schematron-equivalent business rules validated directly against
 *          the DeliveryNote entity before XML generation.
 * Phase 2: XSD structural validation of the generated XML string using
 *          schema_ETR_v2.xsd.
 *
 * Operation type constants (codTipOperatiune):
 *   10 = Achizitie intracomunitara
 *   20 = Livrare intracomunitara
 *   30 = Transport pe teritoriul national (TTN)
 *   40 = Import
 *   50 = Export
 *   60 = Tranzactie intracomunitara - Intrare (DIN)
 *   70 = Tranzactie intracomunitara - Iesire (DIE)
 */
class ETransportValidator
{
    private const OP_TTN = 30;
    private const OP_DIN = 60;
    private const OP_DIE = 70;

    /** Characters valid in UIT positions 1-14. */
    private const UIT_CHARSET = '0123456789ACDEFHJKLMNPQRTUVWXY';

    private readonly string $xsdPath;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        string $projectDir,
        private readonly ETransportSchematronValidator $schematronValidator,
        private readonly LoggerInterface $logger,
    ) {
        $this->xsdPath = $projectDir . '/resources/etransport/schema_ETR_v2.xsd';
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    public function isSchematronAvailable(): bool
    {
        return $this->schematronValidator->isAvailable();
    }

    /**
     * Full three-phase validation: entity business rules, XSD, then Schematron.
     *
     * @return array<array{rule: string, message: string, severity: string}>
     */
    public function validate(DeliveryNote $note, string $xml): array
    {
        $errors = $this->validateEntity($note);
        if (!empty($errors)) {
            return $errors;
        }

        $xsdErrors = $this->validateXml($xml);
        if (!empty($xsdErrors)) {
            return array_merge($errors, $xsdErrors);
        }

        $schematronResult = $this->validateSchematron($xml);
        if (!$schematronResult->isValid) {
            foreach ($schematronResult->errors as $error) {
                $errors[] = [
                    'rule'     => $error->ruleId ?? 'SCH',
                    'message'  => $error->message,
                    'severity' => 'fatal',
                ];
            }
        }

        return $errors;
    }

    /**
     * Phase 3 — Schematron XSLT2 validation.
     *
     * Validates the generated XML against the pre-compiled Schematron rules
     * using Saxon-HE. Gracefully degrades if Java/Saxon unavailable.
     */
    public function validateSchematron(string $xml): ValidationResult
    {
        if (!$this->schematronValidator->isAvailable()) {
            $this->logger->warning('ETransport Schematron validation skipped: validator unavailable');
            return ValidationResult::valid();
        }

        return $this->schematronValidator->validate($xml);
    }

    /**
     * Phase 1 — Schematron-equivalent business rules.
     *
     * Validates the DeliveryNote entity data before XML is generated so that
     * errors can be presented to the user in plain language without requiring
     * an XML round-trip.
     *
     * @return array<array{rule: string, message: string, severity: string}>
     */
    public function validateEntity(DeliveryNote $note): array
    {
        $errors = [];
        $opType = $note->getEtransportOperationType();
        $isTtn = ($opType === self::OP_TTN);
        $isExemptFromWeightValue = in_array($opType, [self::OP_DIN, self::OP_DIE], true);

        // --- BR-002: codDeclarant must be a valid CUI/CIF or CNP format ------
        $cif = (string) $note->getCompany()?->getCif();
        if (!$this->isValidTin($cif)) {
            $errors[] = $this->error(
                'BR-002',
                sprintf(
                    'Codul fiscal al declarantului (CUI/CIF/CNP) nu are un format valid (valoare: "%s"). '
                    . 'Se accepta 2-10 cifre (CUI/CIF) sau exact 13 cifre (CNP).',
                    $cif,
                ),
            );
        }

        // --- BR-005: For TTN, partner country must be RO ---------------------
        if ($isTtn) {
            $partnerCountry = $note->getClient()?->getCountry();
            if ($partnerCountry !== null && $partnerCountry !== 'RO') {
                $errors[] = $this->error(
                    'BR-005',
                    sprintf(
                        'Pentru transport pe teritoriul national (TTN), tara partenerului comercial '
                        . 'TREBUIE sa fie "RO" (Romania). Valoare curenta: "%s".',
                        $partnerCountry,
                    ),
                );
            }
        }

        // --- BR-007: For TTN, partner code must be valid CUI/CNP or 'PF' -----
        if ($isTtn) {
            $partnerCif = $note->getClient()?->getCui();
            if ($partnerCif !== null && $partnerCif !== '') {
                if (!$this->isValidTin($partnerCif) && $partnerCif !== 'PF') {
                    $errors[] = $this->error(
                        'BR-007',
                        sprintf(
                            'Codul de identificare fiscala al partenerului comercial nu are un format valid '
                            . '(valoare: "%s"). Se accepta 2-10 cifre (CUI/CIF), exact 13 cifre (CNP) sau "PF".',
                            $partnerCif,
                        ),
                    );
                }
            }
        }

        // --- BR-019: UIT checksum validation ---------------------------------
        $uit = $note->getEtransportUit();
        if ($uit !== null && $uit !== '') {
            $uitError = $this->validateUit($uit);
            if ($uitError !== null) {
                $errors[] = $this->error('BR-019', $uitError);
            }
        }

        // --- BR-070: For TTN, codScopOperatiune must be 101, 704, 705, 9901 --
        // The purpose code is stored at line level (codScopOperatiune on bunuriTransportate).
        // Each line participating in a TTN transport must carry one of the allowed values.
        if ($isTtn) {
            foreach ($note->getLines() as $index => $line) {
                $lineNum = $index + 1;
                $purposeCode = $line->getPurposeCode();
                if ($purposeCode !== null && !in_array($purposeCode, [101, 704, 705, 9901], true)) {
                    $errors[] = $this->error(
                        'BR-070',
                        sprintf(
                            'Linia %d: pentru transport pe teritoriul national (TTN), scopul operatiunii '
                            . '(codScopOperatiune) TREBUIE sa fie 101, 704, 705 sau 9901 (valoare: %d).',
                            $lineNum,
                            $purposeCode,
                        ),
                    );
                }
            }
        }

        // --- BR-209: For TTN with RO transporter, transporter code must be valid CUI/CNP or 'PF' ---
        if ($isTtn) {
            $transporterCountry = $note->getEtransportTransporterCountry();
            $transporterCode = $note->getEtransportTransporterCode();
            if ($transporterCountry === 'RO' && $transporterCode !== null && $transporterCode !== '') {
                if (!$this->isValidTin($transporterCode) && $transporterCode !== 'PF') {
                    $errors[] = $this->error(
                        'BR-209',
                        sprintf(
                            'Codul organizatorului de transport nu are un format valid pentru Romania '
                            . '(valoare: "%s"). Se accepta 2-10 cifre (CUI/CIF), exact 13 cifre (CNP) sau "PF".',
                            $transporterCode,
                        ),
                    );
                }
            }
        }

        // --- BR-210/211: Route start/end locatie must have county, locality, street (TTN) ---
        if ($isTtn) {
            // Start location (BG-6 / locStartTraseuRutier uses locatie for TTN)
            if (
                $note->getEtransportStartCounty() === null
                || empty($note->getEtransportStartLocality())
                || empty($note->getEtransportStartStreet())
            ) {
                $errors[] = $this->error(
                    'BR-210',
                    'Locul de start al traseului rutier este incomplet. '
                    . 'Judetul, localitatea si strada sunt obligatorii pentru transport pe teritoriul national (TTN).',
                );
            }

            // End location (BG-8 / locFinalTraseuRutier uses locatie for TTN)
            if (
                $note->getEtransportEndCounty() === null
                || empty($note->getEtransportEndLocality())
                || empty($note->getEtransportEndStreet())
            ) {
                $errors[] = $this->error(
                    'BR-211',
                    'Locul de final al traseului rutier este incomplet. '
                    . 'Judetul, localitatea si strada sunt obligatorii pentru transport pe teritoriul national (TTN).',
                );
            }
        }

        // --- BR-214: Locality name must be 2-100 chars -----------------------
        $startLocality = $note->getEtransportStartLocality();
        if ($startLocality !== null && $startLocality !== '') {
            $len = mb_strlen($startLocality);
            if ($len < 2 || $len > 100) {
                $errors[] = $this->error(
                    'BR-214',
                    sprintf(
                        'Denumirea localitatii de start TREBUIE sa aiba intre 2 si 100 de caractere (lungime curenta: %d).',
                        $len,
                    ),
                );
            }
        }

        $endLocality = $note->getEtransportEndLocality();
        if ($endLocality !== null && $endLocality !== '') {
            $len = mb_strlen($endLocality);
            if ($len < 2 || $len > 100) {
                $errors[] = $this->error(
                    'BR-214',
                    sprintf(
                        'Denumirea localitatii de final TREBUIE sa aiba intre 2 si 100 de caractere (lungime curenta: %d).',
                        $len,
                    ),
                );
            }
        }

        // --- BR-215: Street name must be 2-100 chars -------------------------
        $startStreet = $note->getEtransportStartStreet();
        if ($startStreet !== null && $startStreet !== '') {
            $len = mb_strlen($startStreet);
            if ($len < 2 || $len > 100) {
                $errors[] = $this->error(
                    'BR-215',
                    sprintf(
                        'Denumirea strazii de start TREBUIE sa aiba intre 2 si 100 de caractere (lungime curenta: %d).',
                        $len,
                    ),
                );
            }
        }

        $endStreet = $note->getEtransportEndStreet();
        if ($endStreet !== null && $endStreet !== '') {
            $len = mb_strlen($endStreet);
            if ($len < 2 || $len > 100) {
                $errors[] = $this->error(
                    'BR-215',
                    sprintf(
                        'Denumirea strazii de final TREBUIE sa aiba intre 2 si 100 de caractere (lungime curenta: %d).',
                        $len,
                    ),
                );
            }
        }

        // --- BR-031: Vehicle number format: 2-20 chars, only A-Z and 0-9 ----
        $vehicleNumber = $note->getEtransportVehicleNumber();
        if ($vehicleNumber !== null && $vehicleNumber !== '') {
            if (!preg_match('/^[A-Z0-9]{2,20}$/', $vehicleNumber)) {
                $errors[] = $this->error(
                    'BR-031',
                    sprintf(
                        'Numarul de inmatriculare al vehiculului nu are un format valid (valoare: "%s"). '
                        . 'Se accepta 2-20 caractere alfanumerice majuscule (A-Z, 0-9).',
                        $vehicleNumber,
                    ),
                );
            }
        }

        // --- BR-032: Trailer 1 number format ---------------------------------
        $trailer1 = $note->getEtransportTrailer1();
        if ($trailer1 !== null && $trailer1 !== '') {
            if (!preg_match('/^[A-Z0-9]{2,20}$/', $trailer1)) {
                $errors[] = $this->error(
                    'BR-032',
                    sprintf(
                        'Numarul de inmatriculare al remorcii 1 nu are un format valid (valoare: "%s"). '
                        . 'Se accepta 2-20 caractere alfanumerice majuscule (A-Z, 0-9).',
                        $trailer1,
                    ),
                );
            }
        }

        // --- BR-033: Trailer 2 number format ---------------------------------
        $trailer2 = $note->getEtransportTrailer2();
        if ($trailer2 !== null && $trailer2 !== '') {
            if (!preg_match('/^[A-Z0-9]{2,20}$/', $trailer2)) {
                $errors[] = $this->error(
                    'BR-033',
                    sprintf(
                        'Numarul de inmatriculare al remorcii 2 nu are un format valid (valoare: "%s"). '
                        . 'Se accepta 2-20 caractere alfanumerice majuscule (A-Z, 0-9).',
                        $trailer2,
                    ),
                );
            }
        }

        // --- Per-line validations --------------------------------------------
        $lines = $note->getLines();

        foreach ($lines as $index => $line) {
            $lineNum = $index + 1;

            // --- BR-206: tariffCode required when not DIN/DIE ----------------
            if (!$isExemptFromWeightValue) {
                if (empty($line->getTariffCode())) {
                    $errors[] = $this->error(
                        'BR-206',
                        sprintf(
                            'Linia %d: codul tarifar (codTarifar) este obligatoriu pentru aceasta operatiune.',
                            $lineNum,
                        ),
                    );
                }
            }

            // --- BR-207: netWeight required when not DIN/DIE -----------------
            if (!$isExemptFromWeightValue) {
                if ($line->getNetWeight() === null || $line->getNetWeight() === '') {
                    $errors[] = $this->error(
                        'BR-207',
                        sprintf(
                            'Linia %d: greutatea neta (greutateNeta) este obligatorie pentru aceasta operatiune.',
                            $lineNum,
                        ),
                    );
                }
            }

            // --- BR-208: valueWithoutVat required when not DIN/DIE -----------
            if (!$isExemptFromWeightValue) {
                if ($line->getValueWithoutVat() === null || $line->getValueWithoutVat() === '') {
                    $errors[] = $this->error(
                        'BR-208',
                        sprintf(
                            'Linia %d: valoarea fara TVA (valoareLeiFaraTva) este obligatorie pentru aceasta operatiune.',
                            $lineNum,
                        ),
                    );
                }
            }

            // --- BR-218: grossWeight required for each line ------------------
            if ($line->getGrossWeight() === null || $line->getGrossWeight() === '') {
                $errors[] = $this->error(
                    'BR-218',
                    sprintf(
                        'Linia %d: greutatea bruta (greutateBruta) este obligatorie.',
                        $lineNum,
                    ),
                );
            }

            // --- BR-020: grossWeight >= netWeight ----------------------------
            $grossWeight = $line->getGrossWeight();
            $netWeight = $line->getNetWeight();
            if ($grossWeight !== null && $grossWeight !== '' && $netWeight !== null && $netWeight !== '') {
                if (bccomp($grossWeight, $netWeight, 2) < 0) {
                    $errors[] = $this->error(
                        'BR-020',
                        sprintf(
                            'Linia %d: greutatea bruta (%s) TREBUIE sa fie mai mare sau egala cu greutatea neta (%s).',
                            $lineNum,
                            $grossWeight,
                            $netWeight,
                        ),
                    );
                }
            }

            // --- BR-027: cantitate must be positive (> 0) --------------------
            if (bccomp($line->getQuantity(), '0', 4) <= 0) {
                $errors[] = $this->error(
                    'BR-027',
                    sprintf(
                        'Linia %d: cantitatea bunului transportat TREBUIE sa fie mai mare decat zero (valoare: %s).',
                        $lineNum,
                        $line->getQuantity(),
                    ),
                );
            }

            // --- BR-027 / numeric constraint: cantitate max 12 integer digits + 2 decimal ---
            if (!$this->isValidNum12_2($line->getQuantity())) {
                $errors[] = $this->error(
                    'BR-027',
                    sprintf(
                        'Linia %d: cantitatea depaseste formatul numeric permis '
                        . '(maxim 12 cifre intregi si 2 zecimale, valoare: %s).',
                        $lineNum,
                        $line->getQuantity(),
                    ),
                );
            }

            // --- Numeric constraint: netWeight (>= 0, max 12.2) --------------
            if ($netWeight !== null && $netWeight !== '') {
                if (bccomp($netWeight, '0', 2) < 0) {
                    $errors[] = $this->error(
                        'BR-027',
                        sprintf(
                            'Linia %d: greutatea neta nu poate fi negativa (valoare: %s).',
                            $lineNum,
                            $netWeight,
                        ),
                    );
                }
                if (!$this->isValidNum12_2($netWeight)) {
                    $errors[] = $this->error(
                        'BR-027',
                        sprintf(
                            'Linia %d: greutatea neta depaseste formatul numeric permis '
                            . '(maxim 12 cifre intregi si 2 zecimale, valoare: %s).',
                            $lineNum,
                            $netWeight,
                        ),
                    );
                }
            }

            // --- Numeric constraint: grossWeight (>= 0, max 12.2) ------------
            if ($grossWeight !== null && $grossWeight !== '') {
                if (bccomp($grossWeight, '0', 2) < 0) {
                    $errors[] = $this->error(
                        'BR-027',
                        sprintf(
                            'Linia %d: greutatea bruta nu poate fi negativa (valoare: %s).',
                            $lineNum,
                            $grossWeight,
                        ),
                    );
                }
                if (!$this->isValidNum12_2($grossWeight)) {
                    $errors[] = $this->error(
                        'BR-027',
                        sprintf(
                            'Linia %d: greutatea bruta depaseste formatul numeric permis '
                            . '(maxim 12 cifre intregi si 2 zecimale, valoare: %s).',
                            $lineNum,
                            $grossWeight,
                        ),
                    );
                }
            }

            // --- Numeric constraint: valueWithoutVat (>= 0, max 12.2) --------
            $valueWithoutVat = $line->getValueWithoutVat();
            if ($valueWithoutVat !== null && $valueWithoutVat !== '') {
                if (bccomp($valueWithoutVat, '0', 2) < 0) {
                    $errors[] = $this->error(
                        'BR-027',
                        sprintf(
                            'Linia %d: valoarea fara TVA nu poate fi negativa (valoare: %s).',
                            $lineNum,
                            $valueWithoutVat,
                        ),
                    );
                }
                if (!$this->isValidNum12_2($valueWithoutVat)) {
                    $errors[] = $this->error(
                        'BR-027',
                        sprintf(
                            'Linia %d: valoarea fara TVA depaseste formatul numeric permis '
                            . '(maxim 12 cifre intregi si 2 zecimale, valoare: %s).',
                            $lineNum,
                            $valueWithoutVat,
                        ),
                    );
                }
            }
        }

        // --- Basic structural checks -----------------------------------------

        // Vehicle number is required
        if (empty($note->getEtransportVehicleNumber())) {
            $errors[] = $this->error(
                'BR-031',
                'Numarul de inmatriculare al vehiculului este obligatoriu.',
            );
        }

        // Transport date is required
        if ($note->getEtransportTransportDate() === null) {
            $errors[] = $this->error(
                'BR-002',
                'Data transportului este obligatorie.',
            );
        }

        // Transporter country is required
        if (empty($note->getEtransportTransporterCountry())) {
            $errors[] = $this->error(
                'BR-002',
                'Tara organizatorului de transport este obligatorie.',
            );
        }

        // Transporter name is required
        if (empty($note->getEtransportTransporterName())) {
            $errors[] = $this->error(
                'BR-002',
                'Denumirea organizatorului de transport este obligatorie.',
            );
        }

        // At least one line is required
        if ($note->getLines()->isEmpty()) {
            $errors[] = $this->error(
                'BR-206',
                'Avizul de expeditie nu contine nicio linie de bunuri transportate.',
            );
        }

        // Per-line basic checks
        foreach ($note->getLines() as $index => $line) {
            $lineNum = $index + 1;

            // Each line must have a description (denumireMarfa)
            if (empty($line->getDescription())) {
                $errors[] = $this->error(
                    'BR-206',
                    sprintf('Linia %d: denumirea marfii (denumireMarfa) este obligatorie.', $lineNum),
                );
            }

            // Each line must have quantity > 0 (covered above in BR-027 block,
            // but also checked here as a basic structural constraint)
            if (bccomp($line->getQuantity(), '0', 4) <= 0) {
                // Already reported under BR-027; skip duplicate
            }

            // Each line must have a unit of measure code
            if (empty($line->getUnitOfMeasureCode())) {
                $errors[] = $this->error(
                    'BR-206',
                    sprintf('Linia %d: codul unitatii de masura (unitOfMeasureCode) este obligatoriu.', $lineNum),
                );
            }
        }

        return $errors;
    }

    /**
     * Phase 2 — XSD structural validation.
     *
     * Loads the generated XML into a DOMDocument and validates it against the
     * official ANAF schema_ETR_v2.xsd schema file.
     *
     * @return array<array{rule: string, message: string, severity: string}>
     */
    public function validateXml(string $xml): array
    {
        $errors = [];

        $previousUseErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $dom = new \DOMDocument();

        if (trim($xml) === '') {
            libxml_use_internal_errors($previousUseErrors);
            return [$this->xsdError('XML-ul este gol.')];
        }

        if (!$dom->loadXML($xml)) {
            foreach (libxml_get_errors() as $libxmlError) {
                $errors[] = $this->xsdError(
                    sprintf(
                        'XML malformat (linia %d): %s',
                        $libxmlError->line,
                        trim($libxmlError->message),
                    ),
                );
            }
            libxml_clear_errors();
            libxml_use_internal_errors($previousUseErrors);

            return $errors;
        }

        if (!file_exists($this->xsdPath)) {
            libxml_use_internal_errors($previousUseErrors);

            return [
                $this->xsdError(
                    sprintf('Fisierul XSD nu a fost gasit: %s', $this->xsdPath),
                ),
            ];
        }

        libxml_clear_errors();
        $valid = $dom->schemaValidate($this->xsdPath);

        if (!$valid) {
            foreach (libxml_get_errors() as $libxmlError) {
                if ($libxmlError->level === LIBXML_ERR_WARNING) {
                    continue;
                }
                $errors[] = $this->xsdError(
                    sprintf(
                        'Eroare XSD (linia %d): %s',
                        $libxmlError->line,
                        trim($libxmlError->message),
                    ),
                );
            }
        }

        libxml_clear_errors();
        libxml_use_internal_errors($previousUseErrors);

        return $errors;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Build a business-rule error entry.
     *
     * @return array{rule: string, message: string, severity: string}
     */
    private function error(string $rule, string $message): array
    {
        return [
            'rule'     => $rule,
            'message'  => $message,
            'severity' => 'fatal',
        ];
    }

    /**
     * Build an XSD validation error entry.
     *
     * @return array{rule: string, message: string, severity: string}
     */
    private function xsdError(string $message): array
    {
        return [
            'rule'     => 'XSD',
            'message'  => $message,
            'severity' => 'fatal',
        ];
    }

    /**
     * Validate a Romanian fiscal identification number (CUI/CIF or CNP).
     *
     * Accepted formats (after stripping a leading "RO" VAT prefix if present):
     *   - 2 to 10 digits  → CUI / CIF
     *   - exactly 13 digits starting with 1-9 → CNP
     *
     * Matches the Schematron TIN-REGEX: ^(([1-9][0-9]{12})|([1-9][0-9]{1,9}))$
     */
    private function isValidTin(string $value): bool
    {
        // Strip optional RO VAT prefix (e.g. "RO12345678" → "12345678")
        $normalized = preg_replace('/^RO/i', '', trim($value));

        if ($normalized === null || $normalized === '') {
            return false;
        }

        // CNP: exactly 13 digits, first digit 1-9
        if (preg_match('/^[1-9][0-9]{12}$/', $normalized)) {
            return true;
        }

        // CUI/CIF: 2-10 digits, first digit 1-9
        if (preg_match('/^[1-9][0-9]{1,9}$/', $normalized)) {
            return true;
        }

        return false;
    }

    /**
     * Validate the UIT checksum algorithm (BR-019).
     *
     * UIT format: 16 characters total.
     *   - Chars 1-14: from the set [0-9ACDEFHJKLMNPQRTUVWXY]
     *   - Chars 15-16: the last two digits of the sum of ASCII values of chars 1-14.
     *
     * Returns null on success or an error message string on failure.
     */
    private function validateUit(string $uit): ?string
    {
        if (strlen($uit) !== 16) {
            return sprintf(
                'UIT-ul "%s" trebuie sa aiba exact 16 caractere (lungime curenta: %d).',
                $uit,
                strlen($uit),
            );
        }

        $prefix = substr($uit, 0, 14);
        $checkDigits = substr($uit, 14, 2);

        // Validate character set of the first 14 positions
        if (!preg_match('/^[0-9ACDEFHJKLMNPQRTUVWXY]{14}$/', $prefix)) {
            return sprintf(
                'UIT-ul "%s" contine caractere invalide in primele 14 pozitii. '
                . 'Sunt acceptate: 0-9, A, C, D, E, F, H, J, K, L, M, N, P, Q, R, T, U, V, W, X, Y.',
                $uit,
            );
        }

        // Validate that the suffix is exactly two decimal digits
        if (!preg_match('/^\d{2}$/', $checkDigits)) {
            return sprintf(
                'UIT-ul "%s": ultimele 2 caractere TREBUIE sa fie cifre zecimale (valoare: "%s").',
                $uit,
                $checkDigits,
            );
        }

        // Compute ASCII sum of first 14 characters
        $asciiSum = 0;
        for ($i = 0; $i < 14; $i++) {
            $asciiSum += ord($prefix[$i]);
        }

        // The check value is the last two digits of the sum (as a zero-padded string)
        $expectedCheck = substr((string) $asciiSum, -2);
        // Zero-pad to 2 chars if the sum is < 10 (unlikely but safe)
        $expectedCheck = str_pad($expectedCheck, 2, '0', STR_PAD_LEFT);

        if ($checkDigits !== $expectedCheck) {
            return sprintf(
                'UIT-ul "%s" are cifra de control incorecta. '
                . 'Suma ASCII a primelor 14 caractere este %d; '
                . 'ultimele 2 cifre asteptate: "%s", primite: "%s".',
                $uit,
                $asciiSum,
                $expectedCheck,
                $checkDigits,
            );
        }

        return null;
    }

    /**
     * Validate that a numeric string conforms to the NUM12_2 pattern:
     * up to 12 integer digits and up to 2 decimal digits (no leading zeros).
     *
     * Matches the Schematron NUM12_2-REGEX: ^[0-9]{0,12}(\.[0-9]{0,2})?$
     */
    private function isValidNum12_2(string $value): bool
    {
        // Normalize trailing zeros from Doctrine decimal storage (e.g. "100.0000" -> "100")
        $value = rtrim(rtrim($value, '0'), '.');

        return (bool) preg_match('/^[0-9]{0,12}(\.[0-9]{0,2})?$/', $value);
    }
}
