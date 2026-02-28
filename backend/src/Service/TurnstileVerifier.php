<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class TurnstileVerifier
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly ?string $turnstileSecretKey,
        private readonly string $env,
    ) {}

    public function verify(string $token, ?string $remoteIp = null): bool
    {
        if ($this->env === 'dev' || $this->env === 'test') {
            return true;
        }

        if (!$this->turnstileSecretKey) {
            return true;
        }

        if (!$token) {
            return false;
        }

        try {
            $response = $this->httpClient->request('POST', 'https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'body' => [
                    'secret' => $this->turnstileSecretKey,
                    'response' => $token,
                    'remoteip' => $remoteIp,
                ],
            ]);

            $result = $response->toArray(false);

            if (!($result['success'] ?? false)) {
                $this->logger->warning('Turnstile verification failed', ['errors' => $result['error-codes'] ?? []]);
            }

            return $result['success'] ?? false;
        } catch (\Exception $e) {
            $this->logger->error('Turnstile API call failed', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
