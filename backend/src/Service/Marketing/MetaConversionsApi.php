<?php

namespace App\Service\Marketing;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Server-side Meta Conversions API client.
 *
 * Sends conversion events (today: CompleteRegistration) straight from the
 * backend, so paid-social attribution does not depend on a browser pixel and
 * ad blockers. Disabled unless META_PIXEL_ID and META_CAPI_ACCESS_TOKEN are set,
 * which keeps self-hosted instances silent. Personal fields are SHA-256 hashed
 * as Meta requires; the raw email never leaves the server.
 */
class MetaConversionsApi
{
    private const GRAPH_VERSION = 'v21.0';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly ?string $pixelId,
        private readonly ?string $accessToken,
        private readonly ?string $testEventCode = null,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) $this->pixelId && (bool) $this->accessToken;
    }

    /**
     * @param array{
     *   event_name: string,
     *   event_id: string,
     *   event_time: int,
     *   event_source_url?: ?string,
     *   email?: ?string,
     *   external_id?: ?string,
     *   client_ip?: ?string,
     *   client_user_agent?: ?string,
     *   fbc?: ?string,
     *   fbp?: ?string,
     * } $event
     */
    public function send(array $event): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        $payload = ['data' => [$this->buildEvent($event)]];
        if ($this->testEventCode) {
            $payload['test_event_code'] = $this->testEventCode;
        }

        try {
            $response = $this->httpClient->request(
                'POST',
                sprintf('https://graph.facebook.com/%s/%s/events', self::GRAPH_VERSION, $this->pixelId),
                [
                    'query' => ['access_token' => $this->accessToken],
                    'json' => $payload,
                    'timeout' => 10,
                ],
            );
            $result = $response->toArray(false);

            if ($response->getStatusCode() >= 400 || isset($result['error'])) {
                $this->logger->warning('Meta CAPI rejected the event', [
                    'event' => $event['event_name'],
                    'status' => $response->getStatusCode(),
                    'error' => $result['error']['message'] ?? null,
                ]);

                return false;
            }

            $this->logger->info('Meta CAPI event sent', [
                'event' => $event['event_name'],
                'received' => $result['events_received'] ?? null,
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Meta CAPI call failed', ['event' => $event['event_name'], 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Shape one event the way /{pixel}/events expects it. Public so the payload
     * can be unit-tested without an HTTP round trip.
     */
    public function buildEvent(array $event): array
    {
        $userData = array_filter([
            'em' => isset($event['email']) ? [self::hash(mb_strtolower(trim($event['email'])))] : null,
            'external_id' => isset($event['external_id']) ? [self::hash($event['external_id'])] : null,
            'client_ip_address' => $event['client_ip'] ?? null,
            'client_user_agent' => $event['client_user_agent'] ?? null,
            'fbc' => $event['fbc'] ?? null,
            'fbp' => $event['fbp'] ?? null,
        ], static fn ($v) => $v !== null && $v !== '' && $v !== []);

        return array_filter([
            'event_name' => $event['event_name'],
            'event_time' => $event['event_time'],
            'event_id' => $event['event_id'],
            'action_source' => 'website',
            'event_source_url' => $event['event_source_url'] ?? null,
            'user_data' => $userData,
        ], static fn ($v) => $v !== null && $v !== '');
    }

    private static function hash(string $value): string
    {
        return hash('sha256', $value);
    }
}
