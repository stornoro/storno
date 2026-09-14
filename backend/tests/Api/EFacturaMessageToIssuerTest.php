<?php

namespace App\Tests\Api;

class EFacturaMessageToIssuerTest extends ApiTestCase
{
    public function testUnknownInvoiceIs404(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $data = $this->apiPost('/api/v1/invoices/00000000-0000-4000-8000-000000000000/efactura-message', ['message' => 'x'], ['X-Company' => $companyId]);

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        self::assertArrayHasKey('error', $data);
    }

    public function testOutgoingInvoiceCannotBeAnswered(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $list = $this->apiGet('/api/v1/invoices?direction=outgoing', ['X-Company' => $companyId]);
        $outgoing = null;
        foreach ($list['data'] ?? $list['items'] ?? $list as $inv) {
            if (is_array($inv) && ($inv['direction'] ?? null) === 'outgoing') { $outgoing = $inv; break; }
        }
        self::assertNotNull($outgoing, 'fixtures must contain an outgoing invoice');

        $data = $this->apiPost('/api/v1/invoices/' . $outgoing['id'] . '/efactura-message', ['message' => 'Va rugam corectati'], ['X-Company' => $companyId]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSame('NOT_RECEIVED_INVOICE', $data['code']);
    }

    public function testReceivedInvoiceWithoutIndexOrMessageIsRejected(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $list = $this->apiGet('/api/v1/invoices', ['X-Company' => $companyId]);
        $first = null;
        foreach ($list['data'] ?? $list['items'] ?? $list as $inv) {
            if (is_array($inv) && isset($inv['id'])) { $first = $inv; break; }
        }
        self::assertNotNull($first, 'fixtures must contain an invoice');

        // Turn it into a received invoice without an upload index
        $em = self::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
        $invoice = $em->getRepository(\App\Entity\Invoice::class)->find(\Symfony\Component\Uid\Uuid::fromString($first['id']));
        $invoice->setDirection(\App\Enum\InvoiceDirection::INCOMING);
        $invoice->setAnafUploadId(null);
        $em->flush();

        $data = $this->apiPost('/api/v1/invoices/' . $first['id'] . '/efactura-message', ['message' => ''], ['X-Company' => $companyId]);

        $status = $this->client->getResponse()->getStatusCode();
        self::assertContains($status, [400, 422], 'empty message or missing upload index must be refused before ANAF is called');
        self::assertContains($data['code'], ['VALIDATION_ERROR', 'NO_UPLOAD_INDEX']);
    }
}
