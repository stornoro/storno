<?php

namespace App\Twig;

use App\Util\AddressNormalizer;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

class PdfLabelExtension extends AbstractExtension
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {}

    private const COUNTRY_NAMES_RO = [
        'RO' => 'România', 'DE' => 'Germania', 'FR' => 'Franța', 'IT' => 'Italia',
        'ES' => 'Spania', 'PT' => 'Portugalia', 'GB' => 'Regatul Unit', 'US' => 'Statele Unite',
        'AT' => 'Austria', 'BE' => 'Belgia', 'BG' => 'Bulgaria', 'HR' => 'Croația',
        'CY' => 'Cipru', 'CZ' => 'Cehia', 'DK' => 'Danemarca', 'EE' => 'Estonia',
        'FI' => 'Finlanda', 'GR' => 'Grecia', 'HU' => 'Ungaria', 'IE' => 'Irlanda',
        'LV' => 'Letonia', 'LT' => 'Lituania', 'LU' => 'Luxemburg', 'MT' => 'Malta',
        'NL' => 'Olanda', 'PL' => 'Polonia', 'SK' => 'Slovacia', 'SI' => 'Slovenia',
        'SE' => 'Suedia', 'CH' => 'Elveția', 'NO' => 'Norvegia', 'MD' => 'Moldova',
        'UA' => 'Ucraina', 'RS' => 'Serbia', 'TR' => 'Turcia', 'CA' => 'Canada',
        'AU' => 'Australia', 'JP' => 'Japonia', 'CN' => 'China', 'IN' => 'India',
        'BR' => 'Brazilia', 'MX' => 'Mexic', 'AR' => 'Argentina', 'CL' => 'Chile',
    ];

    private const COUNTRY_NAMES_EN = [
        'RO' => 'Romania', 'DE' => 'Germany', 'FR' => 'France', 'IT' => 'Italy',
        'ES' => 'Spain', 'PT' => 'Portugal', 'GB' => 'United Kingdom', 'US' => 'United States',
        'AT' => 'Austria', 'BE' => 'Belgium', 'BG' => 'Bulgaria', 'HR' => 'Croatia',
        'CY' => 'Cyprus', 'CZ' => 'Czech Republic', 'DK' => 'Denmark', 'EE' => 'Estonia',
        'FI' => 'Finland', 'GR' => 'Greece', 'HU' => 'Hungary', 'IE' => 'Ireland',
        'LV' => 'Latvia', 'LT' => 'Lithuania', 'LU' => 'Luxembourg', 'MT' => 'Malta',
        'NL' => 'Netherlands', 'PL' => 'Poland', 'SK' => 'Slovakia', 'SI' => 'Slovenia',
        'SE' => 'Sweden', 'CH' => 'Switzerland', 'NO' => 'Norway', 'MD' => 'Moldova',
        'UA' => 'Ukraine', 'RS' => 'Serbia', 'TR' => 'Turkey', 'CA' => 'Canada',
        'AU' => 'Australia', 'JP' => 'Japan', 'CN' => 'China', 'IN' => 'India',
        'BR' => 'Brazil', 'MX' => 'Mexico', 'AR' => 'Argentina', 'CL' => 'Chile',
    ];

    private const COUNTRY_NAMES_FR = [
        'RO' => 'Roumanie', 'DE' => 'Allemagne', 'FR' => 'France', 'IT' => 'Italie',
        'ES' => 'Espagne', 'PT' => 'Portugal', 'GB' => 'Royaume-Uni', 'US' => 'États-Unis',
        'AT' => 'Autriche', 'BE' => 'Belgique', 'BG' => 'Bulgarie', 'HR' => 'Croatie',
        'CY' => 'Chypre', 'CZ' => 'Tchéquie', 'DK' => 'Danemark', 'EE' => 'Estonie',
        'FI' => 'Finlande', 'GR' => 'Grèce', 'HU' => 'Hongrie', 'IE' => 'Irlande',
        'LV' => 'Lettonie', 'LT' => 'Lituanie', 'LU' => 'Luxembourg', 'MT' => 'Malte',
        'NL' => 'Pays-Bas', 'PL' => 'Pologne', 'SK' => 'Slovaquie', 'SI' => 'Slovénie',
        'SE' => 'Suède', 'CH' => 'Suisse', 'NO' => 'Norvège', 'MD' => 'Moldavie',
        'UA' => 'Ukraine', 'RS' => 'Serbie', 'TR' => 'Turquie', 'CA' => 'Canada',
        'AU' => 'Australie', 'JP' => 'Japon', 'CN' => 'Chine', 'IN' => 'Inde',
        'BR' => 'Brésil', 'MX' => 'Mexique', 'AR' => 'Argentine', 'CL' => 'Chili',
    ];

    private const COUNTRY_NAMES_DE = [
        'RO' => 'Rumänien', 'DE' => 'Deutschland', 'FR' => 'Frankreich', 'IT' => 'Italien',
        'ES' => 'Spanien', 'PT' => 'Portugal', 'GB' => 'Vereinigtes Königreich', 'US' => 'Vereinigte Staaten',
        'AT' => 'Österreich', 'BE' => 'Belgien', 'BG' => 'Bulgarien', 'HR' => 'Kroatien',
        'CY' => 'Zypern', 'CZ' => 'Tschechien', 'DK' => 'Dänemark', 'EE' => 'Estland',
        'FI' => 'Finnland', 'GR' => 'Griechenland', 'HU' => 'Ungarn', 'IE' => 'Irland',
        'LV' => 'Lettland', 'LT' => 'Litauen', 'LU' => 'Luxemburg', 'MT' => 'Malta',
        'NL' => 'Niederlande', 'PL' => 'Polen', 'SK' => 'Slowakei', 'SI' => 'Slowenien',
        'SE' => 'Schweden', 'CH' => 'Schweiz', 'NO' => 'Norwegen', 'MD' => 'Moldawien',
        'UA' => 'Ukraine', 'RS' => 'Serbien', 'TR' => 'Türkei', 'CA' => 'Kanada',
        'AU' => 'Australien', 'JP' => 'Japan', 'CN' => 'China', 'IN' => 'Indien',
        'BR' => 'Brasilien', 'MX' => 'Mexiko', 'AR' => 'Argentinien', 'CL' => 'Chile',
    ];

    public function getFunctions(): array
    {
        return [
            new TwigFunction('pdf_label', [$this, 'pdfLabel']),
            new TwigFunction('pdf_visible', [$this, 'pdfVisible']),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('county_name', [$this, 'countyName']),
            new TwigFilter('country_name', [$this, 'countryName']),
        ];
    }

    public function countyName(?string $code, string $locale = 'ro'): string
    {
        if ($code === null || $code === '') {
            return '';
        }
        return AddressNormalizer::countyToName($code, $locale);
    }

    public function countryName(?string $code, string $locale = 'ro'): string
    {
        if ($code === null || $code === '') {
            return '';
        }
        $upper = strtoupper(trim($code));
        $map = match ($locale) {
            'en' => self::COUNTRY_NAMES_EN,
            'fr' => self::COUNTRY_NAMES_FR,
            'de' => self::COUNTRY_NAMES_DE,
            default => self::COUNTRY_NAMES_RO,
        };
        return $map[$upper] ?? $code;
    }

    /**
     * Returns custom label text or falls back to translation.
     */
    public function pdfLabel(string $key, ?array $overrides, string $locale = 'ro', ?string $transKey = null): string
    {
        if ($overrides && isset($overrides[$key]['text']) && $overrides[$key]['text'] !== null && $overrides[$key]['text'] !== '') {
            return $overrides[$key]['text'];
        }

        return $this->translator->trans($transKey ?? $key, [], 'pdf', $locale);
    }

    /**
     * Returns whether a label/field should be visible.
     */
    public function pdfVisible(string $key, ?array $overrides): bool
    {
        if ($overrides && isset($overrides[$key]['visible'])) {
            return (bool) $overrides[$key]['visible'];
        }

        return true;
    }
}
