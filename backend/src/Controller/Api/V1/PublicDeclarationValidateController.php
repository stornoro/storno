<?php

namespace App\Controller\Api\V1;

use App\Service\Declaration\AnafDeclarationClient;
use App\Service\Declaration\DeclarationValidator;
use App\Service\Declaration\DukUnavailableException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public, unauthenticated validation of an ANAF declaration XML with ANAF's own
 * DUKIntegrator validators (the same jars the portal uses). Nothing is stored.
 * Rate limited per client IP. Used by the landing-site tools, the MCP server and
 * anyone who wants to check a file before uploading it to SPV.
 *
 * Body: {"xml": "<d212 …/>", "type": "D212"}   (type optional: inferred from the root element)
 *   or raw XML with Content-Type: application/xml and ?type=D212
 */
class PublicDeclarationValidateController extends AbstractController
{
    private const MAX_BODY_BYTES = 4 * 1024 * 1024;

    public function __construct(
        private readonly DeclarationValidator $validator,
        private readonly AnafDeclarationClient $anafClient,
        private readonly LoggerInterface $logger,
    ) {}

    /** Processing status of a declaration filed on the e-guvernare portal: ANAF's public StareD112, by upload index and CUI/CNP. */
    #[Route('/api/v1/public/declarations/status/{index}/{cui}', methods: ['GET'])]
    public function status(string $index, string $cui, Request $request, RateLimiterFactory $publicValidateLimiter): JsonResponse
    {
        if (!$publicValidateLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            return $this->json(['error' => 'Prea multe cereri.', 'code' => 'RATE_LIMITED'], Response::HTTP_TOO_MANY_REQUESTS);
        }
        if (!preg_match('/^\d{1,15}$/', $index) || !preg_match('/^\d{2,13}$/', $cui)) {
            return $this->json(['error' => 'index si cui trebuie sa fie numerice.', 'code' => 'INVALID_INPUT'], Response::HTTP_BAD_REQUEST);
        }
        try {
            $r = $this->anafClient->checkPortalStatus($index, $cui);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'ANAF StareD112 nu a raspuns.', 'code' => 'ANAF_UNAVAILABLE'], Response::HTTP_BAD_GATEWAY);
        }
        $labels = ['ok' => 'Documentul este valid: declaratia a fost acceptata.', 'nok' => 'Documentul are erori de validare: depunerea nu este valida, vezi recipisa.', 'processing' => 'In prelucrare la ANAF.', 'unknown' => 'Nicio declaratie gasita pentru acest index si CUI (inca neindexata sau date gresite).'];

        return $this->json(['index' => $index, 'cui' => $cui, 'state' => $r['stare'], 'message' => $labels[$r['stare']] ?? $r['stare'], 'anafText' => $r['text'], 'recipisaUrl' => $r['stare'] === 'unknown' ? null : sprintf('https://www.anaf.ro/StareD112/ObtineRecipisa?numefisier=%s.pdf', $index)]);
    }

    #[Route('/api/v1/public/declarations/validate', methods: ['POST'])]
    public function __invoke(Request $request, RateLimiterFactory $publicValidateLimiter): JsonResponse
    {
        $limiter = $publicValidateLimiter->create($request->getClientIp() ?? 'unknown');
        if (!$limiter->consume()->isAccepted()) {
            return $this->json([
                'error' => 'Prea multe cereri. Incearca din nou peste cateva minute.',
                'code' => 'RATE_LIMITED',
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $content = $request->getContent();
        if (strlen($content) > self::MAX_BODY_BYTES) {
            return $this->json(['error' => 'Fisierul este prea mare (maximum 4 MB).', 'code' => 'PAYLOAD_TOO_LARGE'], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        $type = null;
        $xml = null;
        if (str_contains((string) $request->headers->get('Content-Type'), 'json')) {
            $payload = json_decode($content, true);
            if (!is_array($payload) || !isset($payload['xml']) || !is_string($payload['xml'])) {
                return $this->json(['error' => 'Corpul cererii trebuie sa fie JSON cu campul "xml".', 'code' => 'INVALID_JSON'], Response::HTTP_BAD_REQUEST);
            }
            $xml = $payload['xml'];
            $type = isset($payload['type']) && is_string($payload['type']) ? $payload['type'] : null;
        } else {
            $xml = $content;
            $type = $request->query->get('type');
        }

        $xml = ltrim($xml, "\xEF\xBB\xBF \t\r\n");
        if ($xml === '' || $xml[0] !== '<') {
            return $this->json(['error' => 'Continutul nu este un document XML.', 'code' => 'INVALID_XML'], Response::HTTP_BAD_REQUEST);
        }

        $type = $type !== null && $type !== '' ? strtoupper(trim((string) $type)) : $this->validator->inferType($xml);
        if ($type === null || !preg_match('/^[A-Z]\d{2,4}[A-Z]?$/', $type)) {
            return $this->json([
                'error' => 'Nu am putut determina tipul declaratiei. Trimite campul "type" (de exemplu D212, C168, D300).',
                'code' => 'UNKNOWN_TYPE',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $outcome = $this->validator->validate($xml, $type);
        } catch (DukUnavailableException $e) {
            return $this->json(['error' => $e->getMessage(), 'code' => 'VALIDATOR_UNAVAILABLE'], Response::HTTP_SERVICE_UNAVAILABLE);
        } catch (\Throwable $e) {
            $this->logger->error('Public declaration validation failed', ['type' => $type, 'error' => $e->getMessage()]);

            return $this->json(['error' => 'Validarea a esuat. Incearca din nou.', 'code' => 'VALIDATION_FAILED'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json($outcome->toArray(includeXml: true));
    }
}
