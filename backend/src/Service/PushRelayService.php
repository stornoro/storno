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

    /**
     * Send a push notification through the relay.
     *
     * Returns null on success, or a short error string when the relay rejected the
     * request or the call itself blew up. The caller persists this on the
     * `Notification` row so users / support can debug why a push didn't arrive.
     */
    public function send(string $token, string $title, string $body, array $data = [], ?int $badge = null): ?string
    {
        if (!$this->isEnabled()) {
            return 'relay_disabled';
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
                'data' => !empty($data) ? self::stringifyData($data) : new \stdClass(),
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
                $body = $response->getContent(false);
                $this->logger->error('Push relay returned error.', [
                    'status' => $statusCode,
                    'body' => $body,
                ]);
                return sprintf('relay_%d: %s', $statusCode, mb_substr($body, 0, 200));
            }

            return null;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send push notification via relay.', [
                'error' => $e->getMessage(),
            ]);
            return mb_substr($e->getMessage(), 0, 200);
        }
    }

    /**
     * Flatten a notification data payload into a string-only map.
     *
     * FCM requires every value in `data` to be a string. Nested arrays/objects
     * (e.g. messageParams, errors list) would otherwise throw
     * "Array to string conversion" when passed to strval(). Encode them as JSON
     * so the mobile client can JSON.parse the value back into an object.
     *
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    public static function stringifyData(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (is_array($value) || is_object($value)) {
                $out[$key] = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
            } elseif (is_bool($value)) {
                $out[$key] = $value ? 'true' : 'false';
            } elseif ($value === null) {
                $out[$key] = '';
            } else {
                $out[$key] = (string) $value;
            }
        }
        return $out;
    }
}
