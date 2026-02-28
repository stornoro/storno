<?php

namespace App\Controller\Api\V1;

use App\Entity\VatRate;
use App\Repository\VatRateRepository;
use App\Security\OrganizationContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1')]
class VatRateController extends AbstractController
{
    public function __construct(
        private readonly VatRateRepository $vatRateRepository,
        private readonly OrganizationContext $organizationContext,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    #[Route('/vat-rates', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        $rates = $this->vatRateRepository->findByCompany($company);

        return $this->json(['data' => $rates], context: ['groups' => ['vat_rate:list']]);
    }

    #[Route('/vat-rates', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);

        if (!isset($data['rate']) || !isset($data['label'])) {
            return $this->json(['error' => 'Fields "rate" and "label" are required.'], Response::HTTP_BAD_REQUEST);
        }

        $vatRate = new VatRate();
        $vatRate->setCompany($company);
        $vatRate->setRate($data['rate']);
        $vatRate->setLabel($data['label']);
        $vatRate->setCategoryCode($data['categoryCode'] ?? 'S');
        $vatRate->setIsDefault($data['isDefault'] ?? false);
        $vatRate->setIsActive($data['isActive'] ?? true);
        $vatRate->setPosition($data['position'] ?? 0);
        $vatRate->setCreatedAt(new \DateTimeImmutable());

        if ($vatRate->isDefault()) {
            $this->unsetOtherDefaults($company);
        }

        $this->entityManager->persist($vatRate);
        $this->entityManager->flush();

        return $this->json($vatRate, Response::HTTP_CREATED, context: ['groups' => ['vat_rate:detail']]);
    }

    #[Route('/vat-rates/{uuid}', methods: ['PATCH'])]
    public function update(string $uuid, Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        $vatRate = $this->vatRateRepository->find($uuid);
        if (!$vatRate || $vatRate->getCompany()->getId()->toRfc4122() !== $company->getId()->toRfc4122()) {
            return $this->json(['error' => 'VAT rate not found.'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);

        if (isset($data['rate'])) {
            $vatRate->setRate($data['rate']);
        }
        if (isset($data['label'])) {
            $vatRate->setLabel($data['label']);
        }
        if (isset($data['categoryCode'])) {
            $vatRate->setCategoryCode($data['categoryCode']);
        }
        if (isset($data['isDefault'])) {
            $vatRate->setIsDefault((bool) $data['isDefault']);
            if ($vatRate->isDefault()) {
                $this->unsetOtherDefaults($company, $vatRate);
            }
        }
        if (isset($data['isActive'])) {
            $vatRate->setIsActive((bool) $data['isActive']);
        }
        if (isset($data['position'])) {
            $vatRate->setPosition((int) $data['position']);
        }

        $vatRate->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        return $this->json($vatRate, context: ['groups' => ['vat_rate:detail']]);
    }

    #[Route('/vat-rates/{uuid}', methods: ['DELETE'])]
    public function delete(string $uuid, Request $request): JsonResponse
    {
        $company = $this->organizationContext->resolveCompany($request);
        if (!$company) {
            return $this->json(['error' => 'Company not found.'], Response::HTTP_NOT_FOUND);
        }

        $vatRate = $this->vatRateRepository->find($uuid);
        if (!$vatRate || $vatRate->getCompany()->getId()->toRfc4122() !== $company->getId()->toRfc4122()) {
            return $this->json(['error' => 'VAT rate not found.'], Response::HTTP_NOT_FOUND);
        }

        $vatRate->softDelete();
        $this->entityManager->flush();

        return $this->json(['message' => 'VAT rate deleted.']);
    }

    private function unsetOtherDefaults(\App\Entity\Company $company, ?VatRate $except = null): void
    {
        $rates = $this->vatRateRepository->findByCompany($company);
        foreach ($rates as $rate) {
            if ($except && $rate->getId()?->equals($except->getId())) {
                continue;
            }
            if ($rate->isDefault()) {
                $rate->setIsDefault(false);
            }
        }
    }
}
