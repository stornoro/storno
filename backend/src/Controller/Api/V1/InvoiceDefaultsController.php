<?php

namespace App\Controller\Api\V1;

use App\Manager\CompanyManager;
use App\Repository\VatRateRepository;
use App\Security\OrganizationContext;
use App\Service\ExchangeRateService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/invoice-defaults')]
class InvoiceDefaultsController extends AbstractController
{
    public function __construct(
        private readonly OrganizationContext $organizationContext,
        private readonly ExchangeRateService $exchangeRateService,
        private readonly VatRateRepository $vatRateRepository,
        private readonly CompanyManager $companyManager,
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
            'countries' => $countries,
            'counties' => $counties,
        ]);
    }
}
