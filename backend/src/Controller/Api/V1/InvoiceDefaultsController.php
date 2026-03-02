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

        // Countries — ISO 3166-1 alpha-2 (UBL-compatible)
        $countries = [
            ['code' => 'RO', 'label' => 'Romania'],
            ['code' => 'AT', 'label' => 'Austria'],
            ['code' => 'BE', 'label' => 'Belgia'],
            ['code' => 'BG', 'label' => 'Bulgaria'],
            ['code' => 'CH', 'label' => 'Elvetia'],
            ['code' => 'CY', 'label' => 'Cipru'],
            ['code' => 'CZ', 'label' => 'Cehia'],
            ['code' => 'DE', 'label' => 'Germania'],
            ['code' => 'DK', 'label' => 'Danemarca'],
            ['code' => 'EE', 'label' => 'Estonia'],
            ['code' => 'EL', 'label' => 'Grecia'],
            ['code' => 'ES', 'label' => 'Spania'],
            ['code' => 'FI', 'label' => 'Finlanda'],
            ['code' => 'FR', 'label' => 'Franta'],
            ['code' => 'GB', 'label' => 'Marea Britanie'],
            ['code' => 'HR', 'label' => 'Croatia'],
            ['code' => 'HU', 'label' => 'Ungaria'],
            ['code' => 'IE', 'label' => 'Irlanda'],
            ['code' => 'IT', 'label' => 'Italia'],
            ['code' => 'LT', 'label' => 'Lituania'],
            ['code' => 'LU', 'label' => 'Luxemburg'],
            ['code' => 'LV', 'label' => 'Letonia'],
            ['code' => 'MD', 'label' => 'Moldova'],
            ['code' => 'MT', 'label' => 'Malta'],
            ['code' => 'NL', 'label' => 'Olanda'],
            ['code' => 'PL', 'label' => 'Polonia'],
            ['code' => 'PT', 'label' => 'Portugalia'],
            ['code' => 'SE', 'label' => 'Suedia'],
            ['code' => 'SI', 'label' => 'Slovenia'],
            ['code' => 'SK', 'label' => 'Slovacia'],
            ['code' => 'TR', 'label' => 'Turcia'],
            ['code' => 'UA', 'label' => 'Ucraina'],
            ['code' => 'US', 'label' => 'Statele Unite'],
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
            'ossApplicable' => $ossApplicable,
            'ossVatRate' => $ossVatRate,
            'ossVatRates' => $ossVatRates,
            'countries' => $countries,
            'counties' => $counties,
        ]);
    }
}
