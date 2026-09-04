<?php

declare(strict_types=1);

namespace App\Tests\Api;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Public ANAF validation endpoint. When the Java validation service is running
 * locally (JAVA_SERVICE_URL) the minimal D212 must come back invalid with ANAF's
 * own messages; without it the endpoint must say so with 503, never "valid".
 */
final class PublicDeclarationValidateTest extends WebTestCase
{
    private const D212 = '<?xml version="1.0" encoding="UTF-8"?><d212 xmlns="mfp:anaf:dgti:d212:declaratie:v9" an_r="2025" luna_r="12" cif="1900101123456" nume_c="EXEMPLU" adresa_c="Bucuresti" d_rec="0" totalPlata_A="0"/>';

    public function testValidatesWithoutAccount(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/v1/public/declarations/validate', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['xml' => self::D212]));

        $response = $client->getResponse();
        $data = json_decode((string) $response->getContent(), true);

        if ($response->getStatusCode() === 503) {
            self::assertSame('VALIDATOR_UNAVAILABLE', $data['code']);
            self::markTestSkipped('Java validation service not running locally.');
        }

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('D212', $data['type']);
        self::assertFalse($data['valid']);
        self::assertNotEmpty($data['errors']);
    }

    public function testRejectsNonXml(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/v1/public/declarations/validate', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['xml' => 'hello']));
        self::assertSame(400, $client->getResponse()->getStatusCode());

        $client->request('POST', '/api/v1/public/declarations/validate', server: ['CONTENT_TYPE' => 'application/xml'], content: '<unknownRoot/>');
        self::assertSame(422, $client->getResponse()->getStatusCode());
    }
}
