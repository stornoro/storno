<?php

namespace App\Tests\Api;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The lookup is reachable without a user session, so the door itself is what
 * matters most: who gets in, who does not, and what an outsider can learn from
 * the refusals.
 */
class IntegrationLookupTest extends WebTestCase
{
    private const PATH = '/api/v1/integrations/invoicing-activity';

    private const KEY = 'cheie-de-test-pentru-integrare';

    /**
     * The developer's own .env carries a real key, and it reaches the test
     * environment like any other variable. Each test therefore says which of
     * the two worlds it is in, instead of inheriting whatever is on the
     * machine it runs on.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutKey();
    }

    protected function tearDown(): void
    {
        $this->withoutKey();
        parent::tearDown();
    }

    private function withoutKey(): void
    {
        unset($_ENV['INTEGRATION_API_KEY'], $_SERVER['INTEGRATION_API_KEY']);
    }

    private function withKey(): void
    {
        $_ENV['INTEGRATION_API_KEY'] = $_SERVER['INTEGRATION_API_KEY'] = self::KEY;
    }

    public function testWithoutAKeyConfiguredTheEndpointIsNotThere(): void
    {
        // Failing closed: an unconfigured deployment must not answer questions
        // about which companies invoice through Storno.
        $client = static::createClient();
        $client->request('GET', self::PATH . '?cui=10000001');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testAWrongKeyIsRefused(): void
    {
        $this->withKey();

        $client = static::createClient();
        $client->request('GET', self::PATH . '?cui=10000001', [], [], [
            'HTTP_X_INTEGRATION_KEY' => 'nu-e-cheia-buna',
        ]);

        $this->assertResponseStatusCodeSame(401);
    }

    public function testAValidKeyGetsAnAnswerAndNothingMore(): void
    {
        $this->withKey();

        try {
            $client = static::createClient();
            $client->request('GET', self::PATH . '?cui=10000001', [], [], [
                'HTTP_X_INTEGRATION_KEY' => self::KEY,
            ]);

            $this->assertResponseIsSuccessful();
            $body = json_decode($client->getResponse()->getContent(), true);

            $this->assertSame(
                ['active', 'invoicesLast30d', 'windowDays', 'invoicesInWindow'],
                array_keys($body),
                'Răspunsul trebuie să rămână minimal: nimic identificator, nimic financiar.',
            );
            $this->assertIsBool($body['active']);
            $this->assertIsInt($body['invoicesLast30d']);
        } finally {
            $this->withoutKey();
        }
    }

    public function testMissingFiscalCodeIsARequestError(): void
    {
        $this->withKey();

        try {
            $client = static::createClient();
            $client->request('GET', self::PATH, [], [], [
                'HTTP_X_INTEGRATION_KEY' => self::KEY,
            ]);

            $this->assertResponseStatusCodeSame(400);
        } finally {
            $this->withoutKey();
        }
    }

    public function testRefusalsDoNotRevealWhetherTheFiscalCodeExists(): void
    {
        $this->withKey();

        $client = static::createClient();

        $client->request('GET', self::PATH . '?cui=10000001', [], [], ['HTTP_X_INTEGRATION_KEY' => 'gresit']);
        $known = $client->getResponse()->getStatusCode();
        $knownBody = $client->getResponse()->getContent();

        $client->request('GET', self::PATH . '?cui=99999999', [], [], ['HTTP_X_INTEGRATION_KEY' => 'gresit']);
        $unknown = $client->getResponse()->getStatusCode();
        $unknownBody = $client->getResponse()->getContent();

        // Someone guessing keys must not be able to use the error as an oracle.
        $this->assertSame($known, $unknown);
        $this->assertSame($knownBody, $unknownBody);
    }
}
