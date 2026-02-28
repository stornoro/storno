<?php

namespace App\Controller\Api\V1;

use App\Service\ExchangeRateService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/exchange-rates')]
class ExchangeRateController extends AbstractController
{
    public function __construct(
        private readonly ExchangeRateService $exchangeRateService,
    ) {}

    /**
     * Get all BNR exchange rates.
     */
    #[Route('', methods: ['GET'])]
    public function index(): JsonResponse
    {
        try {
            $data = $this->exchangeRateService->getRates();

            // Flatten for easier consumption: { EUR: 4.9750, USD: 4.5600, ... }
            $flat = [];
            foreach ($data['rates'] as $currency => $info) {
                $flat[$currency] = round($info['value'] / $info['multiplier'], 4);
            }

            return $this->json([
                'date' => $data['date'],
                'base' => 'RON',
                'rates' => $flat,
            ]);
        } catch (\Throwable $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    /**
     * Convert an amount between currencies.
     * Query params: amount, from, to
     */
    #[Route('/convert', methods: ['GET'])]
    public function convert(Request $request): JsonResponse
    {
        $amount = (float) $request->query->get('amount', '0');
        $from = strtoupper($request->query->get('from', 'EUR'));
        $to = strtoupper($request->query->get('to', 'RON'));

        if ($amount <= 0) {
            return $this->json(['error' => 'Amount must be positive.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $result = $this->exchangeRateService->convert($amount, $from, $to);

            return $this->json($result);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }
}
