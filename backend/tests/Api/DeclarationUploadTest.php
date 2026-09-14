<?php

namespace App\Tests\Api;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Uploaded declarations (XML, or a PDF from another program) are filed as they came in. */
class DeclarationUploadTest extends ApiTestCase
{
    private const XML = '<?xml version="1.0" encoding="UTF-8"?><declaratie300 xmlns="mfp:anaf:dgti:d300:declaratie:v7" luna="5" an="2026" cui="12345678" d_rec="0"><rand1 x="1"/></declaratie300>';

    public function testXmlAndPdfUploads(): void
    {
        $this->login();
        $h = ['X-Company' => $this->getFirstCompanyId()];
        $dir = sys_get_temp_dir();

        file_put_contents($dir . '/d300.xml', self::XML);
        $this->client->request('POST', '/api/v1/declarations/upload', [], ['file' => new UploadedFile($dir . '/d300.xml', 'd300.xml', 'application/xml', null, true)], $this->buildHeaders($h));
        $this->assertResponseStatusCodeSame(201);
        $decl = $this->decodeResponse();
        $this->assertSame('d300', $decl['type']);
        $this->assertSame(2026, $decl['year']);
        $this->assertSame(5, $decl['month']);
        $this->assertTrue($decl['metadata']['externalXml']);

        $this->client->request('GET', '/api/v1/declarations/' . $decl['id'] . '/xml', [], [], $this->buildHeaders($h));
        $this->assertResponseStatusCodeSame(200);
        $this->assertStringContainsString('<rand1 x="1"/>', $this->client->getResponse()->getContent(), 'the uploaded document itself is what Storno files, child elements included');

        $z = gzcompress(self::XML);
        $pdf = "%PDF-1.7\n1 0 obj\n<< /Type /Catalog >>\nendobj\n2 0 obj\n<< /Type /EmbeddedFile /Filter /FlateDecode /Length " . strlen($z) . " >>\nstream\n" . $z . "\nendstream\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF";
        file_put_contents($dir . '/d300.pdf', $pdf);
        $this->client->request('POST', '/api/v1/declarations/upload', [], ['file' => new UploadedFile($dir . '/d300.pdf', 'D300_2026_05.pdf', 'application/pdf', null, true)], $this->buildHeaders($h));
        $this->assertResponseStatusCodeSame(201);
        $fromPdf = $this->decodeResponse();
        $this->assertSame('pdf_upload', $fromPdf['metadata']['source']);
        $this->assertSame('D300_2026_05.pdf', $fromPdf['metadata']['uploadedFileName']);

        file_put_contents($dir . '/empty.pdf', "%PDF-1.7\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF");
        $this->client->request('POST', '/api/v1/declarations/upload', [], ['file' => new UploadedFile($dir . '/empty.pdf', 'empty.pdf', 'application/pdf', null, true)], $this->buildHeaders($h));
        $this->assertResponseStatusCodeSame(422);

        $this->apiDelete('/api/v1/declarations/' . $decl['id'], $h);
        $this->apiDelete('/api/v1/declarations/' . $fromPdf['id'], $h);
    }
}
