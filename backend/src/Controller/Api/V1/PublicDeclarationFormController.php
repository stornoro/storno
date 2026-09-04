<?php

namespace App\Controller\Api\V1;

use App\Service\Declaration\AnafOnlineValidator;
use App\Service\Declaration\DeclarationPdfService;
use App\Service\Declaration\DeclarationValidator;
use App\Service\Declaration\DukUnavailableException;
use App\Service\Declaration\Forms\DeclarationFormRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public, account-less declaration forms for AI assistants (MCP) and integrators.
 * Nothing is stored; the user's documents stay on their side, Storno only gets the
 * structured fields it needs to write and validate the XML.
 *
 *   GET  /api/v1/public/declarations/forms                → catalog
 *   GET  /api/v1/public/declarations/forms/{type}         → specification (?xsd=1 → the XSD)
 *   POST /api/v1/public/declarations/forms/{type}/build   → XML + Storno rules + DUK + ANAF online validation
 *   POST /api/v1/public/declarations/pdf                  → DUK PDF with embedded XML and attachment zip
 */
#[Route('/api/v1/public/declarations')]
class PublicDeclarationFormController extends AbstractController
{
    private const MAX_BUILD_BYTES = 512 * 1024;
    private const MAX_PDF_BYTES = 16 * 1024 * 1024;

    public function __construct(
        private readonly DeclarationFormRegistry $forms,
        private readonly DeclarationValidator $validator,
        private readonly AnafOnlineValidator $online,
        private readonly DeclarationPdfService $pdf,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/forms', methods: ['GET'])]
    public function catalog(): JsonResponse
    {
        return $this->json(['forms' => $this->forms->catalog()]);
    }

    #[Route('/forms/{type}', methods: ['GET'])]
    public function spec(string $type, Request $request): Response
    {
        $form = $this->forms->get($type);
        if ($form === null) {
            return $this->json(['error' => 'Formular necunoscut. Vezi GET /api/v1/public/declarations/forms.', 'code' => 'UNKNOWN_FORM'], Response::HTTP_NOT_FOUND);
        }
        if ($request->query->getBoolean('xsd')) {
            $spec = $form->spec();
            $file = $this->projectDir() . '/resources/declarations/' . strtolower($form->type()) . '_*.xsd';
            $matches = glob($file) ?: [];
            if ($matches === []) {
                return $this->json(['error' => 'XSD indisponibil pentru acest formular.', 'code' => 'NO_XSD', 'namespace' => $spec['xml']['namespace'] ?? null], Response::HTTP_NOT_FOUND);
            }

            return new Response((string) file_get_contents($matches[0]), 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
        }

        return $this->json($form->spec());
    }

    #[Route('/forms/{type}/build', methods: ['POST'])]
    public function build(string $type, Request $request, RateLimiterFactory $publicValidateLimiter): JsonResponse
    {
        if (!$publicValidateLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            return $this->json(['error' => 'Prea multe cereri.', 'code' => 'RATE_LIMITED'], Response::HTTP_TOO_MANY_REQUESTS);
        }
        $form = $this->forms->get($type);
        if ($form === null) {
            return $this->json(['error' => 'Formular necunoscut.', 'code' => 'UNKNOWN_FORM'], Response::HTTP_NOT_FOUND);
        }
        $content = $request->getContent();
        if (strlen($content) > self::MAX_BUILD_BYTES) {
            return $this->json(['error' => 'Cererea este prea mare.', 'code' => 'PAYLOAD_TOO_LARGE'], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }
        $body = json_decode($content, true);
        if (!is_array($body)) {
            return $this->json(['error' => 'Corpul cererii trebuie să fie JSON.', 'code' => 'INVALID_JSON'], Response::HTTP_BAD_REQUEST);
        }
        $input = is_array($body['input'] ?? null) ? $body['input'] : $body;
        $wantDuk = ($body['validate'] ?? true) !== false;
        $wantOnline = ($body['online'] ?? true) !== false;

        $result = $form->build($input);
        $duk = null;
        $onlineResult = null;
        $dukError = null;
        if ($wantDuk && !$result->hasErrors()) {
            try {
                $outcome = $this->validator->validate($result->xml, $form->type(), false);
                $duk = ['valid' => $outcome->valid, 'errors' => $outcome->errors, 'warnings' => $outcome->warnings, 'elapsedMs' => $outcome->elapsedMs];
            } catch (DukUnavailableException $e) {
                $dukError = $e->getMessage();
            } catch (\Throwable $e) {
                $this->logger->error('Form build validation failed', ['type' => $form->type(), 'error' => $e->getMessage()]);
                $dukError = 'Validarea DUK a eșuat.';
            }
            if ($wantOnline && $this->online->supports($form->type()) && ($duk === null || $duk['valid'])) {
                $onlineResult = $this->online->validate($form->type(), $result->xml);
            }
        }
        $valid = !$result->hasErrors() && ($duk === null ? $dukError === null && !$wantDuk : $duk['valid']) && ($onlineResult === null || $onlineResult['valid']);

        return $this->json([
            'type' => $form->type(),
            'valid' => $valid,
            'xml' => $result->xml,
            'issues' => $result->issues,
            'validation' => [
                'duk' => $duk,
                'dukError' => $dukError,
                'anafOnline' => $onlineResult,
            ],
            'next' => $valid
                ? 'POST /api/v1/public/declarations/pdf (declaration_pdf) with the attachment(s), then file the PDF through the Storno Agent or upload it in SPV.'
                : 'Fix the issues / validation errors and build again.',
        ]);
    }

    #[Route('/pdf', methods: ['POST'])]
    public function pdf(Request $request, RateLimiterFactory $publicValidateLimiter): Response
    {
        if (!$publicValidateLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            return $this->json(['error' => 'Prea multe cereri.', 'code' => 'RATE_LIMITED'], Response::HTTP_TOO_MANY_REQUESTS);
        }
        $content = $request->getContent();
        if (strlen($content) > self::MAX_PDF_BYTES) {
            return $this->json(['error' => 'Cererea este prea mare (maximum 16 MB).', 'code' => 'PAYLOAD_TOO_LARGE'], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }
        $body = json_decode($content, true);
        if (!is_array($body) || !is_string($body['xml'] ?? null) || trim($body['xml']) === '') {
            return $this->json(['error' => 'Corpul cererii trebuie să fie JSON cu "xml", "type" și "attachments".', 'code' => 'INVALID_JSON'], Response::HTTP_BAD_REQUEST);
        }
        $xml = ltrim($body['xml'], "\xEF\xBB\xBF \t\r\n");
        $type = strtoupper(trim((string) ($body['type'] ?? '')));
        if ($type === '') {
            $type = (string) $this->validator->inferType($xml);
        }
        if (!preg_match('/^[A-Z]\d{2,4}[A-Z]?$/', $type)) {
            return $this->json(['error' => 'Tipul declarației lipsește (de exemplu C168).', 'code' => 'UNKNOWN_TYPE'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $attachments = [];
        foreach (is_array($body['attachments'] ?? null) ? $body['attachments'] : [] as $i => $a) {
            if (!is_array($a) || !is_string($a['contentBase64'] ?? null)) {
                return $this->json(['error' => 'Fiecare atașament are "name" și "contentBase64".', 'code' => 'INVALID_ATTACHMENT'], Response::HTTP_BAD_REQUEST);
            }
            $bin = base64_decode($a['contentBase64'], true);
            if ($bin === false || $bin === '') {
                return $this->json(['error' => sprintf('Atașamentul %d nu este base64 valid.', $i + 1), 'code' => 'INVALID_ATTACHMENT'], Response::HTTP_BAD_REQUEST);
            }
            $attachments[] = ['name' => (string) ($a['name'] ?? ('document-' . ($i + 1) . '.pdf')), 'content' => $bin];
        }

        try {
            $pdf = $this->pdf->render($type, $xml, $attachments);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage(), 'code' => 'VALIDATION_FAILED'], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (DukUnavailableException $e) {
            return $this->json(['error' => $e->getMessage(), 'code' => 'VALIDATOR_UNAVAILABLE'], Response::HTTP_SERVICE_UNAVAILABLE);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'validation errors')) {
                return $this->json(['error' => $msg, 'code' => 'VALIDATION_FAILED'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $this->logger->error('Declaration PDF failed', ['type' => $type, 'error' => $msg]);

            return $this->json(['error' => 'Generarea PDF a eșuat: ' . $msg, 'code' => 'PDF_FAILED'], Response::HTTP_BAD_GATEWAY);
        }

        $fileName = sprintf('%s_%s.pdf', $type, date('Ymd_His'));
        if ($request->query->get('format') === 'pdf') {
            return new Response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => sprintf('attachment; filename="%s"', $fileName)]);
        }

        return $this->json(['type' => $type, 'fileName' => $fileName, 'bytes' => strlen($pdf), 'pdfBase64' => base64_encode($pdf), 'next' => 'Sign and upload with agent_submit_declaration_pdf (Storno Agent + certificate) or upload the PDF in SPV; then anaf_declaration_status(index, cif).']);
    }

    private function projectDir(): string
    {
        return (string) $this->getParameter('kernel.project_dir');
    }
}
