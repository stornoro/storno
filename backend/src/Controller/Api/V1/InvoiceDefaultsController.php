<?php

namespace App\Controller\Api\V1;

use App\Manager\CompanyManager;
use App\Repository\ClientRepository;
use App\Repository\VatRateRepository;
use App\Security\OrganizationContext;
use App\Service\EuVatRateService;
use App\Service\ExchangeRateService;
use App\Service\ReverseChargeHelper;
use Symfony\Component\Uid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/invoice-defaults')]
class InvoiceDefaultsController extends AbstractController
{
    private const EU_COUNTRY_CODES = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'ES',
        'FI', 'FR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT',
        'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK',
    ];

    public function __construct(
        private readonly OrganizationContext $organizationContext,
        private readonly ExchangeRateService $exchangeRateService,
        private readonly VatRateRepository $vatRateRepository,
        private readonly CompanyManager $companyManager,
        private readonly ClientRepository $clientRepository,
        private readonly EuVatRateService $euVatRateService,
    ) {}

    /**
     * Returns defaults and configuration for invoice creation.
     * Includes: VAT rates, currencies, payment terms, exchange rates.
     */
    #[Route('', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);

        $defaultCurrency = $company?->getDefaultCurrency() ?? 'RON';
        $isVatPayer = $company?->isVatPayer() ?? true;

        // Load VAT rates from DB
        $vatRates = [];
        if ($company) {
            $dbRates = $this->vatRateRepository->findActiveByCompany($company);

            // Auto-seed if legacy company has no rates
            if (empty($dbRates)) {
                $this->companyManager->ensureDefaultVatRates($company);
                $dbRates = $this->vatRateRepository->findActiveByCompany($company);
            }

            foreach ($dbRates as $vr) {
                $isDefault = $vr->isDefault();
                // If not VAT payer, override default to 0%
                if (!$isVatPayer) {
                    $isDefault = $vr->getRate() === '0.00';
                }
                $vatRates[] = [
                    'rate' => rtrim(rtrim($vr->getRate(), '0'), '.'),
                    'label' => $vr->getLabel(),
                    'categoryCode' => $vr->getCategoryCode(),
                    'default' => $isDefault,
                ];
            }
        }

        // Check for reverse charge / OSS if clientId is provided
        $reverseCharge = false;
        $ossApplicable = false;
        $exportApplicable = false;
        $ossVatRate = null;
        $ossVatRates = [];
        $clientId = $request->query->get('clientId');
        if ($clientId && $company) {
            try {
                $client = $this->clientRepository->find(Uuid::fromString($clientId));
                if ($client && ReverseChargeHelper::shouldApplyReverseCharge($client, $company)) {
                    $reverseCharge = true;
                    // Override default VAT rate to reverse charge
                    $vatRates = array_map(function ($vr) {
                        $vr['default'] = false;
                        return $vr;
                    }, $vatRates);
                    // Add reverse charge rate as default
                    array_unshift($vatRates, [
                        'rate' => '0',
                        'label' => 'Taxare inversă / Reverse Charge',
                        'categoryCode' => 'AE',
                        'default' => true,
                    ]);
                } elseif (
                    $client
                    && $client->getCountry() !== 'RO'
                    && !in_array($client->getCountry(), self::EU_COUNTRY_CODES, true)
                ) {
                    // Non-EU client: flag set, default override applied after fallback block
                    $exportApplicable = true;
                } elseif (
                    $client
                    && $company->isOss()
                    && $client->getCountry() !== 'RO'
                    && in_array($client->getCountry(), self::EU_COUNTRY_CODES, true)
                    && $client->isViesValid() !== true
                ) {
                    // OSS: destination country's VAT rates
                    $allRates = $this->euVatRateService->getAllRates($client->getCountry());
                    if ($allRates !== null && isset($allRates['standard'])) {
                        $ossApplicable = true;
                        $cc = $client->getCountry();
                        // Build the default (standard) rate for backwards compat
                        $stdStr = rtrim(rtrim(number_format($allRates['standard'], 2, '.', ''), '0'), '.');
                        $ossVatRate = [
                            'rate' => $stdStr,
                            'label' => sprintf('TVA %s%% (%s — OSS)', $stdStr, $cc),
                            'categoryCode' => 'S',
                        ];
                        // Build all available rates for the destination country
                        $rateTypeLabels = [
                            'standard' => 'Standard',
                            'reduced' => 'Redus',
                            'reduced1' => 'Redus',
                            'reduced2' => 'Redus',
                            'super_reduced' => 'Super-redus',
                            'parking' => 'Parking',
                        ];
                        foreach ($allRates as $type => $rateVal) {
                            $rStr = rtrim(rtrim(number_format((float) $rateVal, 2, '.', ''), '0'), '.');
                            $typeLabel = $rateTypeLabels[$type] ?? ucfirst($type);
                            $ossVatRates[] = [
                                'rate' => $rStr,
                                'label' => sprintf('TVA %s%% %s (%s — OSS)', $rStr, $typeLabel, $cc),
                                'categoryCode' => 'S',
                                'default' => $type === 'standard',
                            ];
                        }
                        // Sort: standard first, then by rate descending
                        usort($ossVatRates, function ($a, $b) {
                            if ($a['default'] !== $b['default']) return $b['default'] <=> $a['default'];
                            return (float) $b['rate'] <=> (float) $a['rate'];
                        });
                    }
                }
            } catch (\Throwable) {
                // Invalid UUID or client not found — ignore
            }
        }

        // Fallback if no company
        if (empty($vatRates)) {
            $vatRates = [
                ['rate' => '21', 'label' => 'Standard', 'categoryCode' => 'S', 'default' => true],
                ['rate' => '9', 'label' => 'Redus 9%', 'categoryCode' => 'S', 'default' => false],
                ['rate' => '5', 'label' => 'Redus 5%', 'categoryCode' => 'S', 'default' => false],
                ['rate' => '0', 'label' => 'Scutit', 'categoryCode' => 'Z', 'default' => false],
            ];
        }

        // Non-EU export: default to 0% rate (applied after fallback to guarantee $vatRates is populated)
        if ($exportApplicable) {
            $vatRates = array_map(function ($vr) {
                $vr['default'] = $vr['rate'] === '0';
                return $vr;
            }, $vatRates);
        }

        $currencies = ['RON', 'EUR', 'USD', 'GBP', 'CHF', 'HUF', 'CZK', 'PLN', 'BGN', 'SEK', 'NOK', 'DKK'];

        // Document series types
        $documentSeriesTypes = [
            ['value' => 'invoice', 'label' => 'Factura'],
            ['value' => 'proforma', 'label' => 'Proforma'],
            ['value' => 'credit_note', 'label' => 'Nota de credit'],
            ['value' => 'delivery_note', 'label' => 'Aviz de insotire'],
        ];

        // Payment methods
        $paymentMethods = [
            ['value' => 'bank_transfer', 'label' => 'Transfer bancar'],
            ['value' => 'cash', 'label' => 'Numerar'],
            ['value' => 'card', 'label' => 'Card'],
            ['value' => 'cheque', 'label' => 'Cec / Bilet la ordin'],
            ['value' => 'other', 'label' => 'Altele'],
        ];

        // Units of measure — must match UblXmlGenerator::mapUnitOfMeasure
        $unitsOfMeasure = [
            ['value' => 'buc', 'label' => 'buc (Bucata)', 'code' => 'H87'],
            ['value' => 'kg', 'label' => 'kg (Kilogram)', 'code' => 'KGM'],
            ['value' => 'l', 'label' => 'l (Litru)', 'code' => 'LTR'],
            ['value' => 'm', 'label' => 'm (Metru)', 'code' => 'MTR'],
            ['value' => 'ora', 'label' => 'ora (Ora)', 'code' => 'HUR'],
            ['value' => 'zi', 'label' => 'zi (Zi)', 'code' => 'DAY'],
            ['value' => 'luna', 'label' => 'luna (Luna)', 'code' => 'MON'],
            ['value' => 'set', 'label' => 'set (Set)', 'code' => 'SET'],
            ['value' => 'pachet', 'label' => 'pachet (Pachet)', 'code' => 'PK'],
        ];

        // Countries — full ISO 3166-1 alpha-2 list
        $countries = [
            ['code' => 'US', 'label' => 'United States'],
            ['code' => 'RO', 'label' => 'Romania'],
            ['code' => 'GB', 'label' => 'United Kingdom'],
            ['code' => 'DE', 'label' => 'Germany'],
            ['code' => 'FR', 'label' => 'France'],
            ['code' => 'IT', 'label' => 'Italy'],
            ['code' => 'ES', 'label' => 'Spain'],
            ['code' => 'NL', 'label' => 'Netherlands'],
            ['code' => 'CA', 'label' => 'Canada'],
            ['code' => 'AU', 'label' => 'Australia'],
            ['code' => 'AD', 'label' => 'Andorra'], ['code' => 'AE', 'label' => 'United Arab Emirates'],
            ['code' => 'AF', 'label' => 'Afghanistan'], ['code' => 'AG', 'label' => 'Antigua and Barbuda'],
            ['code' => 'AI', 'label' => 'Anguilla'], ['code' => 'AL', 'label' => 'Albania'],
            ['code' => 'AM', 'label' => 'Armenia'], ['code' => 'AO', 'label' => 'Angola'],
            ['code' => 'AQ', 'label' => 'Antarctica'], ['code' => 'AR', 'label' => 'Argentina'],
            ['code' => 'AS', 'label' => 'American Samoa'], ['code' => 'AT', 'label' => 'Austria'],
            ['code' => 'AW', 'label' => 'Aruba'], ['code' => 'AZ', 'label' => 'Azerbaijan'],
            ['code' => 'BA', 'label' => 'Bosnia and Herzegovina'], ['code' => 'BB', 'label' => 'Barbados'],
            ['code' => 'BD', 'label' => 'Bangladesh'], ['code' => 'BE', 'label' => 'Belgium'],
            ['code' => 'BF', 'label' => 'Burkina Faso'], ['code' => 'BG', 'label' => 'Bulgaria'],
            ['code' => 'BH', 'label' => 'Bahrain'], ['code' => 'BI', 'label' => 'Burundi'],
            ['code' => 'BJ', 'label' => 'Benin'], ['code' => 'BL', 'label' => 'Saint Barthelemy'],
            ['code' => 'BM', 'label' => 'Bermuda'], ['code' => 'BN', 'label' => 'Brunei'],
            ['code' => 'BO', 'label' => 'Bolivia'], ['code' => 'BR', 'label' => 'Brazil'],
            ['code' => 'BS', 'label' => 'Bahamas'], ['code' => 'BT', 'label' => 'Bhutan'],
            ['code' => 'BW', 'label' => 'Botswana'], ['code' => 'BY', 'label' => 'Belarus'],
            ['code' => 'BZ', 'label' => 'Belize'], ['code' => 'CD', 'label' => 'Congo (DRC)'],
            ['code' => 'CF', 'label' => 'Central African Republic'], ['code' => 'CG', 'label' => 'Congo'],
            ['code' => 'CH', 'label' => 'Switzerland'], ['code' => 'CI', 'label' => 'Ivory Coast'],
            ['code' => 'CK', 'label' => 'Cook Islands'], ['code' => 'CL', 'label' => 'Chile'],
            ['code' => 'CM', 'label' => 'Cameroon'], ['code' => 'CN', 'label' => 'China'],
            ['code' => 'CO', 'label' => 'Colombia'], ['code' => 'CR', 'label' => 'Costa Rica'],
            ['code' => 'CU', 'label' => 'Cuba'], ['code' => 'CV', 'label' => 'Cape Verde'],
            ['code' => 'CW', 'label' => 'Curacao'], ['code' => 'CY', 'label' => 'Cyprus'],
            ['code' => 'CZ', 'label' => 'Czech Republic'], ['code' => 'DJ', 'label' => 'Djibouti'],
            ['code' => 'DK', 'label' => 'Denmark'], ['code' => 'DM', 'label' => 'Dominica'],
            ['code' => 'DO', 'label' => 'Dominican Republic'], ['code' => 'DZ', 'label' => 'Algeria'],
            ['code' => 'EC', 'label' => 'Ecuador'], ['code' => 'EE', 'label' => 'Estonia'],
            ['code' => 'EG', 'label' => 'Egypt'], ['code' => 'ER', 'label' => 'Eritrea'],
            ['code' => 'ET', 'label' => 'Ethiopia'], ['code' => 'FI', 'label' => 'Finland'],
            ['code' => 'FJ', 'label' => 'Fiji'], ['code' => 'FK', 'label' => 'Falkland Islands'],
            ['code' => 'FM', 'label' => 'Micronesia'], ['code' => 'FO', 'label' => 'Faroe Islands'],
            ['code' => 'GA', 'label' => 'Gabon'], ['code' => 'GD', 'label' => 'Grenada'],
            ['code' => 'GE', 'label' => 'Georgia'], ['code' => 'GH', 'label' => 'Ghana'],
            ['code' => 'GI', 'label' => 'Gibraltar'], ['code' => 'GL', 'label' => 'Greenland'],
            ['code' => 'GM', 'label' => 'Gambia'], ['code' => 'GN', 'label' => 'Guinea'],
            ['code' => 'GQ', 'label' => 'Equatorial Guinea'], ['code' => 'GR', 'label' => 'Greece'],
            ['code' => 'GT', 'label' => 'Guatemala'], ['code' => 'GU', 'label' => 'Guam'],
            ['code' => 'GW', 'label' => 'Guinea-Bissau'], ['code' => 'GY', 'label' => 'Guyana'],
            ['code' => 'HK', 'label' => 'Hong Kong'], ['code' => 'HN', 'label' => 'Honduras'],
            ['code' => 'HR', 'label' => 'Croatia'], ['code' => 'HT', 'label' => 'Haiti'],
            ['code' => 'HU', 'label' => 'Hungary'], ['code' => 'ID', 'label' => 'Indonesia'],
            ['code' => 'IE', 'label' => 'Ireland'], ['code' => 'IL', 'label' => 'Israel'],
            ['code' => 'IM', 'label' => 'Isle of Man'], ['code' => 'IN', 'label' => 'India'],
            ['code' => 'IQ', 'label' => 'Iraq'], ['code' => 'IR', 'label' => 'Iran'],
            ['code' => 'IS', 'label' => 'Iceland'], ['code' => 'JM', 'label' => 'Jamaica'],
            ['code' => 'JO', 'label' => 'Jordan'], ['code' => 'JP', 'label' => 'Japan'],
            ['code' => 'KE', 'label' => 'Kenya'], ['code' => 'KG', 'label' => 'Kyrgyzstan'],
            ['code' => 'KH', 'label' => 'Cambodia'], ['code' => 'KI', 'label' => 'Kiribati'],
            ['code' => 'KM', 'label' => 'Comoros'], ['code' => 'KN', 'label' => 'Saint Kitts and Nevis'],
            ['code' => 'KP', 'label' => 'North Korea'], ['code' => 'KR', 'label' => 'South Korea'],
            ['code' => 'KW', 'label' => 'Kuwait'], ['code' => 'KY', 'label' => 'Cayman Islands'],
            ['code' => 'KZ', 'label' => 'Kazakhstan'], ['code' => 'LA', 'label' => 'Laos'],
            ['code' => 'LB', 'label' => 'Lebanon'], ['code' => 'LC', 'label' => 'Saint Lucia'],
            ['code' => 'LI', 'label' => 'Liechtenstein'], ['code' => 'LK', 'label' => 'Sri Lanka'],
            ['code' => 'LR', 'label' => 'Liberia'], ['code' => 'LS', 'label' => 'Lesotho'],
            ['code' => 'LT', 'label' => 'Lithuania'], ['code' => 'LU', 'label' => 'Luxembourg'],
            ['code' => 'LV', 'label' => 'Latvia'], ['code' => 'LY', 'label' => 'Libya'],
            ['code' => 'MA', 'label' => 'Morocco'], ['code' => 'MC', 'label' => 'Monaco'],
            ['code' => 'MD', 'label' => 'Moldova'], ['code' => 'ME', 'label' => 'Montenegro'],
            ['code' => 'MG', 'label' => 'Madagascar'], ['code' => 'MH', 'label' => 'Marshall Islands'],
            ['code' => 'MK', 'label' => 'North Macedonia'], ['code' => 'ML', 'label' => 'Mali'],
            ['code' => 'MM', 'label' => 'Myanmar'], ['code' => 'MN', 'label' => 'Mongolia'],
            ['code' => 'MO', 'label' => 'Macau'], ['code' => 'MR', 'label' => 'Mauritania'],
            ['code' => 'MT', 'label' => 'Malta'], ['code' => 'MU', 'label' => 'Mauritius'],
            ['code' => 'MV', 'label' => 'Maldives'], ['code' => 'MW', 'label' => 'Malawi'],
            ['code' => 'MX', 'label' => 'Mexico'], ['code' => 'MY', 'label' => 'Malaysia'],
            ['code' => 'MZ', 'label' => 'Mozambique'], ['code' => 'NA', 'label' => 'Namibia'],
            ['code' => 'NC', 'label' => 'New Caledonia'], ['code' => 'NE', 'label' => 'Niger'],
            ['code' => 'NG', 'label' => 'Nigeria'], ['code' => 'NI', 'label' => 'Nicaragua'],
            ['code' => 'NO', 'label' => 'Norway'], ['code' => 'NP', 'label' => 'Nepal'],
            ['code' => 'NR', 'label' => 'Nauru'], ['code' => 'NU', 'label' => 'Niue'],
            ['code' => 'NZ', 'label' => 'New Zealand'], ['code' => 'OM', 'label' => 'Oman'],
            ['code' => 'PA', 'label' => 'Panama'], ['code' => 'PE', 'label' => 'Peru'],
            ['code' => 'PF', 'label' => 'French Polynesia'], ['code' => 'PG', 'label' => 'Papua New Guinea'],
            ['code' => 'PH', 'label' => 'Philippines'], ['code' => 'PK', 'label' => 'Pakistan'],
            ['code' => 'PL', 'label' => 'Poland'], ['code' => 'PR', 'label' => 'Puerto Rico'],
            ['code' => 'PS', 'label' => 'Palestine'], ['code' => 'PT', 'label' => 'Portugal'],
            ['code' => 'PW', 'label' => 'Palau'], ['code' => 'PY', 'label' => 'Paraguay'],
            ['code' => 'QA', 'label' => 'Qatar'], ['code' => 'RS', 'label' => 'Serbia'],
            ['code' => 'RU', 'label' => 'Russia'], ['code' => 'RW', 'label' => 'Rwanda'],
            ['code' => 'SA', 'label' => 'Saudi Arabia'], ['code' => 'SB', 'label' => 'Solomon Islands'],
            ['code' => 'SC', 'label' => 'Seychelles'], ['code' => 'SD', 'label' => 'Sudan'],
            ['code' => 'SE', 'label' => 'Sweden'], ['code' => 'SG', 'label' => 'Singapore'],
            ['code' => 'SI', 'label' => 'Slovenia'], ['code' => 'SK', 'label' => 'Slovakia'],
            ['code' => 'SL', 'label' => 'Sierra Leone'], ['code' => 'SM', 'label' => 'San Marino'],
            ['code' => 'SN', 'label' => 'Senegal'], ['code' => 'SO', 'label' => 'Somalia'],
            ['code' => 'SR', 'label' => 'Suriname'], ['code' => 'SS', 'label' => 'South Sudan'],
            ['code' => 'ST', 'label' => 'Sao Tome and Principe'], ['code' => 'SV', 'label' => 'El Salvador'],
            ['code' => 'SY', 'label' => 'Syria'], ['code' => 'SZ', 'label' => 'Eswatini'],
            ['code' => 'TD', 'label' => 'Chad'], ['code' => 'TG', 'label' => 'Togo'],
            ['code' => 'TH', 'label' => 'Thailand'], ['code' => 'TJ', 'label' => 'Tajikistan'],
            ['code' => 'TL', 'label' => 'Timor-Leste'], ['code' => 'TM', 'label' => 'Turkmenistan'],
            ['code' => 'TN', 'label' => 'Tunisia'], ['code' => 'TO', 'label' => 'Tonga'],
            ['code' => 'TR', 'label' => 'Turkey'], ['code' => 'TT', 'label' => 'Trinidad and Tobago'],
            ['code' => 'TV', 'label' => 'Tuvalu'], ['code' => 'TW', 'label' => 'Taiwan'],
            ['code' => 'TZ', 'label' => 'Tanzania'], ['code' => 'UA', 'label' => 'Ukraine'],
            ['code' => 'UG', 'label' => 'Uganda'], ['code' => 'UY', 'label' => 'Uruguay'],
            ['code' => 'UZ', 'label' => 'Uzbekistan'], ['code' => 'VA', 'label' => 'Vatican City'],
            ['code' => 'VC', 'label' => 'Saint Vincent and the Grenadines'],
            ['code' => 'VE', 'label' => 'Venezuela'], ['code' => 'VN', 'label' => 'Vietnam'],
            ['code' => 'VU', 'label' => 'Vanuatu'], ['code' => 'WS', 'label' => 'Samoa'],
            ['code' => 'XK', 'label' => 'Kosovo'], ['code' => 'YE', 'label' => 'Yemen'],
            ['code' => 'ZA', 'label' => 'South Africa'], ['code' => 'ZM', 'label' => 'Zambia'],
            ['code' => 'ZW', 'label' => 'Zimbabwe'],
        ];

        // Romanian counties — UBL CountrySubentity codes (source: github.com/romania/localitati)
        $counties = [
            ['code' => 'AB', 'label' => 'Alba'],
            ['code' => 'AG', 'label' => 'Arges'],
            ['code' => 'AR', 'label' => 'Arad'],
            ['code' => 'B', 'label' => 'Bucuresti'],
            ['code' => 'BC', 'label' => 'Bacau'],
            ['code' => 'BH', 'label' => 'Bihor'],
            ['code' => 'BN', 'label' => 'Bistrita-Nasaud'],
            ['code' => 'BR', 'label' => 'Braila'],
            ['code' => 'BT', 'label' => 'Botosani'],
            ['code' => 'BV', 'label' => 'Brasov'],
            ['code' => 'BZ', 'label' => 'Buzau'],
            ['code' => 'CJ', 'label' => 'Cluj'],
            ['code' => 'CL', 'label' => 'Calarasi'],
            ['code' => 'CS', 'label' => 'Caras-Severin'],
            ['code' => 'CT', 'label' => 'Constanta'],
            ['code' => 'CV', 'label' => 'Covasna'],
            ['code' => 'DB', 'label' => 'Dambovita'],
            ['code' => 'DJ', 'label' => 'Dolj'],
            ['code' => 'GJ', 'label' => 'Gorj'],
            ['code' => 'GL', 'label' => 'Galati'],
            ['code' => 'GR', 'label' => 'Giurgiu'],
            ['code' => 'HD', 'label' => 'Hunedoara'],
            ['code' => 'HR', 'label' => 'Harghita'],
            ['code' => 'IF', 'label' => 'Ilfov'],
            ['code' => 'IL', 'label' => 'Ialomita'],
            ['code' => 'IS', 'label' => 'Iasi'],
            ['code' => 'MH', 'label' => 'Mehedinti'],
            ['code' => 'MM', 'label' => 'Maramures'],
            ['code' => 'MS', 'label' => 'Mures'],
            ['code' => 'NT', 'label' => 'Neamt'],
            ['code' => 'OT', 'label' => 'Olt'],
            ['code' => 'PH', 'label' => 'Prahova'],
            ['code' => 'SB', 'label' => 'Sibiu'],
            ['code' => 'SJ', 'label' => 'Salaj'],
            ['code' => 'SM', 'label' => 'Satu Mare'],
            ['code' => 'SV', 'label' => 'Suceava'],
            ['code' => 'TL', 'label' => 'Tulcea'],
            ['code' => 'TM', 'label' => 'Timis'],
            ['code' => 'TR', 'label' => 'Teleorman'],
            ['code' => 'VL', 'label' => 'Valcea'],
            ['code' => 'VN', 'label' => 'Vrancea'],
            ['code' => 'VS', 'label' => 'Vaslui'],
        ];

        // Try to get exchange rates
        $exchangeRates = [];
        try {
            $rateData = $this->exchangeRateService->getRates();
            foreach ($rateData['rates'] as $currency => $info) {
                if (in_array($currency, $currencies, true)) {
                    $exchangeRates[$currency] = round($info['value'] / $info['multiplier'], 4);
                }
            }
        } catch (\Throwable) {
            // Exchange rates unavailable — non-fatal
        }

        return $this->json([
            'vatRates' => $vatRates,
            'currencies' => $currencies,
            'defaultCurrency' => $defaultCurrency,
            'defaultPaymentTermDays' => 30,
            'defaultUnitOfMeasure' => 'buc',
            'unitsOfMeasure' => $unitsOfMeasure,
            'exchangeRates' => $exchangeRates,
            'exchangeRateDate' => $rateData['date'] ?? null,
            'documentSeriesTypes' => $documentSeriesTypes,
            'paymentMethods' => $paymentMethods,
            'isVatPayer' => $isVatPayer,
            'reverseCharge' => $reverseCharge,
            'exportApplicable' => $exportApplicable,
            'ossApplicable' => $ossApplicable,
            'ossVatRate' => $ossVatRate,
            'ossVatRates' => $ossVatRates,
            'countries' => $countries,
            'counties' => $counties,
        ]);
    }
}
