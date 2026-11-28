<?php

namespace App\Tests\Api;

class ClientSyncInvoicesTest extends ApiTestCase
{
    private function createTestClient(string $companyId, string $cui): string
    {
        $response = $this->apiPost('/api/v1/clients', [
            'name' => 'Sync Test SRL ' . substr(md5(uniqid()), 0, 6),
            'type' => 'company',
            'cui' => $cui,
            'registrationNumber' => 'J40/' . rand(100, 9999) . '/2020',
            'address' => 'Str. Exemplu 1',
            'city' => 'Bucuresti',
            'county' => 'B',
            'country' => 'RO',
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(201);

        return $response['client']['id'];
    }

    private function createPastMonthDraftInvoice(string $companyId, string $clientId): array
    {
        $issueDate = (new \DateTimeImmutable('first day of 2 months ago'))->format('Y-m-d');
        $data = $this->apiPost('/api/v1/invoices', [
            'documentType' => 'invoice',
            'issueDate' => $issueDate,
            'dueDate' => (new \DateTimeImmutable($issueDate))->modify('+30 days')->format('Y-m-d'),
            'currency' => 'RON',
            'clientId' => $clientId,
            'lines' => [
                [
                    'description' => 'Servicii consultanta',
                    'quantity' => '1.00',
                    'unitOfMeasure' => 'buc',
                    'unitPrice' => '100.00',
                    'vatRate' => '21.00',
                    'vatCategoryCode' => 'S',
                ],
            ],
        ], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(201);

        return $data['invoice'] ?? $data;
    }

    public function testSyncInvoicesUpdatesPastMonthInvoices(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $oldCui = 'RO' . rand(10000000, 99999999);
        $newCui = 'RO' . rand(10000000, 99999999);
        $clientId = $this->createTestClient($companyId, $oldCui);
        $invoice = $this->createPastMonthDraftInvoice($companyId, $clientId);

        // Updating the client only propagates to current-month invoices — the
        // past-month invoice must keep the old CUI.
        $update = $this->apiPatch('/api/v1/clients/' . $clientId, ['cui' => $newCui], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertSame(0, $update['invoicesUpdated']);

        $detail = $this->apiGet('/api/v1/invoices/' . $invoice['id'], ['X-Company' => $companyId]);
        $this->assertSame(substr($oldCui, 2), $detail['receiverCif']);

        // Explicit sync rewrites the past-month invoice too.
        $sync = $this->apiPost('/api/v1/clients/' . $clientId . '/sync-invoices', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertSame(1, $sync['invoicesUpdated']);

        $detail = $this->apiGet('/api/v1/invoices/' . $invoice['id'], ['X-Company' => $companyId]);
        $this->assertSame(substr($newCui, 2), $detail['receiverCif']);
        $this->assertSame(substr($newCui, 2), $detail['buyerSnapshot']['cui'] ?? null);
    }

    /**
     * An imported invoice keeps its header totals even when its lines were stored
     * with lineTotal 0.00. Syncing it with the client used to sum those lines and
     * rewrite the invoice to 0.
     */
    public function testSyncKeepsAmountsOfSyncedInvoice(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $oldCui = 'RO' . rand(10000000, 99999999);
        $newCui = 'RO' . rand(10000000, 99999999);
        $clientId = $this->createTestClient($companyId, $oldCui);
        $invoice = $this->createPastMonthDraftInvoice($companyId, $clientId);

        $em = static::getContainer()->get('doctrine')->getManager();
        $entity = $em->find(\App\Entity\Invoice::class, \Symfony\Component\Uid\Uuid::fromString($invoice['id']));
        $entity->setStatus(\App\Enum\DocumentStatus::SYNCED);
        foreach ($entity->getLines() as $line) {
            $line->setLineTotal('0.00');
            $line->setVatAmount('0.00');
        }
        $em->flush();
        $em->clear();
        $stored = $this->apiGet('/api/v1/invoices/' . $invoice['id'], ['X-Company' => $companyId]);
        $this->assertSame('synced', $stored['status']);
        $this->assertSame('0.00', $stored['lines'][0]['lineTotal']);

        $this->apiPatch('/api/v1/clients/' . $clientId, ['cui' => $newCui], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);

        $synced = $this->apiPost('/api/v1/invoices/' . $invoice['id'] . '/sync-client', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertSame(substr($newCui, 2), $synced['receiverCif']);
        $this->assertSame($invoice['subtotal'], $synced['subtotal']);
        $this->assertSame($invoice['vatTotal'], $synced['vatTotal']);
        $this->assertSame($invoice['total'], $synced['total']);
    }

    public function testSyncSingleInvoiceWithClientData(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $oldCui = 'RO' . rand(10000000, 99999999);
        $newCui = 'RO' . rand(10000000, 99999999);
        $clientId = $this->createTestClient($companyId, $oldCui);
        $invoice = $this->createPastMonthDraftInvoice($companyId, $clientId);
        $untouched = $this->createPastMonthDraftInvoice($companyId, $clientId);

        $this->apiPatch('/api/v1/clients/' . $clientId, ['cui' => $newCui], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);

        // Per-invoice sync updates only the targeted invoice.
        $synced = $this->apiPost('/api/v1/invoices/' . $invoice['id'] . '/sync-client', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertSame(substr($newCui, 2), $synced['receiverCif']);

        $other = $this->apiGet('/api/v1/invoices/' . $untouched['id'], ['X-Company' => $companyId]);
        $this->assertSame(substr($oldCui, 2), $other['receiverCif']);
    }

    public function testSyncInvoicesNotFound(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $this->apiPost('/api/v1/clients/00000000-0000-0000-0000-000000000000/sync-invoices', [], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(404);
    }
}
