<?php

declare(strict_types=1);

namespace App\Service\Declaration;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * ANAF's own online validators behind its web forms (anaf.ro/declaratii/<form>/api/proxy-validare).
 * They enforce business rules (BR-C168-…) the DUKIntegrator jars do not, so a file
 * that passes DUK can still be refused by the web form. Public, no certificate.
 * Unavailable → null, never a failure: the DUK verdict still stands.
 */
final class AnafOnlineValidator
{
    private const ENDPOINTS = [
        'C168' => 'https://www.anaf.ro/declaratii/c168/api/proxy-validare',
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function supports(string $type): bool
    {
        return isset(self::ENDPOINTS[strtoupper($type)]);
    }

    /** @return array{valid: bool, messages: list<string>, traceId: ?string}|null */
    public function validate(string $type, string $xml): ?array
    {
        $url = self::ENDPOINTS[strtoupper($type)] ?? null;
        if ($url === null) {
            return null;
        }
        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => ['Content-Type' => 'application/xml', 'Accept' => 'application/json'],
                'body' => $xml,
                'timeout' => 40,
            ]);
            if ($response->getStatusCode() !== 200 || !str_contains((string) ($response->getHeaders(false)['content-type'][0] ?? ''), 'json')) {
                $this->logger->warning('ANAF online validator answered unexpectedly', ['type' => $type, 'status' => $response->getStatusCode()]);

                return null;
            }
            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->warning('ANAF online validator unreachable', ['type' => $type, 'error' => $e->getMessage()]);

            return null;
        }
        $messages = [];
        foreach ($data['Messages'] ?? [] as $m) {
            $text = is_array($m) ? ($m['message'] ?? null) : $m;
            if (is_string($text) && $text !== '') {
                $messages[] = $text;
            }
        }

        return [
            'valid' => ($data['stare'] ?? '') === 'ok',
            'messages' => $messages,
            'traceId' => isset($data['trace_id']) ? (string) $data['trace_id'] : null,
        ];
    }
}
