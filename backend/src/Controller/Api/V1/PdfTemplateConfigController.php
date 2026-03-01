<?php

namespace App\Controller\Api\V1;

use App\Entity\PdfTemplateConfig;
use App\Repository\PdfTemplateConfigRepository;
use App\Security\OrganizationContext;
use App\Service\DocumentPdfService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1')]
class PdfTemplateConfigController extends AbstractController
{
    public function __construct(
        private readonly PdfTemplateConfigRepository $configRepository,
        private readonly OrganizationContext $organizationContext,
        private readonly DocumentPdfService $documentPdfService,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    #[Route('/pdf-template-config', methods: ['GET'])]
    public function getConfig(Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        $config = $this->configRepository->findByCompany($company);
        if (!$config) {
            $config = new PdfTemplateConfig();
            $config->setCompany($company);
            $this->entityManager->persist($config);
            $this->entityManager->flush();
        }

        return $this->json($this->serializeConfig($config));
    }

    #[Route('/pdf-template-config', methods: ['PUT'])]
    public function updateConfig(Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        $config = $this->configRepository->findByCompany($company);
        if (!$config) {
            $config = new PdfTemplateConfig();
            $config->setCompany($company);
            $this->entityManager->persist($config);
        }

        $data = json_decode($request->getContent(), true);

        if (isset($data['templateSlug'])) {
            $validSlugs = array_column($this->documentPdfService->getAvailableTemplates(), 'slug');
            if (!in_array($data['templateSlug'], $validSlugs, true)) {
                return $this->json(['error' => 'Invalid template slug.'], Response::HTTP_BAD_REQUEST);
            }
            $config->setTemplateSlug($data['templateSlug']);
        }

        if (array_key_exists('primaryColor', $data)) {
            if ($data['primaryColor'] !== null && !preg_match('/^#[0-9a-fA-F]{6}$/', $data['primaryColor'])) {
                return $this->json(['error' => 'Invalid color format. Use hex (e.g. #2563eb).'], Response::HTTP_BAD_REQUEST);
            }
            $config->setPrimaryColor($data['primaryColor']);
        }

        if (array_key_exists('fontFamily', $data)) {
            $config->setFontFamily($data['fontFamily']);
        }

        if (isset($data['showLogo'])) {
            $config->setShowLogo((bool) $data['showLogo']);
        }

        if (isset($data['showBankInfo'])) {
            $config->setShowBankInfo((bool) $data['showBankInfo']);
        }

        if (isset($data['bankDisplaySection'])) {
            $validSections = ['supplier', 'payment', 'both'];
            if (in_array($data['bankDisplaySection'], $validSections, true)) {
                $config->setBankDisplaySection($data['bankDisplaySection']);
            }
        }

        if (isset($data['bankDisplayMode'])) {
            $validModes = ['stacked', 'inline'];
            if (in_array($data['bankDisplayMode'], $validModes, true)) {
                $config->setBankDisplayMode($data['bankDisplayMode']);
            }
        }

        if (array_key_exists('defaultNotes', $data)) {
            $config->setDefaultNotes($data['defaultNotes']);
        }

        if (array_key_exists('defaultPaymentTerms', $data)) {
            $config->setDefaultPaymentTerms($data['defaultPaymentTerms']);
        }

        if (array_key_exists('defaultPaymentMethod', $data)) {
            $validMethods = ['bank_transfer', 'cash', 'card', 'cheque', 'other', null];
            if (in_array($data['defaultPaymentMethod'], $validMethods, true)) {
                $config->setDefaultPaymentMethod($data['defaultPaymentMethod']);
            }
        }

        if (array_key_exists('footerText', $data)) {
            $config->setFooterText($data['footerText']);
        }

        if (array_key_exists('customCss', $data)) {
            $config->setCustomCss($data['customCss']);
        }

        if (array_key_exists('labelOverrides', $data)) {
            $raw = $data['labelOverrides'];
            if ($raw !== null && is_array($raw)) {
                $allowedKeys = [
                    'invoice_title', 'proforma_title', 'credit_note_title', 'delivery_note_title', 'receipt_title',
                    'date_label', 'due_date', 'subtotal', 'vat_label', 'discount_label', 'total', 'exchange_rate',
                    'payment_method', 'notes', 'payment_terms', 'bank_account', 'footer_text',
                    'supplier', 'supplier_cui', 'supplier_reg_number', 'supplier_address', 'supplier_county',
                    'supplier_phone', 'supplier_email', 'supplier_website',
                    'client', 'client_cui', 'client_reg_number', 'client_cnp', 'client_address', 'client_county',
                    'client_phone', 'client_email', 'client_contact',
                    'col_description', 'col_code', 'col_unit', 'col_quantity', 'col_unit_price',
                    'col_line_total', 'col_vat_percent', 'col_vat', 'col_total',
                ];
                $sanitized = [];
                foreach ($raw as $key => $entry) {
                    if (!in_array($key, $allowedKeys, true) || !is_array($entry)) {
                        continue;
                    }
                    $item = [];
                    if (isset($entry['visible'])) {
                        $item['visible'] = (bool) $entry['visible'];
                    }
                    if (array_key_exists('text', $entry)) {
                        $text = $entry['text'];
                        $item['text'] = ($text !== null && $text !== '') ? mb_substr((string) $text, 0, 200) : null;
                    }
                    if (!empty($item)) {
                        $sanitized[$key] = $item;
                    }
                }
                $config->setLabelOverrides(!empty($sanitized) ? $sanitized : null);
            } else {
                $config->setLabelOverrides(null);
            }
        }

        $this->entityManager->flush();

        return $this->json($this->serializeConfig($config));
    }

    #[Route('/pdf-template-config/templates', methods: ['GET'])]
    public function listTemplates(): JsonResponse
    {
        return $this->json($this->documentPdfService->getAvailableTemplates());
    }

    #[Route('/pdf-template-config/preview', methods: ['POST'])]
    public function preview(Request $request): Response
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true) ?? [];

        try {
            $html = $this->documentPdfService->renderSampleHtml($company, $data);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'Preview generation failed.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new Response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }

    private function serializeConfig(PdfTemplateConfig $config): array
    {
        return [
            'id' => (string) $config->getId(),
            'templateSlug' => $config->getTemplateSlug(),
            'primaryColor' => $config->getPrimaryColor(),
            'fontFamily' => $config->getFontFamily(),
            'showLogo' => $config->isShowLogo(),
            'showBankInfo' => $config->isShowBankInfo(),
            'bankDisplaySection' => $config->getBankDisplaySection(),
            'bankDisplayMode' => $config->getBankDisplayMode(),
            'defaultNotes' => $config->getDefaultNotes(),
            'defaultPaymentTerms' => $config->getDefaultPaymentTerms(),
            'defaultPaymentMethod' => $config->getDefaultPaymentMethod(),
            'footerText' => $config->getFooterText(),
            'customCss' => $config->getCustomCss(),
            'labelOverrides' => $config->getLabelOverrides(),
        ];
    }
}
