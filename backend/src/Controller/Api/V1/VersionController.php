<?php

namespace App\Controller\Api\V1;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class VersionController extends AbstractController
{
    public function __construct(
        private readonly string $projectDir,
        private readonly array $versionMetadata,
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
        // response tailored to their store URL — saves them the conditional.
        $platform = $request->query->get('platform');
        if (is_string($platform) && isset($this->versionMetadata['mobile'][$platform])) {
            $payload['platform'] = $platform;
            $payload['client'] = $this->versionMetadata['mobile'][$platform];
        }

        return $this->json($payload);
    }
}
