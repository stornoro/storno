<?php

declare(strict_types=1);

namespace App\Tests\Api;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Public form endpoints: catalog, specification, build (rules only, ANAF validators switched off) and the PDF attachment requirement. */
final class PublicDeclarationFormTest extends WebTestCase
{
    public function testCatalogSpecAndBuildWorkWithoutAnAccount(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/v1/public/declarations/forms');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $forms = json_decode((string) $client->getResponse()->getContent(), true)['forms'];
        self::assertSame('C168', $forms[0]['type']);

        $client->request('GET', '/api/v1/public/declarations/forms/c168');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $spec = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('mfp:anaf:dgti:c168:declaratie:v3', $spec['xml']['namespace']);
        self::assertNotEmpty($spec['example']['contracte']);

        $client->request('GET', '/api/v1/public/declarations/forms/C168?xsd=1');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('targetNamespace="mfp:anaf:dgti:c168:declaratie:v3"', (string) $client->getResponse()->getContent());

        $client->request('GET', '/api/v1/public/declarations/forms/D999');
        self::assertSame(404, $client->getResponse()->getStatusCode());

        $client->request('POST', '/api/v1/public/declarations/forms/C168/build', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['input' => $spec['example'], 'validate' => false, 'online' => false]));
        self::assertSame(200, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $build = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertStringContainsString('<c168 xmlns="mfp:anaf:dgti:c168:declaratie:v3"', $build['xml']);
        self::assertSame([], array_filter($build['issues'], fn ($i) => $i['level'] === 'error'));
        self::assertNull($build['validation']['duk']);

        $client->request('POST', '/api/v1/public/declarations/forms/C168/build', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['an' => 2026, 'validate' => false]));
        $build = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($build['valid']);
        self::assertContains('STORNO-REQ', array_column($build['issues'], 'code'));
    }

    public function testPdfRefusesC168WithoutAttachment(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/v1/public/declarations/pdf', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['type' => 'C168', 'xml' => '<c168 xmlns="mfp:anaf:dgti:c168:declaratie:v3"/>', 'attachments' => []]));
        $status = $client->getResponse()->getStatusCode();
        self::assertContains($status, [422, 503], (string) $client->getResponse()->getContent());
        if ($status === 422) {
            self::assertStringContainsString('atașament', json_decode((string) $client->getResponse()->getContent(), true)['error']);
        }
    }
}
