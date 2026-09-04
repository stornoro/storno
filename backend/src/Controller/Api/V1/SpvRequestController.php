<?php

namespace App\Controller\Api\V1;

use App\Constants\Pagination;
use App\Entity\SpvRequest;
use App\Entity\User;
use App\Repository\SpvRequestRepository;
use App\Security\OrganizationContext;
use App\Security\Permission;
use App\Service\Spv\SpvRequestCatalog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Requests to ANAF SPV ("solicitări": reports, copies of filed declarations,
 * duplicate receipts, certificates). The certificate lives on the user's
 * machine, so the flow mirrors the inbox sync:
 *
 *   1. POST /spv/requests/prepare {type, params}  → Storno validates and returns the ANAF URL
 *   2. the agent GETs that URL with the qualified certificate
 *   3. POST /spv/requests/{uuid}/agent-result {statusCode, body} → Storno records id_solicitare
 *   4. the answer arrives in listaMesaje with the same id_solicitare; the next
 *      inbox sync archives it and links it to the request.
 */
#[Route('/api/v1/spv/requests')]
class SpvRequestController extends AbstractController
{
    public function __construct(
        private readonly OrganizationContext $organizationContext,
        private readonly EntityManagerInterface $entityManager,
        private readonly SpvRequestRepository $repository,
        private readonly SpvRequestCatalog $catalog,
    ) {}

    #[Route('', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->organizationContext->hasPermission(Permission::DECLARATION_VIEW)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $page = max(1, $request->query->getInt('page', 1));
        $limit = min(Pagination::MAX_LIMIT, max(1, $request->query->getInt('limit', Pagination::DEFAULT_LIMIT)));
        $status = $request->query->get('status');
        $result = $this->repository->paginate($company, $page, $limit, is_string($status) ? $status : null);

        return $this->json([
            'data' => $result['items'],
            'total' => $result['total'],
            'page' => $page,
            'limit' => $limit,
        ], context: ['groups' => ['spv_request:list']]);
    }

    /** Catalog of request types with their parameters, notes and the reasons accepted for income certificates. */
    #[Route('/types', methods: ['GET'])]
    public function types(): JsonResponse
    {
        return $this->json([
            'types' => $this->catalog->types(),
            'incomeCertificateReasons' => $this->catalog->incomeCertificateReasons(),
        ]);
    }

    #[Route('/prepare', methods: ['POST'])]
    public function prepare(Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->organizationContext->hasPermission(Permission::DECLARATION_SUBMIT)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true) ?: [];
        $type = trim((string) ($data['type'] ?? ''));
        $params = is_array($data['params'] ?? null) ? $data['params'] : [];

        try {
            $built = $this->catalog->buildRequest($type, (string) $company->getCif(), $params);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage(), 'code' => 'INVALID_REQUEST'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $spvRequest = (new SpvRequest())
            ->setCompany($company)
            ->setRequestType($type)
            ->setParams($built['params'])
            ->setStatus(SpvRequest::STATUS_PENDING);
        $user = $this->getUser();
        if ($user instanceof User) {
            $spvRequest->setRequestedBy($user);
        }
        $this->entityManager->persist($spvRequest);
        $this->entityManager->flush();

        return $this->json([
            'requestId' => $spvRequest->getId()?->toRfc4122(),
            'anafUrl' => $built['url'],
            'type' => $type,
            'params' => $built['params'],
            'cif' => (string) $company->getCif(),
        ]);
    }

    /** The agent relays ANAF's answer to `cerere`: `{id_solicitare, titlu}` or `{eroare, titlu}`. */
    #[Route('/{uuid}/agent-result', methods: ['POST'])]
    public function agentResult(string $uuid, Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->organizationContext->hasPermission(Permission::DECLARATION_SUBMIT)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }
        if (!Uuid::isValid($uuid)) {
            return $this->json(['error' => 'Invalid id.'], Response::HTTP_BAD_REQUEST);
        }
        $spvRequest = $this->repository->find(Uuid::fromString($uuid));
        if ($spvRequest === null || $spvRequest->getCompany()?->getId()?->toRfc4122() !== $company->getId()?->toRfc4122()) {
            return $this->json(['error' => 'Request not found.'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true) ?: [];
        $statusCode = (int) ($data['statusCode'] ?? 200);
        $body = is_string($data['body'] ?? null) ? $data['body'] : '';
        $parsed = json_decode($body, true);

        if ($statusCode >= 400 || !is_array($parsed)) {
            $hint = mb_substr(trim(strip_tags($body)), 0, 300);
            $message = $statusCode >= 400
                ? sprintf('ANAF a raspuns HTTP %d.', $statusCode)
                : 'Raspunsul ANAF nu a putut fi interpretat. Sesiunea SPV a expirat sau certificatul nu are drepturi pe acest CUI.';
            $spvRequest->setStatus(SpvRequest::STATUS_ERROR)->setErrorMessage(trim($message . ' ' . $hint));
            $this->entityManager->flush();

            return $this->json($spvRequest, Response::HTTP_BAD_GATEWAY, context: ['groups' => ['spv_request:list']]);
        }

        if (isset($parsed['eroare'])) {
            $spvRequest->setStatus(SpvRequest::STATUS_ERROR)
                ->setErrorMessage((string) $parsed['eroare'])
                ->setTitle(isset($parsed['titlu']) ? (string) $parsed['titlu'] : null);
            $this->entityManager->flush();

            return $this->json($spvRequest, Response::HTTP_UNPROCESSABLE_ENTITY, context: ['groups' => ['spv_request:list']]);
        }

        $spvRequest->setAnafRequestId(isset($parsed['id_solicitare']) ? (string) $parsed['id_solicitare'] : null)
            ->setTitle(isset($parsed['titlu']) ? (string) $parsed['titlu'] : null)
            ->setStatus($spvRequest->getAnafRequestId() ? SpvRequest::STATUS_REQUESTED : SpvRequest::STATUS_ERROR)
            ->setErrorMessage($spvRequest->getAnafRequestId() ? null : 'ANAF nu a returnat id_solicitare.');
        $this->entityManager->flush();

        return $this->json($spvRequest, context: ['groups' => ['spv_request:list']]);
    }

    #[Route('/{uuid}', methods: ['DELETE'])]
    public function delete(string $uuid, Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->organizationContext->hasPermission(Permission::DECLARATION_SUBMIT)) {
            return $this->json(['error' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
        }
        if (!Uuid::isValid($uuid)) {
            return $this->json(['error' => 'Invalid id.'], Response::HTTP_BAD_REQUEST);
        }
        $spvRequest = $this->repository->find(Uuid::fromString($uuid));
        if ($spvRequest === null || $spvRequest->getCompany()?->getId()?->toRfc4122() !== $company->getId()?->toRfc4122()) {
            return $this->json(['error' => 'Request not found.'], Response::HTTP_NOT_FOUND);
        }
        $this->entityManager->remove($spvRequest);
        $this->entityManager->flush();

        return $this->json(['deleted' => true]);
    }
}
