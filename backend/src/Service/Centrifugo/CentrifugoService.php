<?php

namespace App\Service\Centrifugo;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class CentrifugoService
{
    /** @var list<array<string, mixed>> */
    private array $buffer = [];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $apiUrl,
        private readonly string $apiKey,
        private readonly string $hmacSecret,
    ) {}

    /**
     * Publish data to a channel immediately (single HTTP call).
     */
    public function publish(string $channel, array $data): array
    {
        return $this->send('publish', ['channel' => $channel, 'data' => $data]);
    }

    /**
     * Broadcast the same data to multiple channels immediately (single HTTP call).
     *
     * @param string[] $channels
     */
    public function broadcast(array $channels, array $data): array
    {
        if (empty($channels)) {
            return [];
        }

        return $this->send('broadcast', ['channels' => $channels, 'data' => $data]);
    }

    /**
     * Queue a publish command to be sent in a batch on flush().
     */
    public function queue(string $channel, array $data): void
    {
        $this->buffer[] = ['publish' => ['channel' => $channel, 'data' => $data]];
    }

    /**
     * Queue a broadcast command (same data to multiple channels) to be sent in a batch on flush().
     *
     * @param string[] $channels
     */
    public function queueBroadcast(array $channels, array $data): void
    {
        if (empty($channels)) {
            return;
        }

        $this->buffer[] = ['broadcast' => ['channels' => $channels, 'data' => $data]];
    }

    /**
     * Flush all queued commands in a single batch HTTP call.
     * Commands are processed in parallel on the Centrifugo side.
     */
    public function flush(): void
    {
        if (empty($this->buffer)) {
            return;
        }

        $commands = $this->buffer;
        $this->buffer = [];

        // Optimize: single publish doesn't need the batch overhead
        if (count($commands) === 1 && isset($commands[0]['publish'])) {
            $cmd = $commands[0]['publish'];
            $this->publish($cmd['channel'], $cmd['data']);
            return;
        }

        // Optimize: single broadcast doesn't need batch overhead
        if (count($commands) === 1 && isset($commands[0]['broadcast'])) {
            $cmd = $commands[0]['broadcast'];
            $this->broadcast($cmd['channels'], $cmd['data']);
            return;
        }

        $this->batch($commands);
    }

    /**
     * Send multiple commands in a single batch request.
     * Reduces HTTP overhead when publishing to many channels.
     *
     * @param list<array<string, mixed>> $commands Each: ['publish' => [...]] or ['broadcast' => [...]]
     * @param bool $parallel Process commands in parallel on Centrifugo side
     * @return array Replies from Centrifugo
     */
    public function batch(array $commands, bool $parallel = true): array
    {
        if (empty($commands)) {
            return [];
        }

        $payload = ['commands' => $commands];

        if ($parallel) {
            $payload['parallel'] = true;
        }

        try {
            $response = $this->httpClient->request('POST', $this->apiUrl . '/batch', [
                'headers' => [
                    'X-API-Key' => $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
            ]);

            $result = $response->toArray();

            $this->logger->debug('Centrifugo batch sent.', [
                'commands' => count($commands),
                'replies' => count($result['replies'] ?? []),
            ]);

            return $result['replies'] ?? [];
        } catch (\Throwable $e) {
            $this->logger->error('Centrifugo batch API error.', [
                'commands' => count($commands),
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Create a batch publisher for collecting multiple commands
     * and sending them in a single HTTP request.
     *
     * Usage:
     *   $batch = $centrifugo->createBatch();
     *   $batch->publish('channel1', $data1);
     *   $batch->broadcast(['ch1', 'ch2'], $data2);
     *   $batch->send();
     */
    public function createBatch(): CentrifugoBatchPublisher
    {
        return new CentrifugoBatchPublisher($this);
    }

    /**
     * Returns the number of queued commands.
     */
    public function getBufferSize(): int
    {
        return count($this->buffer);
    }

    /**
     * Generate a connection token (JWT) for a client.
     */
    public function generateConnectionToken(string $userId, int $expireAt = 0, array $info = []): string
    {
        $payload = ['sub' => $userId];

        if ($expireAt > 0) {
            $payload['exp'] = $expireAt;
        }

        if (!empty($info)) {
            $payload['info'] = $info;
        }

        return $this->encodeJwt($payload);
    }

    /**
     * Generate a subscription token (JWT) for a private channel.
     */
    public function generateSubscriptionToken(string $userId, string $channel, int $expireAt = 0, array $info = []): string
    {
        $payload = [
            'sub' => $userId,
            'channel' => $channel,
        ];

        if ($expireAt > 0) {
            $payload['exp'] = $expireAt;
        }

        if (!empty($info)) {
            $payload['info'] = $info;
        }

        return $this->encodeJwt($payload);
    }

    private function send(string $method, array $params): array
    {
        try {
            $response = $this->httpClient->request('POST', $this->apiUrl . '/' . $method, [
                'headers' => [
                    'X-API-Key' => $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $params,
            ]);

            return $response->toArray();
        } catch (\Throwable $e) {
            $this->logger->error('Centrifugo API error.', [
                'method' => $method,
                'error' => $e->getMessage(),
            ]);

            return ['error' => $e->getMessage()];
        }
    }

    private function encodeJwt(array $payload): string
    {
        $header = $this->base64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $payload['iat'] = time();
        $payload = $this->base64UrlEncode(json_encode($payload));
        $signature = $this->base64UrlEncode(
            hash_hmac('sha256', $header . '.' . $payload, $this->hmacSecret, true)
        );

        return $header . '.' . $payload . '.' . $signature;
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
