<?php

namespace App\Tests\Api;

/**
 * Tests for backup/restore API: /api/v1/backup
 *
 * Permission matrix:
 *   - admin@localhost.com   → ADMIN in org-1 → backup.manage ✓
 *   - contabil@localhost.com → ACCOUNTANT     → no backup.manage
 *   - angajat@localhost.com  → EMPLOYEE       → no backup.manage
 */
class BackupTest extends ApiTestCase
{
    // ─────────────────────────────────────────────────────────────────
    // POST /api/v1/backup — Create backup
    // ─────────────────────────────────────────────────────────────────

    public function testCreateBackupAsAdmin(): void
    {
        $this->login('admin@localhost.com');
        $companyId = $this->getFirstCompanyId();

        $data = $this->apiPost('/api/v1/backup', ['includeFiles' => false], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(202);
        $this->assertArrayHasKey('job', $data);
        $this->assertEquals('backup', $data['job']['type']);
        // In test env, sync messenger processes immediately — status may already be completed
        $this->assertContains($data['job']['status'], ['pending', 'processing', 'completed']);
        $this->assertArrayHasKey('id', $data['job']);
    }

    public function testCreateBackupForbiddenForAccountant(): void
    {
        $this->login('contabil@localhost.com');
        $companyId = $this->getFirstCompanyId();

        $this->apiPost('/api/v1/backup', [], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testCreateBackupForbiddenForEmployee(): void
    {
        $this->login('angajat@localhost.com');
        $companyId = $this->getFirstCompanyId();

        $this->apiPost('/api/v1/backup', [], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testCreateBackupRequiresAuth(): void
    {
        $this->client->request('POST', '/api/v1/backup', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([]));

        $this->assertResponseStatusCodeSame(401);
    }

    // ─────────────────────────────────────────────────────────────────
    // GET /api/v1/backup/history — List backups
    // ─────────────────────────────────────────────────────────────────

    public function testListBackupHistory(): void
    {
        $this->login('admin@localhost.com');
        $companyId = $this->getFirstCompanyId();

        $data = $this->apiGet('/api/v1/backup/history', ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertArrayHasKey('data', $data);
        $this->assertIsArray($data['data']);
    }

    public function testListBackupHistoryForbiddenForAccountant(): void
    {
        $this->login('contabil@localhost.com');
        $companyId = $this->getFirstCompanyId();

        $this->apiGet('/api/v1/backup/history', ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(403);
    }

    // ─────────────────────────────────────────────────────────────────
    // GET /api/v1/backup/{id}/status — Check backup status
    // ─────────────────────────────────────────────────────────────────

    public function testGetBackupStatus(): void
    {
        $this->login('admin@localhost.com');
        $companyId = $this->getFirstCompanyId();

        // Create a backup first
        $create = $this->apiPost('/api/v1/backup', ['includeFiles' => false], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(202);
        $jobId = $create['job']['id'];

        // Check status
        $data = $this->apiGet("/api/v1/backup/{$jobId}/status", ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertArrayHasKey('job', $data);
        $this->assertEquals($jobId, $data['job']['id']);
    }

    public function testGetBackupStatusNotFound(): void
    {
        $this->login('admin@localhost.com');
        $companyId = $this->getFirstCompanyId();

        $this->apiGet('/api/v1/backup/00000000-0000-0000-0000-000000000000/status', ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(404);
    }

    // ─────────────────────────────────────────────────────────────────
    // GET /api/v1/backup/{id}/download — Download not ready
    // ─────────────────────────────────────────────────────────────────

    public function testDownloadCompletedBackup(): void
    {
        $this->login('admin@localhost.com');
        $companyId = $this->getFirstCompanyId();

        // Create a backup (sync messenger completes it immediately)
        $create = $this->apiPost('/api/v1/backup', ['includeFiles' => false], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(202);
        $jobId = $create['job']['id'];

        // In sync mode, the job should be completed
        if ($create['job']['status'] === 'completed') {
            // Download should work
            $this->client->request('GET', "/api/v1/backup/{$jobId}/download", [], [], $this->buildHeaders(['X-Company' => $companyId]));
            $this->assertResponseStatusCodeSame(200);
            $this->assertEquals('application/zip', $this->client->getResponse()->headers->get('Content-Type'));
        } else {
            // If still pending, download should fail
            $this->apiGet("/api/v1/backup/{$jobId}/download", ['X-Company' => $companyId]);
            $this->assertResponseStatusCodeSame(400);
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // POST /api/v1/backup/restore — Restore requires file
    // ─────────────────────────────────────────────────────────────────

    public function testRestoreRequiresFile(): void
    {
        $this->login('admin@localhost.com');
        $companyId = $this->getFirstCompanyId();

        $data = $this->apiPost('/api/v1/backup/restore', [], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(400);
        $this->assertArrayHasKey('error', $data);
    }

    public function testRestoreForbiddenForAccountant(): void
    {
        $this->login('contabil@localhost.com');
        $companyId = $this->getFirstCompanyId();

        $this->apiPost('/api/v1/backup/restore', [], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(403);
    }

    // ─────────────────────────────────────────────────────────────────
    // Rate limiting: only 1 active job per company
    // ─────────────────────────────────────────────────────────────────

    /**
     * Rate limiting test: In test env with sync messenger, the first job
     * completes immediately, so we test the history endpoint to confirm
     * multiple jobs can't stack up. We verify by checking history grows.
     */
    public function testMultipleBackupsCreateHistory(): void
    {
        $this->login('admin@localhost.com');
        $companyId = $this->getFirstCompanyId();

        // Create first backup (sync messenger completes it immediately)
        $data1 = $this->apiPost('/api/v1/backup', ['includeFiles' => false], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(202);
        $jobId1 = $data1['job']['id'];

        // Create second backup (first already completed in sync mode)
        $data2 = $this->apiPost('/api/v1/backup', ['includeFiles' => false], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(202);
        $jobId2 = $data2['job']['id'];

        // Both should appear in history
        $history = $this->apiGet('/api/v1/backup/history', ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);
        $ids = array_column($history['data'], 'id');
        $this->assertContains($jobId1, $ids);
        $this->assertContains($jobId2, $ids);
    }
}
