<?php

namespace App\Controller\Api\V1;

use App\Repository\OrganizationRepository;
use App\Service\LicenseManager;
use App\Service\LicenseValidationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class PublicPlansController extends AbstractController
{
    public function __construct(
        private readonly OrganizationRepository $organizationRepository,
        private readonly LicenseValidationService $licenseValidationService,
        private readonly string $earlyAdopterCouponId = '',
    ) {}

    #[Route('/api/v1/plans', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $pricing = LicenseManager::getPlanPricing();
        $displayFeatures = LicenseManager::getPlanDisplayFeatures();

        // Only expose these plans on the public pricing page
        $visiblePlans = [LicenseManager::PLAN_FREEMIUM, LicenseManager::PLAN_STARTER, LicenseManager::PLAN_PROFESSIONAL, LicenseManager::PLAN_BUSINESS];

        $plans = [];
        foreach (array_keys($pricing) as $key) {
            if (!\in_array($key, $visiblePlans, true)) {
                continue;
            }
            $display = $displayFeatures[$key] ?? null;

            $plan = [
                'key' => $key,
                'monthlyPrice' => $pricing[$key]['monthlyPrice'],
                'yearlyPrice' => $pricing[$key]['yearlyPrice'],
                'currency' => $pricing[$key]['currency'],
                'features' => $display ? $display['features'] : [],
                'includesPlan' => $display ? $display['includesPlan'] : null,
            ];

            if (isset($pricing[$key]['trialDays'])) {
                $plan['trialDays'] = $pricing[$key]['trialDays'];
            }

            if (isset($pricing[$key]['selfHostedOnly'])) {
                $plan['selfHostedOnly'] = $pricing[$key]['selfHostedOnly'];
            }

            if (isset($pricing[$key]['savingsPercent'])) {
                $plan['savingsPercent'] = $pricing[$key]['savingsPercent'];
            }

            if (isset($pricing[$key]['includedSeats'])) {
                $plan['includedSeats'] = $pricing[$key]['includedSeats'];
                $plan['extraSeatMonthlyPrice'] = $pricing[$key]['extraSeatMonthlyPrice'];
                $plan['extraSeatYearlyPrice'] = $pricing[$key]['extraSeatYearlyPrice'];
            }

            $plans[] = $plan;
        }

        $earlyAdopterAvailable = !$this->licenseValidationService->isSelfHosted()
            && !empty($this->earlyAdopterCouponId)
            && $this->organizationRepository->countEarlyAdopters() < 100;

        $response = $this->json([
            'plans' => $plans,
            'earlyAdopterAvailable' => $earlyAdopterAvailable,
        ]);
        $response->setMaxAge(300);
        $response->setPublic();

        return $response;
    }
}
