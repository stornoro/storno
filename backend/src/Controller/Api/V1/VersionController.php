<?php

namespace App\Controller\Api\V1;

use App\Service\VersionGateService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class VersionController extends AbstractController
{
    public function __construct(
        private readonly string $projectDir,
        private readonly array $versionMetadata,
        private readonly VersionGateService $versionGate,
    ) {}

    #[Route('/api/v1/version', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $versionFile = $this->projectDir . '/VERSION.txt';
        $backendVersion = is_file($versionFile) ? trim(file_get_contents($versionFile)) : 'unknown';

        $payload = [
            'version' => $backendVersion,
            'web' => [
                'latest' => $backendVersion,
                'min' => $this->versionMetadata['web']['min'] ?? $backendVersion,
                'releaseNotes' => $this->versionMetadata['web']['releaseNotes'] ?? null,
            ],
            'mobile' => $this->versionMetadata['mobile'] ?? [],
        ];

        // Mobile clients pass ?platform=ios|android|huawei to get a flat
        // response tailored to their store URL. The optional &version=X.Y.Z
        // (also read from the X-App-Version header) lets the server compute
        // an upgrade tier so the client doesn't have to compare versions.
        $platform = $request->query->get('platform');
        if (is_string($platform) && isset($this->versionMetadata['mobile'][$platform])) {
            $clientVersion = $request->query->get('version')
                ?? $request->headers->get('X-App-Version');

            $gate = $this->versionGate->evaluate($platform, is_string($clientVersion) ? $clientVersion : null);

            $payload['platform'] = $platform;
            $payload['client'] = $this->versionMetadata['mobile'][$platform];
            if ($gate !== null) {
                $payload['gate'] = $gate;
            }
        }

        return $this->json($payload);
    }
}
