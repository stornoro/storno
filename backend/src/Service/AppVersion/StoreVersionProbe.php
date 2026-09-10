<?php

declare(strict_types=1);

namespace App\Service\AppVersion;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reads the version currently published in the app stores.
 *
 *  - iOS: the public iTunes lookup API (no credentials).
 *  - Android: Google Play Developer API (production track) when a service
 *    account JSON is configured, otherwise the public Play listing page.
 */
class StoreVersionProbe
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $bundleId = 'com.storno.app',
        private readonly string $googlePlayServiceAccountJson = '',
    ) {}

    public function iosVersion(): ?string
    {
        try {
            $response = $this->httpClient->request('GET', 'https://itunes.apple.com/lookup', [
                'query' => ['bundleId' => $this->bundleId, 'country' => 'ro'],
                'timeout' => 15,
            ]);
            $data = $response->toArray(false);
            $version = $data['results'][0]['version'] ?? null;

            return is_string($version) ? $version : null;
        } catch (\Throwable $e) {
            $this->logger->warning('iTunes lookup failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    public function androidVersion(): ?string
    {
        $fromApi = $this->androidVersionFromPlayApi();
        if ($fromApi !== null) {
            return $fromApi;
        }

        return $this->androidVersionFromListing();
    }

    private function androidVersionFromPlayApi(): ?string
    {
        if ($this->googlePlayServiceAccountJson === '' || !class_exists(\Google\Client::class)) {
            return null;
        }
        try {
            $client = new \Google\Client();
            $client->setAuthConfig($this->googlePlayServiceAccountJson);
            $client->addScope('https://www.googleapis.com/auth/androidpublisher');
            $publisher = new \Google\Service\AndroidPublisher($client);
            $edit = $publisher->edits->insert($this->bundleId, new \Google\Service\AndroidPublisher\AppEdit());
            $track = $publisher->edits_tracks->get($this->bundleId, $edit->getId(), 'production');
            $best = null;
            foreach ($track->getReleases() ?? [] as $release) {
                if ($release->getStatus() !== 'completed') {
                    continue;
                }
                $name = (string) $release->getName();
                if (preg_match('/\d+\.\d+(?:\.\d+)?/', $name, $m) && ($best === null || version_compare($m[0], $best, '>'))) {
                    $best = $m[0];
                }
            }
            try {
                $publisher->edits->delete($this->bundleId, $edit->getId());
            } catch (\Throwable) {
                // read-only edit, leaving it to expire is harmless
            }

            return $best;
        } catch (\Throwable $e) {
            $this->logger->warning('Google Play Developer API lookup failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function androidVersionFromListing(): ?string
    {
        try {
            $response = $this->httpClient->request('GET', 'https://play.google.com/store/apps/details', [
                'query' => ['id' => $this->bundleId, 'hl' => 'en'],
                'headers' => ['User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/124.0 Safari/537.36'],
                'timeout' => 20,
            ]);
            $html = $response->getContent(false);
            // The listing embeds the current version as [[["1.1.11"]],...] in its data blobs.
            if (preg_match('/\[\[\["(\d+\.\d+(?:\.\d+)?)"\]\]/', $html, $m)) {
                return $m[1];
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Play listing lookup failed', ['error' => $e->getMessage()]);
        }

        return null;
    }
}
