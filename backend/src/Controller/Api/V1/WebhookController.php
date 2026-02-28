<?php

namespace App\Controller\Api\V1;

use App\Entity\WebhookDelivery;
use App\Entity\WebhookEndpoint;
use App\Enum\WebhookDeliveryStatus;
use App\Repository\WebhookDeliveryRepository;
use App\Repository\WebhookEndpointRepository;
use App\Security\OrganizationContext;
use App\Service\LicenseManager;
use App\Service\Webhook\WebhookEventRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[Route('/api/v1/webhooks')]
#[IsGranted('ROLE_USER')]
class WebhookController extends AbstractController
{
    public function __construct(
        private readonly WebhookEndpointRepository $endpointRepository,
        private readonly WebhookDeliveryRepository $deliveryRepository,
        private readonly OrganizationContext $organizationContext,
        private readonly EntityManagerInterface $entityManager,
        private readonly HttpClientInterface $httpClient,
        private readonly LicenseManager $licenseManager,
    ) {}

    #[Route('/events', methods: ['GET'])]
    public function listEvents(): JsonResponse
    {
        return $this->json([
            'data' => WebhookEventRegistry::all(),
            'categories' => WebhookEventRegistry::getByCategory(),
        ]);
    }

    #[Route('', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany();
        if (!$company) {
            return $this->json(['error' => 'No company selected.'], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->organizationContext->hasPermission('webhook.view')) {
            return $this->json(['error' => 'Access denied.'], Response::HTTP_FORBIDDEN);
        }

        $endpoints = $this->endpointRepository->findByCompany($company);

        return $this->json([
            'data' => array_map(fn(WebhookEndpoint $e) => $this->serializeEndpoint($e), $endpoints),
        ]);
    }

    #[Route('', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany();
        if (!$company) {
            return $this->json(['error' => 'No company selected.'], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->organizationContext->hasPermission('webhook.manage')) {
            return $this->json(['error' => 'Access denied.'], Response::HTTP_FORBIDDEN);
        }

        $org = $company->getOrganization();
        if (!$this->licenseManager->canUseWebhooks($org)) {
            return $this->json([
                'error' => 'Webhooks are not available on your plan.',
                'code' => 'PLAN_LIMIT',
            ], Response::HTTP_PAYMENT_REQUIRED);
        }

        $data = json_decode($request->getContent(), true);

        $url = $data['url'] ?? null;
        if (!$url || !filter_var($url, FILTER_VALIDATE_URL) || !str_starts_with($url, 'https://')) {
            return $this->json(['error' => 'A valid HTTPS URL is required.'], Response::HTTP_BAD_REQUEST);
        }

        $events = $data['events'] ?? [];
        if (empty($events) || !is_array($events)) {
            return $this->json(['error' => 'At least one event type is required.'], Response::HTTP_BAD_REQUEST);
        }

        // Validate all events
        foreach ($events as $event) {
            if (!WebhookEventRegistry::isValidEvent($event)) {
                return $this->json(['error' => sprintf('Invalid event type: %s', $event)], Response::HTTP_BAD_REQUEST);
            }
        }

        $endpoint = new WebhookEndpoint();
        $endpoint->setCompany($company);
        $endpoint->setUrl($url);
        $endpoint->setEvents(array_values(array_unique($events)));
        $endpoint->setDescription($data['description'] ?? null);

        if (isset($data['isActive'])) {
            $endpoint->setIsActive((bool) $data['isActive']);
        }

        $this->entityManager->persist($endpoint);
        $this->entityManager->flush();

        return $this->json([
            'data' => $this->serializeEndpoint($endpoint, showSecret: true),
        ], Response::HTTP_CREATED);
    }

    #[Route('/{uuid}', methods: ['GET'])]
    public function show(string $uuid): JsonResponse
    {
        $endpoint = $this->resolveEndpoint($uuid);
        if ($endpoint instanceof JsonResponse) {
            return $endpoint;
        }

        return $this->json([
            'data' => $this->serializeEndpoint($endpoint),
        ]);
    }

    #[Route('/{uuid}', methods: ['PATCH'])]
    public function update(string $uuid, Request $request): JsonResponse
    {
        $endpoint = $this->resolveEndpoint($uuid, requireManage: true);
        if ($endpoint instanceof JsonResponse) {
            return $endpoint;
        }

        $data = json_decode($request->getContent(), true);

        if (isset($data['url'])) {
            if (!filter_var($data['url'], FILTER_VALIDATE_URL) || !str_starts_with($data['url'], 'https://')) {
                return $this->json(['error' => 'A valid HTTPS URL is required.'], Response::HTTP_BAD_REQUEST);
            }
            $endpoint->setUrl($data['url']);
        }

        if (isset($data['events'])) {
            if (empty($data['events']) || !is_array($data['events'])) {
                return $this->json(['error' => 'At least one event type is required.'], Response::HTTP_BAD_REQUEST);
            }
            foreach ($data['events'] as $event) {
                if (!WebhookEventRegistry::isValidEvent($event)) {
                    return $this->json(['error' => sprintf('Invalid event type: %s', $event)], Response::HTTP_BAD_REQUEST);
                }
            }
            $endpoint->setEvents(array_values(array_unique($data['events'])));
        }

        if (array_key_exists('description', $data)) {
            $endpoint->setDescription($data['description']);
        }

        if (isset($data['isActive'])) {
            $endpoint->setIsActive((bool) $data['isActive']);
        }

        $this->entityManager->flush();

        return $this->json([
            'data' => $this->serializeEndpoint($endpoint),
        ]);
    }

    #[Route('/{uuid}', methods: ['DELETE'])]
    public function delete(string $uuid): JsonResponse
    {
        $endpoint = $this->resolveEndpoint($uuid, requireManage: true);
        if ($endpoint instanceof JsonResponse) {
            return $endpoint;
        }

        $this->entityManager->remove($endpoint);
        $this->entityManager->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{uuid}/test', methods: ['POST'])]
    public function test(string $uuid): JsonResponse
    {
        $endpoint = $this->resolveEndpoint($uuid, requireManage: true);
        if ($endpoint instanceof JsonResponse) {
            return $endpoint;
        }

        $testPayload = [
            'id' => Uuid::v7()->toRfc4122(),
            'event' => 'webhook.test',
            'created_at' => (new \DateTimeImmutable())->format('c'),
            'data' => [
                'message' => 'This is a test webhook from Storno.ro.',
            ],
        ];

        $jsonPayload = json_encode($testPayload, JSON_UNESCAPED_UNICODE);
        $signature = hash_hmac('sha256', $jsonPayload, $endpoint->getSecret());

        $delivery = new WebhookDelivery();
        $delivery->setEndpoint($endpoint);
        $delivery->setEventType('webhook.test');
        $delivery->setPayload($testPayload);

        $startTime = hrtime(true);

        try {
            $response = $this->httpClient->request('POST', $endpoint->getUrl(), [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-Webhook-Signature' => $signature,
                    'X-Webhook-Event' => 'webhook.test',
                    'X-Webhook-Id' => $delivery->getId()->toRfc4122(),
                    'User-Agent' => 'Storno-Webhook/1.0',
                ],
                'body' => $jsonPayload,
                'timeout' => 10,
            ]);

            $statusCode = $response->getStatusCode();
            $responseBody = $response->getContent(false);
            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

            $delivery->setResponseStatusCode($statusCode);
            $delivery->setResponseBody($responseBody);
            $delivery->setDurationMs($durationMs);

            if ($statusCode >= 200 && $statusCode < 300) {
                $delivery->setStatus(WebhookDeliveryStatus::SUCCESS);
            } else {
                $delivery->setStatus(WebhookDeliveryStatus::FAILED);
                $delivery->setErrorMessage("HTTP $statusCode");
            }
            $delivery->setCompletedAt(new \DateTimeImmutable());
        } catch (\Throwable $e) {
            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);
            $delivery->setDurationMs($durationMs);
            $delivery->setStatus(WebhookDeliveryStatus::FAILED);
            $delivery->setErrorMessage(substr($e->getMessage(), 0, 500));
            $delivery->setCompletedAt(new \DateTimeImmutable());
        }

        $this->entityManager->persist($delivery);
        $this->entityManager->flush();

        return $this->json([
            'success' => $delivery->getStatus() === WebhookDeliveryStatus::SUCCESS,
            'statusCode' => $delivery->getResponseStatusCode(),
            'durationMs' => $delivery->getDurationMs(),
            'error' => $delivery->getErrorMessage(),
        ]);
    }

    #[Route('/{uuid}/regenerate-secret', methods: ['POST'])]
    public function regenerateSecret(string $uuid): JsonResponse
    {
        $endpoint = $this->resolveEndpoint($uuid, requireManage: true);
        if ($endpoint instanceof JsonResponse) {
            return $endpoint;
        }

        $newSecret = $endpoint->regenerateSecret();
        $this->entityManager->flush();

        return $this->json([
            'data' => $this->serializeEndpoint($endpoint, showSecret: true),
            'secret' => $newSecret,
        ]);
    }

    #[Route('/{uuid}/deliveries', methods: ['GET'])]
    public function listDeliveries(string $uuid, Request $request): JsonResponse
    {
        $endpoint = $this->resolveEndpoint($uuid);
        if ($endpoint instanceof JsonResponse) {
            return $endpoint;
        }

        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min(100, max(1, (int) $request->query->get('limit', 20)));

        $deliveries = $this->deliveryRepository->findByEndpointPaginated($endpoint, $page, $limit);
        $total = $this->deliveryRepository->countByEndpoint($endpoint);

        return $this->json([
            'data' => array_map(fn(WebhookDelivery $d) => $this->serializeDeliveryList($d), $deliveries),
            'meta' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
            ],
        ]);
    }

    #[Route('/{uuid}/deliveries/{deliveryUuid}', methods: ['GET'])]
    public function showDelivery(string $uuid, string $deliveryUuid): JsonResponse
    {
        $endpoint = $this->resolveEndpoint($uuid);
        if ($endpoint instanceof JsonResponse) {
            return $endpoint;
        }

        $delivery = $this->deliveryRepository->find(Uuid::fromString($deliveryUuid));
        if (!$delivery || $delivery->getEndpoint()->getId()->toRfc4122() !== $endpoint->getId()->toRfc4122()) {
            return $this->json(['error' => 'Delivery not found.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'data' => $this->serializeDeliveryDetail($delivery),
        ]);
    }

    // --- Private helpers ---

    private function resolveEndpoint(string $uuid, bool $requireManage = false): WebhookEndpoint|JsonResponse
    {
        $company = $this->organizationContext->resolveCompany();
        if (!$company) {
            return $this->json(['error' => 'No company selected.'], Response::HTTP_BAD_REQUEST);
        }

        $permission = $requireManage ? 'webhook.manage' : 'webhook.view';
        if (!$this->organizationContext->hasPermission($permission)) {
            return $this->json(['error' => 'Access denied.'], Response::HTTP_FORBIDDEN);
        }

        $endpoint = $this->endpointRepository->find(Uuid::fromString($uuid));
        if (!$endpoint || $endpoint->getCompany()->getId()->toRfc4122() !== $company->getId()->toRfc4122()) {
            return $this->json(['error' => 'Webhook endpoint not found.'], Response::HTTP_NOT_FOUND);
        }

        return $endpoint;
    }

    private function serializeEndpoint(WebhookEndpoint $endpoint, bool $showSecret = false): array
    {
        return [
            'id' => $endpoint->getId()->toRfc4122(),
            'url' => $endpoint->getUrl(),
            'description' => $endpoint->getDescription(),
            'events' => $endpoint->getEvents(),
            'secret' => $showSecret ? $endpoint->getSecret() : $endpoint->getMaskedSecret(),
            'isActive' => $endpoint->isActive(),
            'createdAt' => $endpoint->getCreatedAt()?->format('c'),
            'updatedAt' => $endpoint->getUpdatedAt()?->format('c'),
        ];
    }

    private function serializeDeliveryList(WebhookDelivery $delivery): array
    {
        return [
            'id' => $delivery->getId()->toRfc4122(),
            'eventType' => $delivery->getEventType(),
            'status' => $delivery->getStatus()->value,
            'responseStatusCode' => $delivery->getResponseStatusCode(),
            'durationMs' => $delivery->getDurationMs(),
            'attempt' => $delivery->getAttempt(),
            'errorMessage' => $delivery->getErrorMessage(),
            'triggeredAt' => $delivery->getTriggeredAt()?->format('c'),
            'completedAt' => $delivery->getCompletedAt()?->format('c'),
        ];
    }

    private function serializeDeliveryDetail(WebhookDelivery $delivery): array
    {
        return [
            'id' => $delivery->getId()->toRfc4122(),
            'eventType' => $delivery->getEventType(),
            'status' => $delivery->getStatus()->value,
            'payload' => $delivery->getPayload(),
            'responseStatusCode' => $delivery->getResponseStatusCode(),
            'responseBody' => $delivery->getResponseBody(),
            'durationMs' => $delivery->getDurationMs(),
            'attempt' => $delivery->getAttempt(),
            'errorMessage' => $delivery->getErrorMessage(),
            'triggeredAt' => $delivery->getTriggeredAt()?->format('c'),
            'completedAt' => $delivery->getCompletedAt()?->format('c'),
            'nextRetryAt' => $delivery->getNextRetryAt()?->format('c'),
        ];
    }
}
