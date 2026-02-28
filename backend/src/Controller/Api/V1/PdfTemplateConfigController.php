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

        if (array_key_exists('footerText', $data)) {
            $config->setFooterText($data['footerText']);
        }

        if (array_key_exists('customCss', $data)) {
            $config->setCustomCss($data['customCss']);
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

        $data = json_decode($request->getContent(), true);
        $slug = $data['templateSlug'] ?? 'classic';
        $color = $data['primaryColor'] ?? null;
        $font = $data['fontFamily'] ?? null;

        try {
            $html = $this->documentPdfService->renderSampleHtml($company, $slug, $color, $font);
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
            'footerText' => $config->getFooterText(),
            'customCss' => $config->getCustomCss(),
        ];
    }
}
