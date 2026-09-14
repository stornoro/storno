<?php

namespace App\Controller\Api\V1;

use App\Entity\Client;
use App\Entity\User;
use App\Exception\EmailSendBlockedException;
use App\Repository\ClientRepository;
use App\Security\OrganizationContext;
use App\Security\Permission;
use App\Service\Client\ClientStatementEmailService;
use App\Service\Client\ClientStatementPdfService;
use App\Service\Client\ClientStatementService;
use App\Service\LicenseManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Customer statements (situație clienți): unpaid invoices per client with
 * aging bands, as JSON / PDF, and e-mailed to one or all clients with a balance.
 *
 * The company-wide routes carry a priority so that `/clients/statements` is
 * matched before `/clients/{uuid}`.
 */
#[Route('/api/v1/clients')]
class ClientStatementController extends AbstractController
{
    public function __construct(
        private readonly ClientRepository $clientRepository,
        private readonly OrganizationContext $organizationContext,
        private readonly ClientStatementService $statementService,
        private readonly ClientStatementPdfService $pdfService,
        private readonly ClientStatementEmailService $emailService,
        private readonly LicenseManager $licenseManager,
    ) {}

    #[Route('/statements', methods: ['GET'], priority: 10)]
    public function statements(Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->organizationContext->hasPermission(Permission::CLIENT_VIEW)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $asOf = $this->parseAsOf($request->query->get('asOf'));
        if ($asOf === null) {
            return $this->json(['error' => 'Invalid asOf date, expected YYYY-MM-DD.'], Response::HTTP_BAD_REQUEST);
        }

        return $this->json($this->statementService->statementsForCompany($company, $asOf));
    }

    #[Route('/statements/email', methods: ['POST'], priority: 10)]
    public function emailAll(Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->organizationContext->hasPermission(Permission::INVOICE_SEND)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true) ?: [];
        $dryRun = filter_var($data['dryRun'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $org = $company->getOrganization();
        if (!$dryRun && $org && !$this->licenseManager->canSendEmails($org)) {
            return $this->json(['error' => 'Email sending is not available on your plan.', 'code' => 'PLAN_LIMIT'], Response::HTTP_PAYMENT_REQUIRED);
        }

        $asOf = $this->parseAsOf($data['asOf'] ?? null);
        if ($asOf === null) {
            return $this->json(['error' => 'Invalid asOf date, expected YYYY-MM-DD.'], Response::HTTP_BAD_REQUEST);
        }

        $minBalance = $data['minBalance'] ?? '0.01';
        if (!is_numeric($minBalance) || (float) $minBalance < 0) {
            return $this->json(['error' => 'minBalance must be a non-negative number.'], Response::HTTP_BAD_REQUEST);
        }
        $minBalance = number_format((float) $minBalance, 2, '.', '');

        $message = $this->cleanMessage($data['message'] ?? null);
        if ($message === false) {
            return $this->json(['error' => 'message must be at most 2000 characters.'], Response::HTTP_BAD_REQUEST);
        }

        /** @var User|null $user */
        $user = $this->getUser();

        $result = $this->emailService->sendToAllWithBalance($company, $asOf, $minBalance, $message, $user, $dryRun);

        return $this->json($result);
    }

    #[Route('/{uuid}/statement', methods: ['GET'])]
    public function statement(string $uuid, Request $request): JsonResponse
    {
        $client = $this->findClient($uuid);
        if (!$client) {
            return $this->json(['error' => 'Client not found.'], Response::HTTP_NOT_FOUND);
        }
        $this->denyAccessUnlessGranted('CLIENT_VIEW', $client);

        $asOf = $this->parseAsOf($request->query->get('asOf'));
        if ($asOf === null) {
            return $this->json(['error' => 'Invalid asOf date, expected YYYY-MM-DD.'], Response::HTTP_BAD_REQUEST);
        }

        return $this->json($this->statementService->statement($client, $asOf));
    }

    #[Route('/{uuid}/statement.pdf', methods: ['GET'])]
    public function statementPdf(string $uuid, Request $request): Response
    {
        $client = $this->findClient($uuid);
        if (!$client) {
            return $this->json(['error' => 'Client not found.'], Response::HTTP_NOT_FOUND);
        }
        $this->denyAccessUnlessGranted('CLIENT_VIEW', $client);

        $asOf = $this->parseAsOf($request->query->get('asOf'));
        if ($asOf === null) {
            return $this->json(['error' => 'Invalid asOf date, expected YYYY-MM-DD.'], Response::HTTP_BAD_REQUEST);
        }

        $statement = $this->statementService->statement($client, $asOf);
        try {
            $pdf = $this->pdfService->generate($client, $statement);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'Failed to generate PDF: ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $disposition = $request->query->getBoolean('download', true) ? 'attachment' : 'inline';

        return new Response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('%s; filename="%s"', $disposition, $this->emailService->attachmentName($client, $asOf)),
            'Content-Length' => (string) strlen($pdf),
        ]);
    }

    #[Route('/{uuid}/statement/email', methods: ['POST'])]
    public function email(string $uuid, Request $request): JsonResponse
    {
        $client = $this->findClient($uuid);
        if (!$client) {
            return $this->json(['error' => 'Client not found.'], Response::HTTP_NOT_FOUND);
        }
        $this->denyAccessUnlessGranted('CLIENT_VIEW', $client);
        if (!$this->organizationContext->hasPermission(Permission::INVOICE_SEND)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $org = $client->getCompany()?->getOrganization();
        if ($org && !$this->licenseManager->canSendEmails($org)) {
            return $this->json(['error' => 'Email sending is not available on your plan.', 'code' => 'PLAN_LIMIT'], Response::HTTP_PAYMENT_REQUIRED);
        }

        $data = json_decode($request->getContent(), true) ?: [];

        $asOf = $this->parseAsOf($data['asOf'] ?? null);
        if ($asOf === null) {
            return $this->json(['error' => 'Invalid asOf date, expected YYYY-MM-DD.'], Response::HTTP_BAD_REQUEST);
        }

        $to = isset($data['to']) ? trim((string) $data['to']) : null;
        if ($to !== null && $to !== '' && !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['error' => 'Valid email address required.'], Response::HTTP_BAD_REQUEST);
        }
        $to = $to ?: $client->getEmail();
        if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['error' => 'The client has no email address; pass "to".', 'code' => 'NO_EMAIL'], Response::HTTP_BAD_REQUEST);
        }

        $message = $this->cleanMessage($data['message'] ?? null);
        if ($message === false) {
            return $this->json(['error' => 'message must be at most 2000 characters.'], Response::HTTP_BAD_REQUEST);
        }

        $statement = $this->statementService->statement($client, $asOf);
        if (bccomp($statement['balance'], '0.00', 2) <= 0) {
            return $this->json(['error' => 'The client has no outstanding balance.', 'code' => 'NO_BALANCE'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        /** @var User|null $user */
        $user = $this->getUser();

        try {
            $emailLog = $this->emailService->send($client, $asOf, $to, $message, $user, $statement);
        } catch (EmailSendBlockedException $e) {
            $headers = $e->retryAfter ? ['Retry-After' => (string) $e->retryAfter] : [];

            return $this->json(['error' => $e->getMessage(), 'code' => $e->errorCode], $e->httpStatus, $headers);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'Failed to send email: ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json($emailLog, context: ['groups' => ['email_log:detail']]);
    }

    private function findClient(string $uuid): ?Client
    {
        if (!Uuid::isValid($uuid)) {
            return null;
        }
        $client = $this->clientRepository->find(Uuid::fromString($uuid));
        if (!$client || $client->getDeletedAt() !== null || !$this->organizationContext->ownsCompany($client->getCompany())) {
            return null;
        }

        return $client;
    }

    /** @return \DateTimeImmutable|null null when the value is present but invalid */
    private function parseAsOf(mixed $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return new \DateTimeImmutable('today');
        }
        if (!\is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date && $date->format('Y-m-d') === $value ? $date : null;
    }

    /** @return string|null|false false when too long */
    private function cleanMessage(mixed $value): string|null|false
    {
        if ($value === null) {
            return null;
        }
        $value = trim(strip_tags((string) $value));
        if ($value === '') {
            return null;
        }

        return mb_strlen($value) > 2000 ? false : $value;
    }
}
