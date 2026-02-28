<?php

namespace App\Controller\Api\V1;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class VersionController extends AbstractController
{
    public function __construct(
        private readonly string $projectDir,
    ) {}

    #[Route('/api/v1/version', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $versionFile = $this->projectDir . '/VERSION.txt';
        $version = is_file($versionFile) ? trim(file_get_contents($versionFile)) : 'unknown';

        return $this->json([
            'version' => $version,
            'releaseDate' => '2026-02-20',
            'minimumPhpVersion' => '8.2',
            'downloadUrl' => 'https://github.com/stornoro/stornoro/releases/latest',
            'changelog' => 'https://github.com/stornoro/stornoro/releases',
        ]);
    }
}
