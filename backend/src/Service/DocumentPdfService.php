<?php

namespace App\Service;

use App\Entity\Company;
use App\Entity\DeliveryNote;
use App\Entity\Invoice;
use App\Entity\PdfTemplateConfig;
use App\Entity\ProformaInvoice;
use App\Entity\Receipt;
use App\Enum\InvoiceDirection;
use App\Repository\BankAccountRepository;
use App\Repository\PdfTemplateConfigRepository;
use App\Service\EuVatRateService;
use App\Service\ExchangeRateService;
use App\Service\Storage\OrganizationStorageResolver;
use Knp\Snappy\Pdf;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Twig\Environment;

class DocumentPdfService
{
    private const AVAILABLE_TEMPLATES = [
        [
            'slug' => 'classic',
            'name' => 'Clasic',
            'description' => 'Design traditional cu linii curate si culori profesionale',
            'defaultColor' => '#2563eb',
        ],
        [
            'slug' => 'modern',
            'name' => 'Modern',
            'description' => 'Design modern cu colturi rotunjite si antet colorat',
            'defaultColor' => '#6366f1',
        ],
        [
            'slug' => 'minimal',
            'name' => 'Minimal',
            'description' => 'Design minimalist cu linii fine si aspect compact',
            'defaultColor' => '#374151',
        ],
        [
            'slug' => 'bold',
            'name' => 'Indrăzneț',
            'description' => 'Design puternic cu bara de culoare si totaluri mari',
            'defaultColor' => '#dc2626',
        ],
    ];

    private const DOC_TYPE_MAP = [
        'invoice' => 'invoice',
        'credit_note' => 'storno',
        'proforma' => 'proforma',
        'delivery_note' => 'aviz',
        'receipt' => 'bon',
    ];

    public function __construct(
        private readonly Environment $twig,
        private readonly Pdf $snappy,
        private readonly PdfTemplateConfigRepository $configRepository,
        private readonly BankAccountRepository $bankAccountRepository,
        private readonly EuVatRateService $euVatRateService,
        private readonly ExchangeRateService $exchangeRateService,
        private readonly FilesystemOperator $defaultStorage,
        private readonly OrganizationStorageResolver $storageResolver,
        private readonly WhiteLabelResolver $whiteLabelResolver,
        private readonly LoggerInterface $logger,
        private readonly string $projectDir,
    ) {}

    public function generateInvoicePdf(Invoice $invoice): string
    {
        $config = $this->resolveConfig($invoice->getCompany());
        $docType = $invoice->getDocumentType()?->value ?? 'invoice';
        $templateType = self::DOC_TYPE_MAP[$docType] ?? 'invoice';

        $lineFlags = $this->detectLineFlags($invoice->getLines());

        $html = $this->renderTemplate($config, $templateType, array_merge($lineFlags, [
            'invoice' => $invoice,
            'company' => $invoice->getCompany(),
            'client' => $invoice->getClient(),
            'buyerSnapshot' => $invoice->getBuyerSnapshot(),
            'config' => $config,
            'logoDataUri' => $this->resolveLogoDataUri($invoice->getCompany(), $config),
            'locale' => $invoice->getLanguage(),
        ], $this->computeVatInRon($invoice->getCurrency(), $invoice->getVatTotal(), $invoice->getExchangeRate())));

        return $this->convertToPdf($html);
    }

    public function generateProformaPdf(ProformaInvoice $proforma): string
    {
        $config = $this->resolveConfig($proforma->getCompany());

        $lineFlags = $this->detectLineFlags($proforma->getLines());

        $html = $this->renderTemplate($config, 'proforma', array_merge($lineFlags, [
            'invoice' => $proforma,
            'company' => $proforma->getCompany(),
            'client' => $proforma->getClient(),
            'config' => $config,
            'logoDataUri' => $this->resolveLogoDataUri($proforma->getCompany(), $config),
            'locale' => $proforma->getLanguage(),
        ], $this->computeVatInRon($proforma->getCurrency(), $proforma->getVatTotal(), $proforma->getExchangeRate())));

        return $this->convertToPdf($html);
    }

    public function generateDeliveryNotePdf(DeliveryNote $note, bool $hideVat = false, bool $hidePrices = false): string
    {
        $config = $this->resolveConfig($note->getCompany());

        $lineFlags = $this->detectLineFlags($note->getLines());

        $html = $this->renderTemplate($config, 'aviz', array_merge($lineFlags, [
            'invoice' => $note,
            'company' => $note->getCompany(),
            'client' => $note->getClient(),
            'config' => $config,
            'logoDataUri' => $this->resolveLogoDataUri($note->getCompany(), $config),
            'locale' => method_exists($note, 'getLanguage') ? $note->getLanguage() : 'ro',
            'hideVat' => $hideVat,
            'hidePrices' => $hidePrices,
        ]));

        return $this->convertToPdf($html);
    }

    public function generateReceiptPdf(Receipt $receipt): string
    {
        $config = $this->resolveConfig($receipt->getCompany());

        $lineFlags = $this->detectLineFlags($receipt->getLines());

        $html = $this->renderTemplate($config, 'bon', array_merge($lineFlags, [
            'invoice' => $receipt,
            'company' => $receipt->getCompany(),
            'client' => $receipt->getClient(),
            'config' => $config,
            'logoDataUri' => $this->resolveLogoDataUri($receipt->getCompany(), $config),
            'locale' => 'ro',
            'vatClassBreakdown' => $this->computeVatClassBreakdown($receipt),
        ]));

        // Fiscal receipts are thermal-printer format: 80mm wide, variable height.
        return $this->convertReceiptToPdf($html);
    }

    /**
     * Group VAT totals by rate, decorating each bucket with a semantic label
     * (Standard / Reduced / Zero / etc.) resolved from the company's country
     * via the EU VAT rates feed. Country-agnostic — falls back to a plain
     * numeric label when the rate isn't recognized.
     *
     * @return list<array{rate: string, label: ?string, base: float, vat: float}>
     */
    private function computeVatClassBreakdown(Receipt $receipt): array
    {
        $buckets = [];
        foreach ($receipt->getLines() as $line) {
            $rate = number_format((float) $line->getVatRate(), 2, '.', '');
            $vat  = (float) $line->getVatAmount();
            $base = (float) $line->getLineTotal() - $vat;
            if (!isset($buckets[$rate])) {
                $buckets[$rate] = ['rate' => $rate, 'label' => null, 'base' => 0.0, 'vat' => 0.0];
            }
            $buckets[$rate]['base'] += $base;
            $buckets[$rate]['vat']  += $vat;
        }

        $country = $receipt->getCompany()?->getCountry();
        if ($country && !empty($buckets)) {
            $countryRates = $this->euVatRateService->getAllRates($country) ?? [];
            // Build a map from "21.00" → "standard" for the country
            $rateToTier = [];
            foreach ($countryRates as $tier => $value) {
                $rateToTier[number_format((float) $value, 2, '.', '')] = $tier;
            }
            foreach ($buckets as &$b) {
                if (isset($rateToTier[$b['rate']])) {
                    $b['label'] = $this->vatTierLabel($rateToTier[$b['rate']]);
                } elseif ((float) $b['rate'] === 0.0) {
                    $b['label'] = $this->vatTierLabel('zero');
                }
            }
            unset($b);
        }

        usort($buckets, fn($a, $b) => (float) $b['rate'] <=> (float) $a['rate']);
        return array_values($buckets);
    }

    private function vatTierLabel(string $tier): string
    {
        return match ($tier) {
            'standard'      => 'Standard',
            'reduced'       => 'Reduced',
            'reduced1'      => 'Reduced',
            'reduced2'      => 'Reduced',
            'super_reduced' => 'Super reduced',
            'parking'       => 'Parking',
            'zero'          => 'Zero',
            default         => ucfirst($tier),
        };
    }

    public const CUSTOM_CSS_MAX_LENGTH = 20000;
    public const FONT_FAMILY_PATTERN = '/^[A-Za-z0-9 ,\'"-]{1,100}$/';

    /**
     * Validate user-supplied custom CSS that is injected verbatim into a <style>
     * block of the PDF templates. Returns an error message, or null when valid.
     *
     * Pure function: safe to call statically from controllers and tests.
     */
    public static function validateCustomCss(?string $css): ?string
    {
        if ($css === null || $css === '') {
            return null;
        }

        if (mb_strlen($css) > self::CUSTOM_CSS_MAX_LENGTH) {
            return sprintf('Custom CSS is too long (maximum %d characters).', self::CUSTOM_CSS_MAX_LENGTH);
        }

        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $css)) {
            return 'Custom CSS contains control characters, which are not allowed.';
        }

        // "<" is the only character that can terminate the surrounding <style>
        // element; ">" is left alone because it is the CSS child combinator.
        if (str_contains($css, '<')) {
            return 'Custom CSS must not contain the "<" character.';
        }

        // Backslash escapes (e.g. "\3c" for "<", "\75rl(" for "url(") can smuggle
        // forbidden tokens past the textual checks below; CSS rarely needs them.
        if (str_contains($css, '\\')) {
            return 'Custom CSS must not contain backslash escape sequences.';
        }

        // Normalise whitespace/comments so "@ import", "url /**/ (" etc. are caught too.
        $normalized = strtolower($css);
        $normalized = preg_replace('#/\*.*?\*/#s', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/', '', $normalized) ?? $normalized;

        $forbidden = [
            '@import' => 'Custom CSS must not contain @import rules.',
            'url(' => 'Custom CSS must not contain url() references.',
            'expression(' => 'Custom CSS must not contain expression().',
            'javascript:' => 'Custom CSS must not contain "javascript:" URLs.',
            '-moz-binding' => 'Custom CSS must not contain -moz-binding.',
            'behavior:' => 'Custom CSS must not contain "behavior:" declarations.',
        ];

        foreach ($forbidden as $needle => $message) {
            if (str_contains($normalized, $needle)) {
                return $message;
            }
        }

        return null;
    }

    /**
     * Validate a font-family name that is injected into the PDF stylesheet.
     * Returns an error message, or null when valid.
     */
    public static function validateFontFamily(?string $fontFamily): ?string
    {
        if ($fontFamily === null || $fontFamily === '') {
            return null;
        }

        if (!preg_match(self::FONT_FAMILY_PATTERN, $fontFamily)) {
            return 'Invalid font family. Use only letters, digits, spaces, commas, quotes and hyphens (max 100 characters).';
        }

        return null;
    }

    public function renderSampleHtml(Company $company, array $overrides = []): string
    {
        $config = $this->resolveConfig($company);
        // Override with preview values
        if (isset($overrides['templateSlug']) && in_array($overrides['templateSlug'], array_column(self::AVAILABLE_TEMPLATES, 'slug'), true)) {
            $config->setTemplateSlug($overrides['templateSlug']);
        }
        if (isset($overrides['primaryColor']) && preg_match('/^#[0-9a-fA-F]{6}$/', (string) $overrides['primaryColor'])) {
            $config->setPrimaryColor($overrides['primaryColor']);
        }
        if (isset($overrides['fontFamily']) && self::validateFontFamily((string) $overrides['fontFamily']) === null) {
            $config->setFontFamily((string) $overrides['fontFamily']);
        }
        if (isset($overrides['showLogo'])) {
            $config->setShowLogo((bool) $overrides['showLogo']);
        }
        if (isset($overrides['showBankInfo'])) {
            $config->setShowBankInfo((bool) $overrides['showBankInfo']);
        }
        if (isset($overrides['showVatInRon'])) {
            $config->setShowVatInRon((bool) $overrides['showVatInRon']);
        }
        if (isset($overrides['bankDisplaySection'])) {
            $config->setBankDisplaySection($overrides['bankDisplaySection']);
        }
        if (isset($overrides['bankDisplayMode'])) {
            $config->setBankDisplayMode($overrides['bankDisplayMode']);
        }
        if (array_key_exists('defaultNotes', $overrides)) {
            $config->setDefaultNotes($overrides['defaultNotes']);
        }
        if (array_key_exists('defaultPaymentTerms', $overrides)) {
            $config->setDefaultPaymentTerms($overrides['defaultPaymentTerms']);
        }
        if (array_key_exists('defaultPaymentMethod', $overrides)) {
            $config->setDefaultPaymentMethod($overrides['defaultPaymentMethod']);
        }
        if (array_key_exists('footerText', $overrides)) {
            $config->setFooterText($overrides['footerText']);
        }
        if (array_key_exists('labelOverrides', $overrides)) {
            $config->setLabelOverrides($overrides['labelOverrides']);
        }

        $sampleData = $this->buildSampleInvoiceData($company, $config);
        $sampleInvoice = $sampleData['invoice'];
        $vatInRonContext = $this->computeVatInRon(
            $sampleInvoice->currency,
            $sampleInvoice->vatTotal,
            $sampleInvoice->exchangeRate,
        );

        return $this->renderTemplate($config, 'invoice', array_merge($sampleData, [
            'config' => $config,
            'logoDataUri' => $this->resolveLogoDataUri($company, $config),
            'locale' => 'ro',
        ], $vatInRonContext));
    }

    public function getAvailableTemplates(): array
    {
        return self::AVAILABLE_TEMPLATES;
    }

    /**
     * Compute the RON-equivalent VAT for a foreign-currency document so the PDF
     * can satisfy Cod Fiscal art. 319 alin. (20) lit. j (VAT amount must be
     * shown in lei). Mirrors the rate resolution used by the ANAF UBL XML
     * (UblXmlGenerator::addTaxTotalInRon): the rate stored on the document wins,
     * otherwise the live/last-good BNR rate. Uses bcmul on the raw VAT total —
     * the same value shown on the PDF in document currency — so the lei figure
     * is internally consistent with the displayed EUR/USD VAT and matches the
     * XML TaxAmount(RON) for the common (no document-level allowance) case.
     *
     * Returns an empty array (no line rendered) for RON documents or when no
     * rate is available.
     *
     * @return array{vatInRon?: string, vatInRonRate?: string}
     */
    private function computeVatInRon(string $currency, string $vatTotal, ?string $exchangeRate): array
    {
        if (strtoupper($currency) === 'RON') {
            return [];
        }

        $rate = $exchangeRate !== null && $exchangeRate !== ''
            ? (float) $exchangeRate
            : $this->exchangeRateService->getRate($currency);

        if ($rate === null || $rate <= 0) {
            return [];
        }

        return [
            'vatInRon' => bcmul($vatTotal, (string) $rate, 2),
            // Machine-decimal string (dot, 4 places per BNR convention); the
            // Twig template formats the separator for the document locale.
            'vatInRonRate' => number_format($rate, 4, '.', ''),
        ];
    }

    public function isOutgoingInvoice(Invoice $invoice): bool
    {
        return $invoice->getDirection() === InvoiceDirection::OUTGOING;
    }

    private function resolveConfig(Company $company): PdfTemplateConfig
    {
        $config = $this->configRepository->findByCompany($company);

        if (!$config) {
            $config = new PdfTemplateConfig();
            $config->setCompany($company);
        }

        return $config;
    }

    private function renderTemplate(PdfTemplateConfig $config, string $docType, array $context): string
    {
        $slug = $config->getTemplateSlug();
        $templatePath = sprintf('documents/pdf/%s/%s.html.twig', $slug, $docType);

        // Resolve colors
        $defaultColor = '#2563eb';
        foreach (self::AVAILABLE_TEMPLATES as $tpl) {
            if ($tpl['slug'] === $slug) {
                $defaultColor = $tpl['defaultColor'];
                break;
            }
        }

        $context['primaryColor'] = $config->getPrimaryColor() ?? $defaultColor;
        $context['fontFamily'] = $config->getFontFamily() ?? 'DejaVu Sans';
        $context['fontsDir'] = $this->projectDir . '/assets/fonts';
        $context['showLogo'] = $config->isShowLogo();
        $context['showBankInfo'] = $config->isShowBankInfo();
        $context['showVatInRon'] = $config->isShowVatInRon();
        $context['customCss'] = $config->getCustomCss();
        $context['bankDisplaySection'] = $config->getBankDisplaySection();
        $context['bankDisplayMode'] = $config->getBankDisplayMode();
        $context['labelOverrides'] = $config->getLabelOverrides();

        // Fetch bank accounts marked for invoice display
        $company = $context['company'] ?? null;
        if ($company instanceof Company) {
            $context['invoiceBankAccounts'] = $this->bankAccountRepository->findForInvoice($company);
        } else {
            $context['invoiceBankAccounts'] = [];
        }

        $context['whiteLabelHideBranding'] = ($company instanceof Company && $company->getOrganization())
            ? $this->whiteLabelResolver->shouldHideBranding($company->getOrganization())
            : false;

        return $this->twig->render($templatePath, $context);
    }

    /**
     * Hardened wkhtmltopdf options shared by every document render.
     *
     * The rendered HTML contains user-controlled fragments (custom CSS, labels,
     * notes), so JavaScript is disabled and local file access is restricted to
     * the bundled fonts directory (the templates reference the Noto fonts via
     * file:// URLs; the logo is embedded as a data URI and needs no file access).
     */
    private function securePdfOptions(): array
    {
        return [
            'encoding' => 'UTF-8',
            'print-media-type' => true,
            'no-outline' => true,
            'disable-javascript' => true,
            'disable-external-links' => true,
            'disable-local-file-access' => true,
            'allow' => [$this->projectDir . '/assets/fonts'],
        ];
    }

    private function convertToPdf(string $html): string
    {
        return $this->snappy->getOutputFromHtml($html, $this->securePdfOptions() + [
            'page-size' => 'A4',
            'margin-top' => '10mm',
            'margin-bottom' => '10mm',
            'margin-left' => '10mm',
            'margin-right' => '10mm',
        ]);
    }

    /**
     * Render a fiscal-receipt-shaped PDF: 80mm thermal-printer paper, no client block,
     * monospace, narrow margins. Height grows automatically per content via wkhtmltopdf
     * page-height upper bound; long receipts simply paginate.
     */
    private function convertReceiptToPdf(string $html): string
    {
        return $this->snappy->getOutputFromHtml($html, $this->securePdfOptions() + [
            'page-width' => '80mm',
            'page-height' => '297mm',
            'margin-top' => '4mm',
            'margin-bottom' => '4mm',
            'margin-left' => '4mm',
            'margin-right' => '4mm',
        ]);
    }

    private function resolveLogoDataUri(Company $company, PdfTemplateConfig $config): ?string
    {
        if (!$config->isShowLogo()) {
            return null;
        }

        $logoPath = $company->getLogoPath();
        if (!$logoPath) {
            return null;
        }

        try {
            $storage = $this->storageResolver->resolveForCompany($company);
            if (!$storage->fileExists($logoPath)) {
                return null;
            }

            $content = $storage->read($logoPath);
            $mimeType = $storage->mimeType($logoPath);

            return sprintf('data:%s;base64,%s', $mimeType, base64_encode($content));
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to read company logo for PDF', [
                'companyId' => (string) $company->getId(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param iterable $lines
     */
    private function detectLineFlags(iterable $lines): array
    {
        $hasProductCodes = false;
        $hasLineDiscounts = false;

        foreach ($lines as $line) {
            if (method_exists($line, 'getProductCode') && $line->getProductCode()) {
                $hasProductCodes = true;
            }
            if (method_exists($line, 'getDiscount') && $line->getDiscount() && $line->getDiscount() !== '0.00') {
                $hasLineDiscounts = true;
            }
            if ($hasProductCodes && $hasLineDiscounts) {
                break;
            }
        }

        return [
            'hasProductCodes' => $hasProductCodes,
            'hasLineDiscounts' => $hasLineDiscounts,
        ];
    }

    private function buildSampleInvoiceData(Company $company, ?PdfTemplateConfig $config = null): array
    {
        return [
            'invoice' => (object) [
                'number' => 'DEMO-0001',
                'issueDate' => new \DateTimeImmutable(),
                'dueDate' => new \DateTimeImmutable('+30 days'),
                // Sample is rendered in EUR (with a representative BNR rate) so
                // the preview demonstrates foreign-currency features — the VAT
                // in RON line and the exchange-rate line — which are invisible
                // on a RON document.
                'currency' => 'EUR',
                'subtotal' => '1500.00',
                'vatTotal' => '285.00',
                'discount' => '0.00',
                'total' => '1785.00',
                'exchangeRate' => '5.2353',
                'notes' => null,
                'paymentTerms' => null,
                'paymentMethod' => null,
                'parentDocument' => null,
                'documentType' => (object) ['value' => 'invoice'],
                'orderNumber' => null,
                'contractNumber' => null,
                'projectReference' => null,
                'deliveryLocation' => null,
                'mentions' => null,
                'issuerName' => null,
                'issuerId' => null,
                'salesAgent' => null,
                'deputyName' => null,
                'deputyIdentityCard' => null,
                'deputyAuto' => null,
                'penaltyEnabled' => false,
                'penaltyPercentPerDay' => null,
                'penaltyGraceDays' => null,
                'lines' => [
                    (object) [
                        'position' => 1,
                        'description' => 'Servicii consultanta IT',
                        'quantity' => '10.00',
                        'unitOfMeasure' => 'ora',
                        'unitPrice' => '100.00',
                        'vatRate' => '19.00',
                        'vatAmount' => '190.00',
                        'lineTotal' => '1190.00',
                        'discount' => '0.00',
                        'discountPercent' => '0.00',
                        'productCode' => null,
                    ],
                    (object) [
                        'position' => 2,
                        'description' => 'Licenta software anual',
                        'quantity' => '1.00',
                        'unitOfMeasure' => 'buc',
                        'unitPrice' => '500.00',
                        'vatRate' => '19.00',
                        'vatAmount' => '95.00',
                        'lineTotal' => '595.00',
                        'discount' => '0.00',
                        'discountPercent' => '0.00',
                        'productCode' => null,
                    ],
                ],
            ],
            'company' => $company,
            'client' => (object) [
                'type' => 'company',
                'name' => 'SC Exemplu Client SRL',
                'cui' => '12345678',
                'isVatPayer' => true,
                'registrationNumber' => 'J40/1234/2020',
                'address' => 'Str. Exemplu nr. 1',
                'city' => 'Bucuresti',
                'county' => 'Sector 1',
                'postalCode' => '010101',
                'country' => 'RO',
                'phone' => '0212345678',
                'email' => 'contact@exemplu.ro',
                'contactPerson' => null,
                'cnp' => null,
            ],
            'hasProductCodes' => false,
            'hasLineDiscounts' => false,
        ];
    }
}
