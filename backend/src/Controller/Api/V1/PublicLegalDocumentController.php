<?php

namespace App\Controller\Api\V1;

use App\Service\Document\LegalDocumentService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public generator of standard legal documents (rental termination agreement,
 * sworn statement for C168). Nothing is stored. Rate limited per IP.
 *
 *   GET  /api/v1/public/documents                 → catalog (types + required fields)
 *   POST /api/v1/public/documents/{type}          → {"title", "html", "pdfBase64"}
 *   POST /api/v1/public/documents/{type}?format=pdf → application/pdf
 */
#[Route('/api/v1/public/documents')]
class PublicLegalDocumentController extends AbstractController
{
    private const MAX_BODY_BYTES = 64 * 1024;

    public function __construct(
        private readonly LegalDocumentService $documents,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('', methods: ['GET'])]
    public function catalog(): JsonResponse
    {
        return $this->json(['types' => $this->documents->catalog()]);
    }

    #[Route('/{type}', methods: ['POST'])]
    public function generate(string $type, Request $request, RateLimiterFactory $publicValidateLimiter): Response
    {
        if (!$publicValidateLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            return $this->json(['error' => 'Prea multe cereri.', 'code' => 'RATE_LIMITED'], Response::HTTP_TOO_MANY_REQUESTS);
        }
        $content = $request->getContent();
        if (strlen($content) > self::MAX_BODY_BYTES) {
            return $this->json(['error' => 'Cererea este prea mare.', 'code' => 'PAYLOAD_TOO_LARGE'], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }
        $fields = json_decode($content, true);
        if (!is_array($fields)) {
            return $this->json(['error' => 'Corpul cererii trebuie sa fie JSON cu campurile documentului.', 'code' => 'INVALID_JSON'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $doc = $this->documents->render($type, $fields);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage(), 'code' => 'VALIDATION_FAILED'], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            $this->logger->error('Legal document generation failed', ['type' => $type, 'error' => $e->getMessage()]);

            return $this->json(['error' => 'Nu am putut genera documentul.', 'code' => 'GENERATION_FAILED'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        if ($request->query->get('format') === 'pdf') {
            return new Response($doc['pdf'], 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => sprintf('attachment; filename="%s.pdf"', $type),
            ]);
        }

        return $this->json(['type' => $type, 'title' => $doc['title'], 'html' => $doc['html'], 'pdfBase64' => base64_encode($doc['pdf'])]);
    }
}
