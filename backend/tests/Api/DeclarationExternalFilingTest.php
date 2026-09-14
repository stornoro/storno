<?php

namespace App\Tests\Api;

/** A declaration filed outside Storno (portal by hand, counter) is tracked by ANAF's number. */
class DeclarationExternalFilingTest extends ApiTestCase
{
    public function testFiledExternallyIsTracked(): void
    {
        $this->login();
        $h = ['X-Company' => $this->getFirstCompanyId()];
        $decl = $this->apiPost('/api/v1/declarations', ['type' => 'd300', 'year' => 2026, 'month' => 6], $h);
        $this->assertResponseStatusCodeSame(201);

        $this->apiPatch('/api/v1/declarations/' . $decl['id'], ['filedExternally' => ['index' => 'x']], $h);
        $this->assertResponseStatusCodeSame(400);

        $up = $this->apiPatch('/api/v1/declarations/' . $decl['id'], ['filedExternally' => ['index' => 'INTERNT-4500123-2026', 'ghiseu' => true]], $h);
        $this->assertResponseStatusCodeSame(200);
        // outside prod the status check runs synchronously right away; it may already have polled ANAF
        $this->assertContains($up['status'], ['submitted', 'processing', 'error', 'accepted', 'rejected']);
        $this->assertNotNull($up['submittedAt']);
        $this->assertSame('4500123', $up['anafUploadId']);
        $this->assertTrue($up['metadata']['filedExternally']);
        $this->assertTrue($up['metadata']['ghiseu']);

        if (in_array($up['status'], ['submitted', 'processing'], true)) {
            $this->apiPatch('/api/v1/declarations/' . $decl['id'], ['filedExternally' => ['index' => '1']], $h);
            $this->assertResponseStatusCodeSame(400);
        }

        $this->apiDelete('/api/v1/declarations/' . $decl['id'], $h);
    }
}
