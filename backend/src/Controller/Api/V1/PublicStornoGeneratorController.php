<?php

namespace App\Controller\Api\V1;

use App\Exception\PublicStornoGeneratorException;
use App\Service\Anaf\PublicStornoGenerator;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public, unauthenticated endpoint behind the landing-site tool
 * "Generator factura storno". Nothing is stored; the result is the UBL XML
 * plus the validation report. Rate limited per client IP.
 */
class PublicStornoGeneratorController extends AbstractController
{
    private const MAX_BODY_BYTES = 64 * 1024;

    public function __construct(
        private readonly PublicStornoGenerator $generator,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/api/v1/public/storno-generator', methods: ['POST'])]
    public function __invoke(Request $request, RateLimiterFactory $publicGeneratorLimiter): JsonResponse
    {
        $limiter = $publicGeneratorLimiter->create($request->getClientIp() ?? 'unknown');
        if (!$limiter->consume()->isAccepted()) {
            return $this->json([
                'error' => 'Prea multe cereri. Incearca din nou peste cateva minute.',
                'code' => 'RATE_LIMITED',
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $content = $request->getContent();
        if (strlen($content) > self::MAX_BODY_BYTES) {
            return $this->json([
                'error' => 'Cererea este prea mare.',
                'code' => 'PAYLOAD_TOO_LARGE',
            ], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        $payload = json_decode($content, true);
        if (!is_array($payload)) {
            return $this->json([
                'error' => 'Corpul cererii trebuie sa fie JSON valid.',
                'code' => 'INVALID_JSON',
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $result = $this->generator->generate($payload);
        } catch (PublicStornoGeneratorException $e) {
            return $this->json([
                'error' => $e->getMessage(),
                'code' => 'VALIDATION_FAILED',
                'fieldErrors' => $e->getFieldErrors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            $this->logger->error('Public storno generator failed', [
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ]);

            return $this->json([
                'error' => 'Nu am putut genera XML-ul. Incearca din nou.',
                'code' => 'GENERATION_FAILED',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json($result);
    }
}
