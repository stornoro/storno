<?php

/**
 * Backend side of the Centrifugo smoke test: drives the real CentrifugoService
 * against a running server. One action per invocation, JSON on stdout.
 *
 *   php backend.php token <userId>
 *   php backend.php subtoken <userId> <channel>
 *   php backend.php publish <channel> <json>
 *   php backend.php broadcast <json channels> <json>
 *   php backend.php batch <json commands>
 *   php backend.php queue <json commands>      queue()/queueBroadcast() then flush()
 *   php backend.php info                       the call the health checks make
 */

require dirname(__DIR__, 3) . '/backend/vendor/autoload.php';

use App\Service\Centrifugo\CentrifugoService;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpClient\HttpClient;

$apiUrl = getenv('CENTRIFUGO_API_URL') ?: 'http://127.0.0.1:18445/api';
$apiKey = getenv('CENTRIFUGO_API_KEY') ?: '';
$secret = getenv('CENTRIFUGO_TOKEN_HMAC_SECRET') ?: '';

// The service swallows transport errors and only logs them; a logged error fails the action.
$logger = new class extends AbstractLogger {
    public array $errors = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if (in_array($level, ['error', 'critical', 'alert', 'emergency'], true)) {
            $this->errors[] = $message . ' ' . json_encode($context);
        }
    }
};

$http = HttpClient::create(['timeout' => 5]);
$service = new CentrifugoService($http, $logger, $apiUrl, $apiKey, $secret);

$json = static fn (string $raw): array => json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
[, $action] = $argv + [1 => ''];
$args = array_slice($argv, 2);

$out = match ($action) {
    'token' => ['token' => $service->generateConnectionToken($args[0], time() + 3600, ['email' => 'user@example.com'])],
    'subtoken' => ['token' => $service->generateSubscriptionToken($args[0], $args[1], time() + 3600)],
    'expired-subtoken' => ['token' => $service->generateSubscriptionToken($args[0], $args[1], time() - 60)],
    'publish' => $service->publish($args[0], $json($args[1])),
    'broadcast' => $service->broadcast($json($args[0]), $json($args[1])),
    'batch' => ['replies' => $service->batch($json($args[0]))],
    'queue' => (static function () use ($service, $json, $args): array {
        foreach ($json($args[0]) as $command) {
            if (isset($command['channels'])) {
                $service->queueBroadcast($command['channels'], $command['data']);
            } else {
                $service->queue($command['channel'], $command['data']);
            }
        }
        $queued = $service->getBufferSize();
        $service->flush();

        return ['queued' => $queued, 'left' => $service->getBufferSize()];
    })(),
    'info' => $http->request('POST', $apiUrl . '/info', [
        'headers' => ['X-API-Key' => $apiKey, 'Content-Type' => 'application/json'],
        'json' => new \stdClass(),
    ])->toArray(),
    default => throw new \InvalidArgumentException('Unknown action: ' . $action),
};

if ($logger->errors !== []) {
    fwrite(STDERR, implode("\n", $logger->errors) . "\n");
    exit(1);
}

echo json_encode($out, JSON_UNESCAPED_SLASHES), "\n";
