<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class PushRelayService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $pushRelayUrl = '',
    ) {}

    public function isEnabled(): bool
    {
        return $this->pushRelayUrl !== '';
    }

    public function send(string $token, string $title, string $body, array $data = [], ?int $badge = null): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        try {
            $aps = ['sound' => 'default'];
            if ($badge !== null) {
                $aps['badge'] = $badge;
            }

            $payload = [
                'token' => $token,
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                ],
                'data' => !empty($data) ? array_map('strval', $data) : new \stdClass(),
                'android' => [
                    'priority' => 'high',
                ],
                'apns' => [
                    'payload' => [
                        'aps' => $aps,
                    ],
                ],
            ];

            $response = $this->httpClient->request('POST', rtrim($this->pushRelayUrl, '/') . '/api/v1/push/send', [
                'json' => $payload,
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode >= 400) {
                $this->logger->error('Push relay returned error.', [
                    'status' => $statusCode,
                    'body' => $response->getContent(false),
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send push notification via relay.', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
