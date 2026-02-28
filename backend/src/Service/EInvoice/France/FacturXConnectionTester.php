<?php

namespace App\Service\EInvoice\France;

use App\Service\EInvoice\EInvoiceConnectionTesterInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.einvoice_connection_tester', ['provider' => 'facturx'])]
class FacturXConnectionTester implements EInvoiceConnectionTesterInterface
{
    public function __construct(
        private readonly ChorusProClient $client,
    ) {}

    public function test(array $config): array
    {
        if (empty($config['clientId']) || empty($config['clientSecret'])) {
            return ['success' => false, 'error' => 'Missing clientId or clientSecret.'];
        }

        try {
            $ref = new \ReflectionMethod($this->client, 'authenticate');
            $ref->invoke($this->client, $config['clientId'], $config['clientSecret']);

            return ['success' => true, 'error' => null];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
