<?php

namespace App\Service\EInvoice\Germany;

use App\Service\EInvoice\EInvoiceConnectionTesterInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.einvoice_connection_tester', ['provider' => 'xrechnung'])]
class XRechnungConnectionTester implements EInvoiceConnectionTesterInterface
{
    public function __construct(
        private readonly XRechnungClient $client,
    ) {}

    public function test(array $config): array
    {
        if (empty($config['clientId']) || empty($config['clientSecret'])) {
            return ['success' => false, 'error' => 'Missing clientId or clientSecret.'];
        }

        try {
            // Use reflection to call the private authenticate method for connection testing
            $ref = new \ReflectionMethod($this->client, 'authenticate');
            $ref->invoke($this->client, $config['clientId'], $config['clientSecret']);

            return ['success' => true, 'error' => null];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
