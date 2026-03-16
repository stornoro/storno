<?php

namespace App\Controller\Api\V1;

use App\Entity\AnafToken;
use App\Entity\AnafTokenLink;
use App\Entity\User;
use App\Events\Anaf\TokenCreatedEvent;
use App\Events\Anaf\TokenDeletedEvent;
use App\Exception\AnafRateLimitException;
use App\Repository\AnafTokenLinkRepository;
use App\Repository\AnafTokenRepository;
use App\Repository\CompanyRepository;
use App\Security\OrganizationContext;
use App\Service\Anaf\EFacturaClient;
use App\Service\LicenseManager;
use App\Services\AnafService;
use App\Service\Webhook\WebhookDispatcher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

#[Route('/api/v1/anaf')]
#[IsGranted('ROLE_USER')]
class AnafController extends AbstractController
{
    public function __construct(
        private readonly AnafTokenRepository $anafTokenRepository,
        private readonly AnafTokenLinkRepository $anafTokenLinkRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly EFacturaClient $eFacturaClient,
        private readonly CompanyRepository $companyRepository,
        private readonly OrganizationContext $organizationContext,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly AnafService $anafService,
        private readonly LicenseManager $licenseManager,
        private readonly WebhookDispatcher $webhookDispatcher,
        private readonly CacheInterface $cache,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%env(FRONTEND_URL)%')]
        private readonly string $frontendUrl,
    ) {}

    #[Route('/tokens', methods: ['GET'])]
    public function listTokens(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $tokens = $this->anafTokenRepository->findByUser($user);

        return $this->json([
            'data' => array_map(fn(AnafToken $t) => $this->serializeToken($t), $tokens),
        ]);
    }

    #[Route('/tokens', methods: ['POST'])]
    public function saveToken(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $data = json_decode($request->getContent(), true);

        $tokenValue = $data['token'] ?? null;
        if (!$tokenValue || strlen(trim($tokenValue)) < 10) {
            return $this->json(['error' => 'Token-ul este obligatoriu si trebuie sa aiba minim 10 caractere.'], Response::HTTP_BAD_REQUEST);
        }

        $tokenValue = trim($tokenValue);
        $label = $data['label'] ?? null;

        // Try to decode JWT and extract expiration
        $expiresAt = $this->extractJwtExpiry($tokenValue);
        if (!$expiresAt) {
            $expiresInDays = (int) ($data['expiresInDays'] ?? 90);
            $expiresInDays = max(1, min(365, $expiresInDays));
            $expiresAt = new \DateTimeImmutable(sprintf('+%d days', $expiresInDays));
        }

        // Validate against ANAF if a company is available
        $companyId = $data['companyId'] ?? null;
        $company = null;

        if ($companyId) {
            $company = $this->companyRepository->find(Uuid::fromString($companyId));
            if ($company && !$this->companyBelongsToUser($user, $company)) {
                return $this->json(['error' => 'Compania nu apartine organizatiei dumneavoastra.'], Response::HTTP_FORBIDDEN);
            }
        } else {
            $company = $this->resolveCompany($request);
        }

        if ($company) {
            try {
                $validation = $this->eFacturaClient->validateToken((string) $company->getCif(), $tokenValue);
                if (!$validation['valid']) {
                    return $this->json([
                        'error' => $validation['error'] ?? 'Token-ul nu are acces la CIF-ul ' . $company->getCif(),
                    ], Response::HTTP_UNPROCESSABLE_ENTITY);
                }
            } catch (AnafRateLimitException $e) {
                return $this->json([
                    'error' => 'Limita de apeluri ANAF a fost atinsa. Incercati din nou mai tarziu.',
                    'retryAfter' => $e->retryAfter,
                ], Response::HTTP_TOO_MANY_REQUESTS, [
                    'Retry-After' => $e->retryAfter,
                ]);
            } catch (\Throwable $e) {
                return $this->json([
                    'error' => 'Nu s-a putut valida token-ul cu ANAF. Verificati token-ul si incercati din nou.',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        $anafToken = new AnafToken();
        $anafToken
            ->setToken($tokenValue)
            ->setExpireAt($expiresAt)
            ->setLabel($label);

        if ($company) {
            $anafToken->addValidatedCif((int) $company->getCif());
        }

        $user->addAnafToken($anafToken);
        $this->entityManager->persist($anafToken);
        $this->entityManager->flush();

        $this->eventDispatcher->dispatch(new TokenCreatedEvent($user, $anafToken), TokenCreatedEvent::NAME);

        if ($company) {
            $this->webhookDispatcher->dispatchForCompany($company, 'anaf.token_created', [
                'tokenId' => $anafToken->getId()->toRfc4122(),
                'companyId' => $company->getId()->toRfc4122(),
            ]);
        }

        return $this->json([
            'status' => 'ok',
            'message' => 'Token validat si salvat cu succes.',
            'data' => $this->serializeToken($anafToken),
        ], Response::HTTP_CREATED);
    }

    #[Route('/tokens/{id}', methods: ['DELETE'])]
    public function deleteToken(string $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $anafToken = $this->anafTokenRepository->find(Uuid::fromString($id));

        if (!$anafToken || $anafToken->getUser() !== $user) {
            return $this->json(['error' => 'Token not found.'], Response::HTTP_NOT_FOUND);
        }

        $user->removeAnafToken($anafToken);
        $this->entityManager->remove($anafToken);
        $this->entityManager->flush();

        $this->eventDispatcher->dispatch(new TokenDeletedEvent($user), TokenDeletedEvent::NAME);

        return $this->json(['status' => 'ok', 'message' => 'Token sters cu succes.']);
    }

    #[Route('/status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $tokens = $this->anafTokenRepository->findByUser($user);

        if (empty($tokens)) {
            return $this->json([
                'connected' => false,
                'hasToken' => false,
                'tokenCount' => 0,
            ]);
        }

        $hasValid = false;
        $nearestExpiry = null;

        foreach ($tokens as $token) {
            if (!$token->isExpired()) {
                $hasValid = true;
            }
            $expireAt = $token->getExpireAt();
            if ($expireAt && ($nearestExpiry === null || $expireAt < $nearestExpiry)) {
                $nearestExpiry = $expireAt;
            }
        }

        return $this->json([
            'connected' => $hasValid,
            'hasToken' => true,
            'tokenCount' => count($tokens),
            'nearestExpiry' => $nearestExpiry?->format('c'),
            'hasExpiredTokens' => count(array_filter($tokens, fn(AnafToken $t) => $t->isExpired())) > 0,
        ]);
    }

    #[Route('/tokens/{id}/validate-cif', methods: ['POST'])]
    public function validateCif(string $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $anafToken = $this->anafTokenRepository->find(Uuid::fromString($id));

        if (!$anafToken || $anafToken->getUser() !== $user) {
            return $this->json(['error' => 'Token not found.'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);
        $cif = $data['cif'] ?? null;

        if (!$cif) {
            return $this->json(['error' => 'CIF is required.'], Response::HTTP_BAD_REQUEST);
        }

        $org = $this->organizationContext->getOrganization();
        if (!$org) {
            return $this->json(['error' => 'No organization found.'], Response::HTTP_NOT_FOUND);
        }

        // Step 0: Verify the CIF belongs to a company in the user's organization
        $existingCompany = $this->companyRepository->findByOrganizationAndCif($org, (int) $cif);

        if (!$existingCompany) {
            // CIF not in org — check if plan limit prevents adding more companies
            if (!$this->licenseManager->canAddCompany($org)) {
                return $this->json([
                    'error' => 'Limita de companii atinsa. Upgradati planul pentru a adauga CIF-ul ' . $cif . '.',
                    'code' => 'PLAN_LIMIT',
                ], Response::HTTP_PAYMENT_REQUIRED);
            }

            return $this->json([
                'valid' => false,
                'cif' => (int) $cif,
                'error' => 'CIF-ul ' . $cif . ' nu apartine niciunei companii din organizatia dumneavoastra. Adaugati compania mai intai.',
                'code' => 'COMPANY_NOT_FOUND',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Step 1: Verify the CIF is a real, registered company via ANAF public API
        try {
            $companyInfo = $this->anafService->findCompany((string) $cif);
        } catch (\Throwable) {
            $companyInfo = null;
        }

        if (!$companyInfo) {
            $anafToken->removeValidatedCif((int) $cif);
            $this->entityManager->flush();

            return $this->json([
                'valid' => false,
                'cif' => (int) $cif,
                'error' => 'CIF-ul ' . $cif . ' nu exista in baza de date ANAF.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Step 2: Validate the token has e-Factura access for this CIF
        try {
            $validation = $this->eFacturaClient->validateToken((string) $cif, $anafToken->getToken());

            if (!$validation['valid']) {
                $anafToken->removeValidatedCif((int) $cif);
                $this->entityManager->flush();

                return $this->json([
                    'valid' => false,
                    'cif' => (int) $cif,
                    'error' => $validation['error'] ?? 'Token-ul nu are acces la CIF-ul ' . $cif,
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $anafToken->addValidatedCif((int) $cif);
            $this->entityManager->flush();

            return $this->json([
                'valid' => true,
                'cif' => (int) $cif,
                'companyName' => $companyInfo->getName(),
                'message' => 'Token-ul este valid pentru ' . $companyInfo->getName() . ' (CIF ' . $cif . ')',
            ]);
        } catch (AnafRateLimitException $e) {
            return $this->json([
                'error' => 'Limita de apeluri ANAF a fost atinsa.',
                'retryAfter' => $e->retryAfter,
            ], Response::HTTP_TOO_MANY_REQUESTS);
        } catch (\Throwable $e) {
            return $this->json([
                'valid' => false,
                'cif' => (int) $cif,
                'error' => 'Nu s-a putut valida token-ul pentru CIF-ul ' . $cif . '. Incercati din nou.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    #[Route('/token-links', methods: ['GET'])]
    public function listTokenLinks(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $links = $this->anafTokenLinkRepository->findActiveByUser($user);

        return $this->json([
            'data' => array_map(fn(AnafTokenLink $l) => [
                'linkToken' => $l->getToken(),
                'linkUrl' => rtrim($this->frontendUrl, '/') . '/anaf/link/' . $l->getToken(),
                'companyId' => $l->getCompany()?->getId()?->toRfc4122(),
                'expiresAt' => $l->getExpiresAt()->format('c'),
                'createdAt' => $l->getCreatedAt()->format('c'),
            ], $links),
        ]);
    }

    #[Route('/token-links', methods: ['POST'])]
    public function createTokenLink(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        // Rate limit: max 5 active (unused + not expired) links per user
        $activeLinks = $this->anafTokenLinkRepository->countActiveByUser($user);
        if ($activeLinks >= 5) {
            return $this->json([
                'error' => 'Aveti deja prea multe link-uri active. Asteptati sa expire sau stergeti-le.',
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $companyId = $data['companyId'] ?? $request->headers->get('X-Company');

        $link = new AnafTokenLink();
        $link->setUser($user);

        if ($companyId) {
            $company = $this->companyRepository->find(Uuid::fromString($companyId));
            // Verify the company belongs to the user's organization
            if ($company && $this->companyBelongsToUser($user, $company)) {
                $link->setCompany($company);
            }
        }

        $this->entityManager->persist($link);
        $this->entityManager->flush();

        $linkUrl = rtrim($this->frontendUrl, '/') . '/anaf/link/' . $link->getToken();

        return $this->json([
            'linkToken' => $link->getToken(),
            'linkUrl' => $linkUrl,
            'expiresAt' => $link->getExpiresAt()->format('c'),
        ], Response::HTTP_CREATED);
    }

    #[Route('/vat-status/{cif}', methods: ['GET'])]
    public function vatStatus(string $cif): JsonResponse
    {
        $cif = preg_replace('/\D/', '', $cif);
        if (!$cif) {
            return $this->json(['error' => 'CIF invalid.'], Response::HTTP_BAD_REQUEST);
        }

        $cacheKey = 'anaf_vat_' . $cif . '_' . date('Y-m-d');

        $result = $this->cache->get($cacheKey, function (ItemInterface $item) use ($cif): ?array {
            $item->expiresAfter(86400);

            try {
                $info = $this->anafService->findCompany($cif);
            } catch (\Throwable) {
                return null;
            }

            if (!$info) {
                return null;
            }

            return [
                'vatPayer' => $info->isVatPayer(),
                'vatOnCollection' => $info->isVatOnCollection(),
                'name' => $info->getName(),
            ];
        });

        if ($result === null) {
            return $this->json(['error' => 'CIF-ul nu a fost gasit in baza de date ANAF.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($result);
    }

    private function serializeToken(AnafToken $token): array
    {
        return [
            'id' => (string) $token->getId(),
            'label' => $token->getLabel(),
            'expiresAt' => $token->getExpireAt()?->format('c'),
            'isExpired' => $token->isExpired(),
            'lastUsedAt' => $token->getLastUsedAt()?->format('c'),
            'createdAt' => $token->getCreatedAt()?->format('c'),
            'validatedCifs' => $token->getValidatedCifs() ?? [],
        ];
    }

    private function extractJwtExpiry(string $token): ?\DateTimeImmutable
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        $payload = base64_decode(strtr($parts[1], '-_', '+/'));
        if (!$payload) {
            return null;
        }

        $data = json_decode($payload, true);
        if (!is_array($data) || !isset($data['exp'])) {
            return null;
        }

        $exp = (int) $data['exp'];
        if ($exp <= 0) {
            return null;
        }

        return \DateTimeImmutable::createFromFormat('U', (string) $exp) ?: null;
    }

    private function companyBelongsToUser(User $user, \App\Entity\Company $company): bool
    {
        foreach ($user->getOrganizationMemberships() as $membership) {
            $org = $membership->getOrganization();
            if (!$org) continue;
            foreach ($org->getCompanies() as $orgCompany) {
                if ($orgCompany->getId()->equals($company->getId())) {
                    return true;
                }
            }
        }
        return false;
    }

    private function resolveCompany(Request $request): ?\App\Entity\Company
    {
        $companyId = $request->query->get('company') ?? $request->headers->get('X-Company');
        if ($companyId) {
            return $this->companyRepository->find(Uuid::fromString($companyId));
        }

        $org = $this->organizationContext->getOrganization();
        if ($org) {
            $companies = $org->getCompanies();
            if ($companies->count() === 1) {
                return $companies->first();
            }
        }

        return null;
    }
}
