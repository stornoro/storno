<?php

declare(strict_types=1);

namespace App\Tests\Api;

class DeclarationAgentRequiredTest extends ApiTestCase
{
    public function testServerSideSyncEndpointsAnswerAgentRequired(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $sync = $this->apiPost('/api/v1/declarations/sync', ['year' => 2026], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(409);
        $this->assertSame('AGENT_REQUIRED', $sync['code']);
        $this->assertStringContainsString('sync-prepare', $sync['hint']);

        $refresh = $this->apiPost('/api/v1/declarations/refresh-statuses', [], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(409);
        $this->assertSame('AGENT_REQUIRED', $refresh['code']);
    }
}
