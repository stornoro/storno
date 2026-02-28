<?php

namespace App\Service\Anaf;

use App\Exception\AnafRateLimitException;
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
    ) {}

    public function consumeGlobal(): void
    {
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
