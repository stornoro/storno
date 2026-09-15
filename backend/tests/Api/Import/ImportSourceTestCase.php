<?php

declare(strict_types=1);

namespace App\Tests\Api\Import;

use App\Tests\Api\ApiTestCase;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Upload → preview → mapping → execute → (re-import is idempotent) → revert,
 * for one import source. Messenger has no async routing in the test
 * environment, so execute runs the import before it answers.
 */
abstract class ImportSourceTestCase extends ApiTestCase
{
    protected const FIXTURES = __DIR__ . '/../../Fixtures/import/';

    protected string $companyId;

    /** @var array<string, string> */
    protected array $headers;

    /** @var string[] every job this test uploaded, purged in tearDown */
    private array $jobIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->login();
        $this->companyId = $this->getFirstCompanyId();
        $this->headers = ['X-Company' => $this->companyId];
    }

    /**
     * The test database is shared and keeps what a test writes, so every job
     * this test created is purged the way revert does it — also when an
     * assertion failed halfway and the test never reached its own revert.
     */
    protected function tearDown(): void
    {
        if ($this->jobIds !== []) {
            $conn = $this->db();
            foreach ($this->jobIds as $jobId) {
                $conn->executeStatement('DELETE il FROM invoice_line il INNER JOIN invoice i ON il.invoice_id = i.id WHERE i.import_job_id = :j', ['j' => $jobId]);
                $conn->executeStatement('DELETE FROM invoice WHERE import_job_id = :j', ['j' => $jobId]);
                $conn->executeStatement('DELETE rl FROM receipt_line rl INNER JOIN receipt r ON rl.receipt_id = r.id WHERE r.import_job_id = :j', ['j' => $jobId]);
                $conn->executeStatement('DELETE FROM receipt WHERE import_job_id = :j', ['j' => $jobId]);
                $conn->executeStatement('DELETE FROM client WHERE import_job_id = :j', ['j' => $jobId]);
                $conn->executeStatement('DELETE FROM import_job WHERE id = :j', ['j' => $jobId]);
            }
            $this->jobIds = [];
        }

        parent::tearDown();
    }

    protected function upload(string $source, string $importType, string $fixture): array
    {
        $path = self::FIXTURES . $fixture;
        $copy = sys_get_temp_dir() . '/' . uniqid('import_', true) . '_' . $fixture;
        copy($path, $copy);

        $this->client->request(
            'POST',
            '/api/v1/import/upload',
            ['importType' => $importType, 'source' => $source],
            ['file' => new UploadedFile($copy, $fixture, null, null, true)],
            $this->buildHeaders($this->headers),
        );
        $this->assertResponseStatusCodeSame(201, $this->client->getResponse()->getContent());
        $data = $this->decodeResponse();
        $this->assertSame('preview', $data['job']['status'], json_encode($data['job']['errors'] ?? null));
        $this->jobIds[] = $data['job']['id'];

        return $data;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed> the finished job
     */
    protected function runImport(string $source, string $importType, string $fixture, array $options = [], ?array $mappingOverride = null): array
    {
        $uploaded = $this->upload($source, $importType, $fixture);
        $job = $uploaded['job'];

        $preview = $this->apiGet('/api/v1/import/' . $job['id'] . '/preview', $this->headers);
        $this->assertResponseStatusCodeSame(200);
        $this->assertNotEmpty($preview['job']['previewData']);
        $this->assertNotEmpty($preview['job']['suggestedMapping'], 'the source mapper must recognise the fixture headers');
        $this->assertNotEmpty($preview['targetFields']);

        $mapping = $mappingOverride ?? $preview['job']['suggestedMapping'];
        $this->apiPatch('/api/v1/import/' . $job['id'] . '/mapping', ['columnMapping' => $mapping], $this->headers);
        $this->assertResponseStatusCodeSame(200);

        $this->apiPost('/api/v1/import/' . $job['id'] . '/execute', $options === [] ? [] : ['importOptions' => $options], $this->headers);
        $this->assertResponseStatusCodeSame(200, $this->client->getResponse()->getContent());

        $final = $this->apiGet('/api/v1/import/' . $job['id'], $this->headers)['job'];
        $this->assertSame('completed', $final['status'], 'import errors: ' . json_encode($final['errors'], JSON_UNESCAPED_UNICODE));
        $this->assertSame(0, $final['errorCount'], 'import errors: ' . json_encode($final['errors'], JSON_UNESCAPED_UNICODE));

        return $final;
    }

    protected function revert(string $jobId): void
    {
        $reverted = $this->apiPost('/api/v1/import/' . $jobId . '/revert', [], $this->headers);
        $this->assertResponseStatusCodeSame(200, $this->client->getResponse()->getContent());
        $this->assertSame('reverted', $reverted['job']['status']);
    }

    protected function db(): Connection
    {
        return static::getContainer()->get('doctrine')->getConnection();
    }

    /** @return array<int, array<string, mixed>> */
    protected function invoicesOf(string $jobId): array
    {
        return $this->db()->fetchAllAssociative(
            'SELECT number, direction, subtotal, vat_total, total, currency, invoice_type_code, receiver_name, sender_name, client_id, supplier_id FROM invoice WHERE import_job_id = :j ORDER BY number',
            ['j' => $jobId],
        );
    }

    /** @return array<int, array<string, mixed>> */
    protected function linesOf(string $number, string $jobId): array
    {
        return $this->db()->fetchAllAssociative(
            'SELECT il.description, il.quantity, il.unit_price, il.vat_rate, il.vat_category_code, il.vat_amount, il.line_total FROM invoice_line il INNER JOIN invoice i ON il.invoice_id = i.id WHERE i.import_job_id = :j AND i.number = :n ORDER BY il.position',
            ['j' => $jobId, 'n' => $number],
        );
    }

    protected function companyIsVatPayer(): bool
    {
        return (bool) $this->db()->fetchOne('SELECT vat_payer FROM company WHERE id = :id', ['id' => $this->companyId]);
    }
}
