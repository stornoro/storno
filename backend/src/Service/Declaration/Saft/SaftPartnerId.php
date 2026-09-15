<?php

declare(strict_types=1);

namespace App\Service\Declaration\Saft;

use App\Service\Declaration\D394\D394Rules;

/**
 * Partner identifiers of the SAF-T file (CustomerID, SupplierID and the partner's
 * RegistrationNumber all share one format): a two-digit type followed by the identifier.
 *
 *   00 + CUI            Romanian company (digits only, no "RO", checksum valid)
 *   01 + country + VAT  EU company registered for VAT (the VAT number without its prefix)
 *   02 + country + code non-EU company identified by a tax code
 *   03 + CNP / NIF      natural person with a personal number (13 digits, not starting with 0)
 *   04 + client code    natural person who does not give a CNP (a code assigned by the company)
 *   05 / 06 + country + code   EU / non-EU partner without a VAT number, by a company-assigned code
 *   08 + 13 zeros       point-of-sale customers not identified at all (never used for invoices)
 *
 * ANAF's validator checks the type, the country (ISO 3166-1 alpha-2) and, for 00 / 03, the
 * digits. A partner Storno cannot identify is left out of the file and reported in `warnings`.
 */
final class SaftPartnerId
{
    public const UNIDENTIFIED_POS_CUSTOMER = '080000000000000';

    /**
     * @param string|null $fallbackCode a company-assigned code (client code / id) for partners without a fiscal identifier
     * @return array{id: ?string, issue: ?string} the identifier, or the reason (NO_ID, INVALID_CUI, INVALID_CNP)
     */
    public static function build(?string $country, ?string $cui, ?string $cnp, ?string $vatCode, bool $individual, ?string $fallbackCode): array
    {
        $country = strtoupper(trim((string) $country)) ?: 'RO';
        $cuiDigits = preg_replace('/\D/', '', (string) $cui) ?? '';
        $cnpDigits = preg_replace('/\D/', '', (string) $cnp) ?? '';
        $code = self::sanitize($fallbackCode);

        if ($country === 'RO') {
            if ($cuiDigits !== '' && strlen($cuiDigits) === 13) {
                // a CNP typed in the CUI field
                $cnpDigits = $cuiDigits;
                $cuiDigits = '';
            }
            if ($cuiDigits !== '') {
                if (strlen($cuiDigits) > 10 || !D394Rules::isValidCui($cuiDigits)) {
                    return ['id' => null, 'issue' => 'INVALID_CUI'];
                }

                return ['id' => '00' . $cuiDigits, 'issue' => null];
            }
            if ($cnpDigits !== '') {
                if (strlen($cnpDigits) !== 13 || $cnpDigits[0] === '0' || !D394Rules::isValidCnp($cnpDigits)) {
                    return ['id' => null, 'issue' => 'INVALID_CNP'];
                }

                return ['id' => '03' . $cnpDigits, 'issue' => null];
            }
            if ($individual && $code !== '') {
                return ['id' => mb_substr('04' . $code, 0, 35), 'issue' => null];
            }

            return ['id' => null, 'issue' => 'NO_ID'];
        }

        $vat = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $vatCode) ?? '');
        if ($vat !== '' && str_starts_with($vat, $country)) {
            $vat = substr($vat, strlen($country));
        }
        if ($vat === '' && $cuiDigits !== '') {
            $vat = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $cui) ?? '');
            if (str_starts_with($vat, $country)) {
                $vat = substr($vat, strlen($country));
            }
        }
        $eu = D394Rules::isEu($country);
        if ($vat !== '') {
            return ['id' => mb_substr(($eu ? '01' : '02') . $country . $vat, 0, 35), 'issue' => null];
        }
        if ($code !== '') {
            return ['id' => mb_substr(($eu ? '05' : '06') . $country . $code, 0, 35), 'issue' => null];
        }

        return ['id' => null, 'issue' => 'NO_ID'];
    }

    /** Letters and digits only: the validator refuses special characters in company-assigned codes. */
    public static function sanitize(?string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $code) ?? '');
    }
}
