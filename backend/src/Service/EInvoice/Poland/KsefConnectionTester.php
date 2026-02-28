<?php

namespace App\Service\EInvoice\Poland;

use App\Service\EInvoice\EInvoiceConnectionTesterInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.einvoice_connection_tester', ['provider' => 'ksef'])]
class KsefConnectionTester implements EInvoiceConnectionTesterInterface
{
    public function __construct(
        private readonly KsefClient $client,
    ) {}

    public function test(array $config): array
    {
        if (empty($config['authToken']) || empty($config['nip'])) {
            return ['success' => false, 'error' => 'Missing authToken or nip.'];
        }

        try {
            $ref = new \ReflectionMethod($this->client, 'initSession');
            $sessionToken = $ref->invoke($this->client, $config['authToken'], $config['nip']);

            // Clean up: terminate the test session
            $termRef = new \ReflectionMethod($this->client, 'terminateSession');
            $termRef->invoke($this->client, $sessionToken);

            return ['success' => true, 'error' => null];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
