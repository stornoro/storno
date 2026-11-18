<?php

namespace App\Service\Anaf;

use App\Entity\AnafToken;
use App\Entity\Company;
use App\Repository\AnafTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class AnafTokenResolver
{
    public function __construct(
        private readonly AnafTokenRepository $anafTokenRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        #[Autowire('%app.anaf_oauth2_client_id%')]
        private readonly string $clientId,
        #[Autowire('%app.anaf_oauth2_client_secret%')]
        private readonly string $clientSecret,
    ) {}

    public function resolve(Company $company): ?string
    {
        $token = $this->resolveEntity($company);

        return $token?->getToken();
    }

    /**
     * Resolve the AnafToken entity for a company.
     *
     * Default (shared) mode: any token of the organization can serve the company, the ones already
     * validated for its CIF first. One certificate with rights on every company keeps working with
     * no per-company setup, and a newly added company is covered at once.
     *
     * Exclusive mode (Company::isAnafTokenExclusive()): only the token bound to the company by an
     * exclusive link is used. No fallback, so company B is never sent with the certificate of A, and
     * deleting the bound token revokes that one company alone.
     */
    public function resolveEntity(Company $company): ?AnafToken
    {
        if ($company->isAnafTokenExclusive()) {
            return $this->resolveBound($company);
        }

        $org = $company->getOrganization();
        if (!$org) {
            return null;
        }

        // A token bound to one company never serves another one.
        $tokens = array_values(array_filter(
            $this->anafTokenRepository->findByOrganization($org),
            static fn (AnafToken $t) => !$t->isExclusive(),
        ));
        if (empty($tokens)) {
            return null;
        }

        $cif = (int) $company->getCif();

        // Prioritize tokens that have already been validated for this CIF
        usort($tokens, function (AnafToken $a, AnafToken $b) use ($cif) {
            $aMatch = $a->hasValidatedCif($cif) ? 0 : 1;
            $bMatch = $b->hasValidatedCif($cif) ? 0 : 1;
            return $aMatch <=> $bMatch;
        });

        foreach ($tokens as $anafToken) {
            if ($this->isExpired($anafToken)) {
                $refreshed = $this->refreshToken($anafToken);
                if (!$refreshed) {
                    continue;
                }
            }

            $anafToken->setLastUsedAt(new \DateTimeImmutable());
            // Cache this CIF as validated for this token
            $anafToken->addValidatedCif($cif);
            $this->entityManager->flush();

            return $anafToken;
        }

        return null;
    }

    private function resolveBound(Company $company): ?AnafToken
    {
        $anafToken = $company->getAnafToken();
        if (!$anafToken || !$anafToken->getToken()) {
            return null;
        }

        if ($this->isExpired($anafToken) && !$this->refreshToken($anafToken)) {
            return null;
        }

        $anafToken->setLastUsedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        return $anafToken;
    }

    public function refreshToken(AnafToken $anafToken): bool
    {
        $refreshToken = $anafToken->getRefreshToken();
        if (!$refreshToken) {
            $this->logger->warning('ANAF token has no refresh token', [
                'tokenId' => $anafToken->getId(),
                'label' => $anafToken->getLabel(),
            ]);
            return false;
        }

        try {
            $response = $this->httpClient->request('POST', 'https://logincert.anaf.ro/anaf-oauth2/v1/token', [
                'body' => [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refreshToken,
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'token_content_type' => 'jwt',
                ],
            ]);

            $content = json_decode($response->getContent(false), true);

            if (!isset($content['access_token'])) {
                $this->logger->error('Failed to refresh ANAF token', [
                    'tokenId' => $anafToken->getId(),
                    'label' => $anafToken->getLabel(),
                    'response' => $content,
                ]);
                return false;
            }

            $anafToken->setToken($content['access_token']);
            $anafToken->setRefreshToken($content['refresh_token'] ?? $refreshToken);
            $anafToken->setExpireAt(new \DateTimeImmutable(sprintf('+%d seconds', $content['expires_in'] ?? 3600)));
            $this->entityManager->flush();

            $this->logger->info('ANAF token refreshed successfully', [
                'tokenId' => $anafToken->getId(),
                'label' => $anafToken->getLabel(),
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Exception refreshing ANAF token', [
                'tokenId' => $anafToken->getId(),
                'label' => $anafToken->getLabel(),
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Remove a CIF from all tokens' validated CIF cache.
     * Called when ANAF confirms a token doesn't have access to this CIF.
     */
    public function invalidateCifCache(Company $company): void
    {
        $org = $company->getOrganization();
        if (!$org) {
            return;
        }

        $cif = (int) $company->getCif();
        $tokens = $this->anafTokenRepository->findByOrganization($org);

        foreach ($tokens as $anafToken) {
            if ($anafToken->hasValidatedCif($cif)) {
                $anafToken->removeValidatedCif($cif);
            }
        }

        $this->entityManager->flush();
    }

    private function isExpired(AnafToken $token): bool
    {
        $expireAt = $token->getExpireAt();
        if (!$expireAt) {
            return true;
        }

        // Consider expired if less than 1 hour remaining (safety buffer for long operations)
        return $expireAt < new \DateTimeImmutable('+1 hour');
    }
}
