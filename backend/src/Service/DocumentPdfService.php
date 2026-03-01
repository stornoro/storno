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
        private readonly FilesystemOperator $defaultStorage,
        private readonly OrganizationStorageResolver $storageResolver,
        private readonly LoggerInterface $logger,
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
            'config' => $config,
            'logoDataUri' => $this->resolveLogoDataUri($invoice->getCompany(), $config),
            'locale' => $invoice->getLanguage(),
        ]));

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
        ]));

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
        ]));

        return $this->convertToPdf($html);
    }

    public function renderSampleHtml(Company $company, array $overrides = []): string
    {
        $config = $this->resolveConfig($company);
        // Override with preview values
        if (isset($overrides['templateSlug'])) {
            $config->setTemplateSlug($overrides['templateSlug']);
        }
        if (isset($overrides['primaryColor'])) {
            $config->setPrimaryColor($overrides['primaryColor']);
        }
        if (isset($overrides['fontFamily'])) {
            $config->setFontFamily($overrides['fontFamily']);
        }
        if (isset($overrides['showLogo'])) {
            $config->setShowLogo((bool) $overrides['showLogo']);
        }
        if (isset($overrides['showBankInfo'])) {
            $config->setShowBankInfo((bool) $overrides['showBankInfo']);
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

        return $this->renderTemplate($config, 'invoice', array_merge($sampleData, [
            'config' => $config,
            'logoDataUri' => $this->resolveLogoDataUri($company, $config),
            'locale' => 'ro',
        ]));
    }

    public function getAvailableTemplates(): array
    {
        return self::AVAILABLE_TEMPLATES;
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
        $context['showLogo'] = $config->isShowLogo();
        $context['showBankInfo'] = $config->isShowBankInfo();
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

        return $this->twig->render($templatePath, $context);
    }

    private function convertToPdf(string $html): string
    {
        return $this->snappy->getOutputFromHtml($html, [
            'page-size' => 'A4',
            'margin-top' => '10mm',
            'margin-bottom' => '10mm',
            'margin-left' => '10mm',
            'margin-right' => '10mm',
            'encoding' => 'UTF-8',
            'print-media-type' => true,
            'no-outline' => true,
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
                'currency' => $company->getDefaultCurrency(),
                'subtotal' => '1500.00',
                'vatTotal' => '285.00',
                'discount' => '0.00',
                'total' => '1785.00',
                'exchangeRate' => null,
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
