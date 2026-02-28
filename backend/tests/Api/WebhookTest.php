<?php

namespace App\Tests\Api;

/**
 * Tests for webhook endpoint API: /api/v1/webhooks
 *
 * Permission matrix (from RolePermissionMap):
 *   - admin@localhost.com   → ADMIN in org-1  → webhook.view + webhook.manage
 *   - contabil@localhost.com → ACCOUNTANT in org-1 → webhook.view only (no webhook.manage)
 *   - angajat@localhost.com  → EMPLOYEE in org-1  → neither permission
 *   - user@localhost.com     → OWNER in org-2     → different org (company scoping isolation)
 */
class WebhookTest extends ApiTestCase
{
    // ---------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------

    /**
     * Creates a webhook endpoint for the given company and returns the response data.
     * Caller must already be logged in with a user that has webhook.manage.
     */
    private function createWebhook(string $companyId, array $overrides = []): array
    {
        $payload = array_merge([
            'url' => 'https://example.com/webhook',
            'events' => ['invoice.created'],
            'description' => 'Test webhook',
            'isActive' => true,
        ], $overrides);

        return $this->apiPost('/api/v1/webhooks', $payload, ['X-Company' => $companyId]);
    }

    // ---------------------------------------------------------------------------
    // GET /api/v1/webhooks/events
    // ---------------------------------------------------------------------------

    public function testListEvents(): void
    {
        $this->login();

        $data = $this->apiGet('/api/v1/webhooks/events');

        $this->assertResponseStatusCodeSame(200);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('categories', $data);
        $this->assertIsArray($data['data']);
        $this->assertIsArray($data['categories']);
        $this->assertNotEmpty($data['data']);
        $this->assertNotEmpty($data['categories']);

        // Each event entry must have event, category, and description keys
        foreach ($data['data'] as $event) {
            $this->assertArrayHasKey('event', $event);
            $this->assertArrayHasKey('category', $event);
            $this->assertArrayHasKey('description', $event);
        }

        // Known event types must be present
        $eventNames = array_column($data['data'], 'event');
        $this->assertContains('invoice.created', $eventNames);
        $this->assertContains('invoice.validated', $eventNames);
        $this->assertContains('sync.completed', $eventNames);
        $this->assertContains('payment.received', $eventNames);
        $this->assertContains('anaf.token_created', $eventNames);

        // Known categories must exist as keys
        $this->assertArrayHasKey('invoice', $data['categories']);
        $this->assertArrayHasKey('company', $data['categories']);
        $this->assertArrayHasKey('sync', $data['categories']);
        $this->assertArrayHasKey('payment', $data['categories']);
        $this->assertArrayHasKey('anaf', $data['categories']);
    }

    public function testListEventsUnauthenticated(): void
    {
        $this->apiGet('/api/v1/webhooks/events');

        $this->assertResponseStatusCodeSame(401);
    }

    // ---------------------------------------------------------------------------
    // GET /api/v1/webhooks (list)
    // ---------------------------------------------------------------------------

    public function testListWebhooksEmpty(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $data = $this->apiGet('/api/v1/webhooks', ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertArrayHasKey('data', $data);
        $this->assertIsArray($data['data']);
    }

    public function testListWebhooksRequiresAuthentication(): void
    {
        $this->apiGet('/api/v1/webhooks');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testListWebhooksRequiresCompanyHeader(): void
    {
        $this->login();

        // org-1 admin has 3 companies, so no auto-resolve; missing X-Company → 400
        $data = $this->apiGet('/api/v1/webhooks');

        $this->assertResponseStatusCodeSame(400);
    }

    public function testListWebhooksRequiresViewPermission(): void
    {
        // EMPLOYEE has neither webhook.view nor webhook.manage
        $this->login('angajat@localhost.com');
        $companyId = $this->getFirstCompanyId();

        $this->apiGet('/api/v1/webhooks', ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testListWebhooksAsAccountant(): void
    {
        // ACCOUNTANT has webhook.view
        $this->login('contabil@localhost.com');
        $companyId = $this->getFirstCompanyId();

        $data = $this->apiGet('/api/v1/webhooks', ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertArrayHasKey('data', $data);
    }

    // ---------------------------------------------------------------------------
    // POST /api/v1/webhooks (create)
    // ---------------------------------------------------------------------------

    public function testCreateWebhook(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $data = $this->createWebhook($companyId, [
            'url' => 'https://hooks.example.com/receive',
            'events' => ['invoice.created', 'invoice.validated'],
            'description' => 'My integration webhook',
        ]);

        $this->assertResponseStatusCodeSame(201);
        $this->assertArrayHasKey('data', $data);

        $webhook = $data['data'];
        $this->assertArrayHasKey('id', $webhook);
        $this->assertArrayHasKey('url', $webhook);
        $this->assertArrayHasKey('events', $webhook);
        $this->assertArrayHasKey('secret', $webhook);
        $this->assertArrayHasKey('isActive', $webhook);
        $this->assertArrayHasKey('description', $webhook);
        $this->assertArrayHasKey('createdAt', $webhook);
        $this->assertArrayHasKey('updatedAt', $webhook);

        $this->assertEquals('https://hooks.example.com/receive', $webhook['url']);
        $this->assertEquals(['invoice.created', 'invoice.validated'], $webhook['events']);
        $this->assertEquals('My integration webhook', $webhook['description']);
        $this->assertTrue($webhook['isActive']);

        // Secret is returned in full on creation
        $this->assertNotEmpty($webhook['secret']);
        $this->assertStringNotContainsString('***', $webhook['secret']);
    }

    public function testCreateWebhookDefaultsToActive(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $data = $this->createWebhook($companyId, [
            'url' => 'https://example.com/wh-active-test',
            'events' => ['sync.completed'],
            // isActive not sent
        ]);

        $this->assertResponseStatusCodeSame(201);
        $this->assertTrue($data['data']['isActive']);
    }

    public function testCreateWebhookCanBeInactive(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $data = $this->createWebhook($companyId, [
            'url' => 'https://example.com/wh-inactive',
            'events' => ['payment.received'],
            'isActive' => false,
        ]);

        $this->assertResponseStatusCodeSame(201);
        $this->assertFalse($data['data']['isActive']);
    }

    public function testCreateWebhookRequiresAuthentication(): void
    {
        $this->apiPost('/api/v1/webhooks', [
            'url' => 'https://example.com/wh',
            'events' => ['invoice.created'],
        ]);

        $this->assertResponseStatusCodeSame(401);
    }

    public function testCreateWebhookRequiresManagePermission(): void
    {
        // ACCOUNTANT has webhook.view but not webhook.manage
        $this->login('contabil@localhost.com');
        $companyId = $this->getFirstCompanyId();

        $this->createWebhook($companyId);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testCreateWebhookRequiresUrl(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $data = $this->apiPost('/api/v1/webhooks', [
            'events' => ['invoice.created'],
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testCreateWebhookRequiresEvents(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $data = $this->apiPost('/api/v1/webhooks', [
            'url' => 'https://example.com/wh',
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testCreateWebhookRejectsEmptyEventsArray(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $data = $this->apiPost('/api/v1/webhooks', [
            'url' => 'https://example.com/wh',
            'events' => [],
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testCreateWebhookRejectsHttpUrl(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $data = $this->apiPost('/api/v1/webhooks', [
            'url' => 'http://example.com/webhook',
            'events' => ['invoice.created'],
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testCreateWebhookRejectsNonUrl(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $data = $this->apiPost('/api/v1/webhooks', [
            'url' => 'not-a-url-at-all',
            'events' => ['invoice.created'],
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testCreateWebhookRejectsInvalidEventType(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $data = $this->apiPost('/api/v1/webhooks', [
            'url' => 'https://example.com/wh',
            'events' => ['nonexistent.event'],
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testCreateWebhookRejectsMixedValidInvalidEvents(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        // Even one invalid event among valid ones should fail
        $data = $this->apiPost('/api/v1/webhooks', [
            'url' => 'https://example.com/wh',
            'events' => ['invoice.created', 'totally.invalid'],
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testCreateWebhookDeduplicatesEvents(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $data = $this->createWebhook($companyId, [
            'url' => 'https://example.com/wh-dedup',
            'events' => ['invoice.created', 'invoice.created', 'invoice.validated'],
        ]);

        $this->assertResponseStatusCodeSame(201);
        // Duplicates must be removed
        $this->assertCount(2, $data['data']['events']);
        $this->assertContains('invoice.created', $data['data']['events']);
        $this->assertContains('invoice.validated', $data['data']['events']);
    }

    public function testCreateWebhookRequiresCompanyHeader(): void
    {
        // org-1 admin has multiple companies, so no auto-resolve
        $this->login();

        $this->apiPost('/api/v1/webhooks', [
            'url' => 'https://example.com/wh',
            'events' => ['invoice.created'],
        ]);

        $this->assertResponseStatusCodeSame(400);
    }

    // ---------------------------------------------------------------------------
    // GET /api/v1/webhooks/{uuid} (show)
    // ---------------------------------------------------------------------------

    public function testShowWebhook(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $created = $this->createWebhook($companyId, [
            'url' => 'https://example.com/wh-show',
            'events' => ['company.updated'],
        ]);
        $this->assertResponseStatusCodeSame(201);
        $webhookId = $created['data']['id'];

        $data = $this->apiGet('/api/v1/webhooks/' . $webhookId, ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertArrayHasKey('data', $data);

        $webhook = $data['data'];
        $this->assertEquals($webhookId, $webhook['id']);
        $this->assertEquals('https://example.com/wh-show', $webhook['url']);
        $this->assertEquals(['company.updated'], $webhook['events']);

        // Secret is masked on show (not create)
        $this->assertStringStartsWith('***', $webhook['secret']);
    }

    public function testShowWebhookNotFound(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $fakeUuid = '00000000-0000-7000-8000-000000000001';
        $this->apiGet('/api/v1/webhooks/' . $fakeUuid, ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(404);
    }

    public function testShowWebhookRequiresViewPermission(): void
    {
        // First create a webhook as admin
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $created = $this->createWebhook($companyId);
        $this->assertResponseStatusCodeSame(201);
        $webhookId = $created['data']['id'];

        // Then try to show as employee (no webhook.view)
        $this->login('angajat@localhost.com');
        $this->apiGet('/api/v1/webhooks/' . $webhookId, ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(403);
    }

    // ---------------------------------------------------------------------------
    // PATCH /api/v1/webhooks/{uuid} (update)
    // ---------------------------------------------------------------------------

    public function testUpdateWebhookUrl(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $created = $this->createWebhook($companyId, ['url' => 'https://old.example.com/wh']);
        $this->assertResponseStatusCodeSame(201);
        $webhookId = $created['data']['id'];

        $updated = $this->apiPatch('/api/v1/webhooks/' . $webhookId, [
            'url' => 'https://new.example.com/wh',
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertEquals('https://new.example.com/wh', $updated['data']['url']);
    }

    public function testUpdateWebhookEvents(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $created = $this->createWebhook($companyId, ['events' => ['invoice.created']]);
        $this->assertResponseStatusCodeSame(201);
        $webhookId = $created['data']['id'];

        $updated = $this->apiPatch('/api/v1/webhooks/' . $webhookId, [
            'events' => ['invoice.validated', 'sync.completed', 'payment.received'],
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertCount(3, $updated['data']['events']);
        $this->assertContains('invoice.validated', $updated['data']['events']);
        $this->assertContains('sync.completed', $updated['data']['events']);
        $this->assertContains('payment.received', $updated['data']['events']);
    }

    public function testUpdateWebhookDescription(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $created = $this->createWebhook($companyId, ['description' => 'Original description']);
        $this->assertResponseStatusCodeSame(201);
        $webhookId = $created['data']['id'];

        $updated = $this->apiPatch('/api/v1/webhooks/' . $webhookId, [
            'description' => 'Updated description',
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertEquals('Updated description', $updated['data']['description']);
    }

    public function testUpdateWebhookIsActive(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $created = $this->createWebhook($companyId, ['isActive' => true]);
        $this->assertResponseStatusCodeSame(201);
        $webhookId = $created['data']['id'];

        $updated = $this->apiPatch('/api/v1/webhooks/' . $webhookId, [
            'isActive' => false,
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertFalse($updated['data']['isActive']);
    }

    public function testUpdateWebhookRejectsHttpUrl(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $created = $this->createWebhook($companyId);
        $this->assertResponseStatusCodeSame(201);
        $webhookId = $created['data']['id'];

        $this->apiPatch('/api/v1/webhooks/' . $webhookId, [
            'url' => 'http://insecure.example.com/wh',
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testUpdateWebhookRejectsInvalidEvent(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $created = $this->createWebhook($companyId);
        $this->assertResponseStatusCodeSame(201);
        $webhookId = $created['data']['id'];

        $this->apiPatch('/api/v1/webhooks/' . $webhookId, [
            'events' => ['invoice.nonexistent'],
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testUpdateWebhookRejectsEmptyEvents(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $created = $this->createWebhook($companyId);
        $this->assertResponseStatusCodeSame(201);
        $webhookId = $created['data']['id'];

        $this->apiPatch('/api/v1/webhooks/' . $webhookId, [
            'events' => [],
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testUpdateWebhookRequiresManagePermission(): void
    {
        // Create as admin
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $created = $this->createWebhook($companyId);
        $this->assertResponseStatusCodeSame(201);
        $webhookId = $created['data']['id'];

        // Attempt update as accountant (only webhook.view, not webhook.manage)
        $this->login('contabil@localhost.com');
        $this->apiPatch('/api/v1/webhooks/' . $webhookId, [
            'description' => 'Unauthorized update',
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testUpdateWebhookNotFound(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $fakeUuid = '00000000-0000-7000-8000-000000000002';
        $this->apiPatch('/api/v1/webhooks/' . $fakeUuid, [
            'description' => 'No such webhook',
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(404);
    }

    // ---------------------------------------------------------------------------
    // DELETE /api/v1/webhooks/{uuid}
    // ---------------------------------------------------------------------------

    public function testDeleteWebhook(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $created = $this->createWebhook($companyId, ['url' => 'https://delete.example.com/wh']);
        $this->assertResponseStatusCodeSame(201);
        $webhookId = $created['data']['id'];

        $this->apiDelete('/api/v1/webhooks/' . $webhookId, ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(204);

        // Verify it is gone
        $this->apiGet('/api/v1/webhooks/' . $webhookId, ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testDeleteWebhookRequiresManagePermission(): void
    {
        // Create as admin
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $created = $this->createWebhook($companyId);
        $this->assertResponseStatusCodeSame(201);
        $webhookId = $created['data']['id'];

        // Attempt delete as accountant
        $this->login('contabil@localhost.com');
        $this->apiDelete('/api/v1/webhooks/' . $webhookId, ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testDeleteWebhookNotFound(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $fakeUuid = '00000000-0000-7000-8000-000000000003';
        $this->apiDelete('/api/v1/webhooks/' . $fakeUuid, ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(404);
    }

    // ---------------------------------------------------------------------------
    // Full CRUD lifecycle
    // ---------------------------------------------------------------------------

    public function testFullCrudLifecycle(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        // CREATE
        $created = $this->createWebhook($companyId, [
            'url' => 'https://lifecycle.example.com/wh',
            'events' => ['invoice.created'],
            'description' => 'Lifecycle test',
        ]);
        $this->assertResponseStatusCodeSame(201);
        $webhookId = $created['data']['id'];
        $this->assertNotEmpty($webhookId);

        // LIST — webhook appears in list
        $list = $this->apiGet('/api/v1/webhooks', ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);
        $ids = array_column($list['data'], 'id');
        $this->assertContains($webhookId, $ids);

        // SHOW
        $shown = $this->apiGet('/api/v1/webhooks/' . $webhookId, ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertEquals($webhookId, $shown['data']['id']);
        // Secret is masked on show
        $this->assertStringStartsWith('***', $shown['data']['secret']);

        // UPDATE
        $updated = $this->apiPatch('/api/v1/webhooks/' . $webhookId, [
            'description' => 'Updated lifecycle',
            'events' => ['invoice.validated', 'sync.error'],
            'isActive' => false,
        ], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertEquals('Updated lifecycle', $updated['data']['description']);
        $this->assertFalse($updated['data']['isActive']);
        $this->assertCount(2, $updated['data']['events']);

        // DELETE
        $this->apiDelete('/api/v1/webhooks/' . $webhookId, ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(204);

        // CONFIRM GONE
        $this->apiGet('/api/v1/webhooks/' . $webhookId, ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(404);
    }

    // ---------------------------------------------------------------------------
    // POST /api/v1/webhooks/{uuid}/test
    // ---------------------------------------------------------------------------

    public function testTestEndpoint(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        // Use a URL that will fail (the test delivery is still recorded)
        $created = $this->createWebhook($companyId, [
            'url' => 'https://nonexistent-webhook-target.example.invalid/receive',
            'events' => ['invoice.created'],
        ]);
        $this->assertResponseStatusCodeSame(201);
        $webhookId = $created['data']['id'];

        $result = $this->apiPost('/api/v1/webhooks/' . $webhookId . '/test', [], ['X-Company' => $companyId]);

        // The test endpoint always returns 200 regardless of delivery outcome
        $this->assertResponseStatusCodeSame(200);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('statusCode', $result);
        $this->assertArrayHasKey('durationMs', $result);
        $this->assertArrayHasKey('error', $result);
        $this->assertIsBool($result['success']);
        $this->assertIsInt($result['durationMs']);
    }

    public function testTestEndpointRequiresManagePermission(): void
    {
        // Create as admin
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $created = $this->createWebhook($companyId);
        $this->assertResponseStatusCodeSame(201);
        $webhookId = $created['data']['id'];

        // Attempt as accountant (webhook.view only)
        $this->login('contabil@localhost.com');
        $this->apiPost('/api/v1/webhooks/' . $webhookId . '/test', [], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testTestEndpointNotFound(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $fakeUuid = '00000000-0000-7000-8000-000000000004';
        $this->apiPost('/api/v1/webhooks/' . $fakeUuid . '/test', [], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(404);
    }

    // ---------------------------------------------------------------------------
    // POST /api/v1/webhooks/{uuid}/regenerate-secret
    // ---------------------------------------------------------------------------

    public function testRegenerateSecret(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $created = $this->createWebhook($companyId, [
            'url' => 'https://secret.example.com/wh',
            'events' => ['anaf.token_created'],
        ]);
        $this->assertResponseStatusCodeSame(201);
        $webhookId = $created['data']['id'];
        $originalSecret = $created['data']['secret'];

        $result = $this->apiPost(
            '/api/v1/webhooks/' . $webhookId . '/regenerate-secret',
            [],
            ['X-Company' => $companyId]
        );

        $this->assertResponseStatusCodeSame(200);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('secret', $result);

        // The new raw secret must differ from the original
        $newSecret = $result['secret'];
        $this->assertNotEmpty($newSecret);
        $this->assertNotEquals($originalSecret, $newSecret);

        // The secret field in 'data' should be the full new secret (showSecret: true)
        $this->assertStringNotContainsString('***', $result['data']['secret']);
        $this->assertEquals($newSecret, $result['data']['secret']);
    }

    public function testRegenerateSecretRequiresManagePermission(): void
    {
        // Create as admin
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $created = $this->createWebhook($companyId);
        $this->assertResponseStatusCodeSame(201);
        $webhookId = $created['data']['id'];

        // Attempt as accountant
        $this->login('contabil@localhost.com');
        $this->apiPost('/api/v1/webhooks/' . $webhookId . '/regenerate-secret', [], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testRegenerateSecretNotFound(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $fakeUuid = '00000000-0000-7000-8000-000000000005';
        $this->apiPost('/api/v1/webhooks/' . $fakeUuid . '/regenerate-secret', [], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(404);
    }

    // ---------------------------------------------------------------------------
    // GET /api/v1/webhooks/{uuid}/deliveries
    // ---------------------------------------------------------------------------

    public function testListDeliveriesEmptyForNewWebhook(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $created = $this->createWebhook($companyId, [
            'url' => 'https://deliveries.example.com/wh',
            'events' => ['company.created'],
        ]);
        $this->assertResponseStatusCodeSame(201);
        $webhookId = $created['data']['id'];

        $result = $this->apiGet(
            '/api/v1/webhooks/' . $webhookId . '/deliveries',
            ['X-Company' => $companyId]
        );

        $this->assertResponseStatusCodeSame(200);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('meta', $result);
        $this->assertIsArray($result['data']);
        $this->assertEmpty($result['data']);

        // Pagination meta must be present
        $this->assertArrayHasKey('page', $result['meta']);
        $this->assertArrayHasKey('limit', $result['meta']);
        $this->assertArrayHasKey('total', $result['meta']);
        $this->assertEquals(1, $result['meta']['page']);
        $this->assertEquals(0, $result['meta']['total']);
    }

    public function testListDeliveriesAfterTest(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $created = $this->createWebhook($companyId, [
            'url' => 'https://deliveries-after-test.example.invalid/wh',
            'events' => ['invoice.issued'],
        ]);
        $this->assertResponseStatusCodeSame(201);
        $webhookId = $created['data']['id'];

        // Trigger a test delivery (it will fail but still be recorded)
        $this->apiPost('/api/v1/webhooks/' . $webhookId . '/test', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);

        // The test delivery must appear in the deliveries list
        $result = $this->apiGet(
            '/api/v1/webhooks/' . $webhookId . '/deliveries',
            ['X-Company' => $companyId]
        );

        $this->assertResponseStatusCodeSame(200);
        $this->assertCount(1, $result['data']);
        $this->assertEquals(1, $result['meta']['total']);

        // Validate delivery shape
        $delivery = $result['data'][0];
        $this->assertArrayHasKey('id', $delivery);
        $this->assertArrayHasKey('eventType', $delivery);
        $this->assertArrayHasKey('status', $delivery);
        $this->assertArrayHasKey('responseStatusCode', $delivery);
        $this->assertArrayHasKey('durationMs', $delivery);
        $this->assertArrayHasKey('attempt', $delivery);
        $this->assertArrayHasKey('errorMessage', $delivery);
        $this->assertArrayHasKey('triggeredAt', $delivery);
        $this->assertArrayHasKey('completedAt', $delivery);
        $this->assertEquals('webhook.test', $delivery['eventType']);
    }

    public function testListDeliveriesRequiresViewPermission(): void
    {
        // Create as admin
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $created = $this->createWebhook($companyId);
        $this->assertResponseStatusCodeSame(201);
        $webhookId = $created['data']['id'];

        // Attempt as employee (no webhook.view)
        $this->login('angajat@localhost.com');
        $this->apiGet('/api/v1/webhooks/' . $webhookId . '/deliveries', ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testListDeliveriesAsAccountant(): void
    {
        // ACCOUNTANT has webhook.view — listing deliveries should work
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $created = $this->createWebhook($companyId);
        $this->assertResponseStatusCodeSame(201);
        $webhookId = $created['data']['id'];

        $this->login('contabil@localhost.com');
        $result = $this->apiGet(
            '/api/v1/webhooks/' . $webhookId . '/deliveries',
            ['X-Company' => $companyId]
        );

        $this->assertResponseStatusCodeSame(200);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('meta', $result);
    }

    public function testListDeliveriesNotFound(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $fakeUuid = '00000000-0000-7000-8000-000000000006';
        $this->apiGet('/api/v1/webhooks/' . $fakeUuid . '/deliveries', ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(404);
    }

    // ---------------------------------------------------------------------------
    // Company scoping: webhooks are scoped to the company in the X-Company header
    // ---------------------------------------------------------------------------

    public function testWebhookIsOnlyScopedToItsOwnCompany(): void
    {
        // Create two webhooks in different companies within org-1
        $this->login();

        $companies = $this->apiGet('/api/v1/companies');
        $this->assertResponseStatusCodeSame(200);
        $this->assertGreaterThanOrEqual(2, count($companies['data']), 'Need at least 2 companies in org-1');

        $company1Id = $companies['data'][0]['id'];
        $company2Id = $companies['data'][1]['id'];

        // Create webhook in company 1
        $created = $this->createWebhook($company1Id, [
            'url' => 'https://company1.example.com/wh',
            'events' => ['invoice.created'],
        ]);
        $this->assertResponseStatusCodeSame(201);
        $webhookId = $created['data']['id'];

        // Accessing the webhook with company 1 header must succeed
        $this->apiGet('/api/v1/webhooks/' . $webhookId, ['X-Company' => $company1Id]);
        $this->assertResponseStatusCodeSame(200);

        // Accessing the same webhook with company 2 header must return 404
        // (company scope mismatch: endpoint belongs to company1, not company2)
        $this->apiGet('/api/v1/webhooks/' . $webhookId, ['X-Company' => $company2Id]);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testWebhookListIsScopedToCompany(): void
    {
        // Verify the list only shows webhooks for the specified company
        $this->login();

        $companies = $this->apiGet('/api/v1/companies');
        $this->assertResponseStatusCodeSame(200);
        $this->assertGreaterThanOrEqual(2, count($companies['data']), 'Need at least 2 companies in org-1');

        $company1Id = $companies['data'][0]['id'];
        $company2Id = $companies['data'][1]['id'];

        // Create a webhook in company 1
        $created = $this->createWebhook($company1Id, [
            'url' => 'https://list-scope.example.com/wh',
            'events' => ['sync.started'],
        ]);
        $this->assertResponseStatusCodeSame(201);
        $webhookId = $created['data']['id'];

        // List for company 1 must include the webhook
        $list1 = $this->apiGet('/api/v1/webhooks', ['X-Company' => $company1Id]);
        $this->assertResponseStatusCodeSame(200);
        $ids1 = array_column($list1['data'], 'id');
        $this->assertContains($webhookId, $ids1);

        // List for company 2 must NOT include company 1's webhook
        $list2 = $this->apiGet('/api/v1/webhooks', ['X-Company' => $company2Id]);
        $this->assertResponseStatusCodeSame(200);
        $ids2 = array_column($list2['data'], 'id');
        $this->assertNotContains($webhookId, $ids2);
    }

    public function testCannotDeleteWebhookWithWrongCompanyHeader(): void
    {
        // Create a webhook in company 1
        $this->login();

        $companies = $this->apiGet('/api/v1/companies');
        $this->assertResponseStatusCodeSame(200);
        $this->assertGreaterThanOrEqual(2, count($companies['data']), 'Need at least 2 companies in org-1');

        $company1Id = $companies['data'][0]['id'];
        $company2Id = $companies['data'][1]['id'];

        $created = $this->createWebhook($company1Id, [
            'url' => 'https://delete-scope.example.com/wh',
            'events' => ['payment.received'],
        ]);
        $this->assertResponseStatusCodeSame(201);
        $webhookId = $created['data']['id'];

        // Attempt delete with company 2 header — must fail (404 scoping)
        $this->apiDelete('/api/v1/webhooks/' . $webhookId, ['X-Company' => $company2Id]);
        $this->assertResponseStatusCodeSame(404);

        // Webhook must still exist under company 1
        $this->apiGet('/api/v1/webhooks/' . $webhookId, ['X-Company' => $company1Id]);
        $this->assertResponseStatusCodeSame(200);
    }

    // ---------------------------------------------------------------------------
    // Permission boundaries: view vs manage
    // ---------------------------------------------------------------------------

    public function testViewPermissionGrantsReadAccess(): void
    {
        // Create as admin
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $created = $this->createWebhook($companyId, [
            'url' => 'https://perm-view.example.com/wh',
            'events' => ['invoice.rejected'],
        ]);
        $this->assertResponseStatusCodeSame(201);
        $webhookId = $created['data']['id'];

        // ACCOUNTANT (webhook.view) can list and show
        $this->login('contabil@localhost.com');

        $list = $this->apiGet('/api/v1/webhooks', ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);

        $shown = $this->apiGet('/api/v1/webhooks/' . $webhookId, ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertEquals($webhookId, $shown['data']['id']);
    }

    public function testEmployeeCannotViewWebhooks(): void
    {
        $this->login('angajat@localhost.com');
        $companyId = $this->getFirstCompanyId();

        // EMPLOYEE has no webhook.view — list must be 403
        $this->apiGet('/api/v1/webhooks', ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(403);
    }

    public function testAccountantCannotManageWebhooks(): void
    {
        // Create webhook as admin
        $this->login();
        $companyId = $this->getFirstCompanyId();
        $created = $this->createWebhook($companyId, [
            'url' => 'https://perm-manage.example.com/wh',
            'events' => ['company.restored'],
        ]);
        $this->assertResponseStatusCodeSame(201);
        $webhookId = $created['data']['id'];

        // ACCOUNTANT cannot create, update, delete, test, or regenerate secret
        $this->login('contabil@localhost.com');

        $this->createWebhook($companyId);
        $this->assertResponseStatusCodeSame(403);

        $this->apiPatch('/api/v1/webhooks/' . $webhookId, ['isActive' => false], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(403);

        $this->apiDelete('/api/v1/webhooks/' . $webhookId, ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(403);

        $this->apiPost('/api/v1/webhooks/' . $webhookId . '/test', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(403);

        $this->apiPost('/api/v1/webhooks/' . $webhookId . '/regenerate-secret', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(403);
    }

    // ---------------------------------------------------------------------------
    // All valid event types are accepted
    // ---------------------------------------------------------------------------

    public function testAllValidEventTypesAreAccepted(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $allEvents = [
            'invoice.created',
            'invoice.issued',
            'invoice.validated',
            'invoice.rejected',
            'invoice.sent_to_anaf',
            'company.created',
            'company.updated',
            'company.removed',
            'company.restored',
            'company.reset',
            'sync.started',
            'sync.completed',
            'sync.error',
            'payment.received',
            'anaf.token_created',
        ];

        $data = $this->createWebhook($companyId, [
            'url' => 'https://all-events.example.com/wh',
            'events' => $allEvents,
        ]);

        $this->assertResponseStatusCodeSame(201);
        $this->assertCount(count($allEvents), $data['data']['events']);

        foreach ($allEvents as $event) {
            $this->assertContains($event, $data['data']['events']);
        }
    }
}
