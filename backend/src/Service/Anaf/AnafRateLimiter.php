<?php

namespace App\Service\Anaf;

use App\Exception\AnafRateLimitException;
use App\Security\OrganizationContext;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactory;

class AnafRateLimiter
{
    public function __construct(
        #[Autowire(service: 'limiter.anaf_global')]
        private readonly RateLimiterFactory $globalLimiter,
        #[Autowire(service: 'limiter.anaf_lista')]
        private readonly RateLimiterFactory $listaLimiter,
        #[Autowire(service: 'limiter.anaf_descarcare_message')]
        private readonly RateLimiterFactory $descarcareMessageLimiter,
        #[Autowire(service: 'limiter.anaf_stare_message')]
        private readonly RateLimiterFactory $stareMessageLimiter,
        #[Autowire(service: 'limiter.anaf_upload_rasp')]
        private readonly RateLimiterFactory $uploadRaspLimiter,
        #[Autowire(service: 'limiter.anaf_org')]
        private readonly RateLimiterFactory $orgLimiter,
        private readonly OrganizationContext $organizationContext,
    ) {}

    /**
     * Consume one call from the shared ANAF budget. When the call originates
     * from an authenticated HTTP request, a per-organization bucket is consumed
     * first so a single tenant cannot exhaust the global budget for everyone.
     * Message-handler (worker) calls have no organization context and only hit
     * the global bucket, as before.
     */
    public function consumeGlobal(): void
    {
        $orgId = $this->resolveOrganizationId();
        if ($orgId !== null) {
            $this->consume($this->orgLimiter, 'anaf_org', 'anaf_org_' . $orgId);
        }

        $this->consume($this->globalLimiter, 'anaf_global', 'anaf_global');
    }

    public function consumeLista(string $cif): void
    {
        $this->consume($this->listaLimiter, 'anaf_lista', 'anaf_lista_' . $cif);
    }

    public function consumeDescarcare(string $messageId): void
    {
        $this->consume($this->descarcareMessageLimiter, 'anaf_descarcare', 'anaf_dl_' . $messageId);
    }

    public function consumeStare(string $uploadId): void
    {
        $this->consume($this->stareMessageLimiter, 'anaf_stare', 'anaf_stare_' . $uploadId);
    }

    public function consumeUploadRasp(string $cif): void
    {
        $this->consume($this->uploadRaspLimiter, 'anaf_upload_rasp', 'anaf_rasp_' . $cif);
    }

    private function resolveOrganizationId(): ?string
    {
        try {
            return $this->organizationContext->getOrganization()?->getId()?->toRfc4122();
        } catch (\Throwable) {
            // No request / security context (CLI, workers) — skip the per-org bucket.
            return null;
        }
    }

    private function consume(RateLimiterFactory $factory, string $limitName, string $key): void
    {
        $limiter = $factory->create($key);
        $limit = $limiter->consume();

        if (!$limit->isAccepted()) {
            $retryAfter = $limit->getRetryAfter();
            $seconds = $retryAfter->getTimestamp() - time();

            throw new AnafRateLimitException(max(1, $seconds), $limitName);
        }
    }
}
