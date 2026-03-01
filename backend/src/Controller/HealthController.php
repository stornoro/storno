<?php

namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class HealthController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CacheInterface $cache,
        private readonly HttpClientInterface $httpClient,
        private readonly string $centrifugoApiUrl,
        private readonly string $centrifugoWsUrl,
        private readonly string $centrifugoApiKey,
    ) {}

    #[Route('/', methods: ['GET'])]
    public function root(): JsonResponse
    {
        return $this->json(['name' => 'Storno API', 'status' => 'ok']);
    }

    #[Route('/health', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $checks = [];
        $healthy = true;

        // Database check
        try {
            $this->entityManager->getConnection()->executeQuery('SELECT 1');
            $checks['database'] = 'ok';
        } catch (\Throwable $e) {
            $checks['database'] = 'error: ' . $e->getMessage();
            $healthy = false;
        }

        // Cache check
        try {
            $this->cache->get('health_check', fn () => 'ok');
            $checks['cache'] = 'ok';
        } catch (\Throwable $e) {
            $checks['cache'] = 'error: ' . $e->getMessage();
            $healthy = false;
        }

        // Centrifugo check
        try {
            $response = $this->httpClient->request('POST', $this->centrifugoApiUrl . '/info', [
                'headers' => [
                    'X-API-Key' => $this->centrifugoApiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => new \stdClass(),
                'timeout' => 3,
            ]);
            $info = $response->toArray();
            $checks['centrifugo'] = 'ok';
            $checks['centrifugo_clients'] = $info['result']['nodes'][0]['num_clients'] ?? 0;
        } catch (\Throwable $e) {
            $checks['centrifugo'] = 'error: ' . $e->getMessage();
            $healthy = false;
        }

        return $this->json([
            'status' => $healthy ? 'healthy' : 'degraded',
            'checks' => $checks,
            'websocket' => $this->centrifugoWsUrl,
            'timestamp' => (new \DateTimeImmutable())->format('c'),
        ], $healthy ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE);
    }
}
