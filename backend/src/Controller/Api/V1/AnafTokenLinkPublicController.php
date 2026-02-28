<?php

namespace App\Controller\Api\V1;

use App\Repository\AnafTokenLinkRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/anaf')]
class AnafTokenLinkPublicController extends AbstractController
{
    #[Route('/token-links/{linkToken}', methods: ['GET'])]
    public function checkTokenLink(string $linkToken, AnafTokenLinkRepository $linkRepository): JsonResponse
    {
        $link = $linkRepository->findOneBy(['token' => $linkToken]);

        if (!$link) {
            return $this->json(['error' => 'Link not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        return $this->json([
            'expired' => $link->isExpired(),
            'used' => $link->isUsed(),
            'completed' => $link->getAnafToken() !== null,
            'expiresAt' => $link->getExpiresAt()->format('c'),
        ]);
    }
}
