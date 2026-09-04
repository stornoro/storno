<?php

declare(strict_types=1);

namespace App\Tests\Api;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicLegalDocumentTest extends WebTestCase
{
    public function testTerminationAgreementRendersHtmlAndPdf(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/public/documents');
        $types = array_column(json_decode((string) $client->getResponse()->getContent(), true)['types'], 'type');
        self::assertContains('conventie_incetare_inchiriere', $types);
        self::assertContains('declaratie_incetare_contract', $types);

        $fields = [
            'locator' => ['nume' => 'EXEMPLU PROPRIETAR', 'adresa' => 'Mun. Bucuresti, Str. Exemplu nr. 1, Sector 1', 'cnp' => '1900101123456'],
            'locatar' => ['nume' => 'EXEMPLU CHIRIAS', 'adresa' => 'Mun. Bucuresti, Str. Model nr. 2, Sector 2', 'ci_serie' => 'XX', 'ci_numar' => '123456'],
            'contract' => ['numar' => '7', 'data' => '01.03.2025', 'adresa_imobil' => 'Mun. Bucuresti, Str. Exemplu nr. 1, ap. 3', 'numar_inregistrare_anaf' => 'INTERNT-100000123-2025', 'data_inregistrare_anaf' => '05.03.2025'],
            'data_incetare' => '31.08.2026',
            'garantie' => ['suma' => '500', 'valuta' => 'EUR', 'termen_zile' => 10],
        ];
        $client->request('POST', '/api/v1/public/documents/conventie_incetare_inchiriere', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($fields));
        self::assertSame(200, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertStringContainsString('CONVENȚIE DE ÎNCETARE', $data['html']);
        self::assertStringContainsString('INTERNT-100000123-2025', $data['html']);
        self::assertStringContainsString('500 EUR', $data['html']);
        self::assertStringStartsWith('%PDF', base64_decode($data['pdfBase64']));

        // missing fields are named
        $client->request('POST', '/api/v1/public/documents/declaratie_incetare_contract', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['locator' => ['nume' => 'X']]));
        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('contract.numar', json_decode((string) $client->getResponse()->getContent(), true)['error']);
    }
}
