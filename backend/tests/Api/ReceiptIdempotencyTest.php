<?php

namespace App\Tests\Api;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\Receipt;
use App\Entity\ReceiptLine;
use App\Enum\ReceiptStatus;

/**
 * Integration tests for the idempotency-key feature on POST /api/v1/receipts.
 *
 * Each test creates its own company context via the shared seeded admin user
 * so they share the same company / series fixture. Tests are stateless relative
 * to each other because each uses a unique randomly-generated key.
 *
 * Header precedence: when both `Idempotency-Key` (header) and `idempotencyKey`
 * (body) are sent, the header wins. ReceiptController::create() unconditionally
 * copies the header into `$data` when present, overriding any body field.
 */
class ReceiptIdempotencyTest extends ApiTestCase
{
    // -------------------------------------------------------------------------
    // Helpers shared with ReceiptCrudTest pattern
    // -------------------------------------------------------------------------

    private function minimalReceiptBody(): array
    {
        return [
            'issueDate'     => '2026-04-25',
            'currency'      => 'RON',
            'paymentMethod' => 'cash',
            'lines'         => [
                [
                    'description'   => 'Produs idempotency test',
                    'quantity'      => '1.00',
                    'unitOfMeasure' => 'buc',
                    'unitPrice'     => '100.00',
                    'vatRate'       => '19.00',
                    'vatCategoryCode' => 'S',
                    'discount'      => '0.00',
                    'discountPercent' => '0.00',
                    'vatIncluded'   => false,
                ],
            ],
        ];
    }

    private function createReceiptSeries(string $companyId): array
    {
        $prefix = 'IDEMP' . substr(md5(uniqid('', true) . microtime(true)), 0, 6);
        $series = $this->apiPost('/api/v1/document-series', [
            'prefix'        => $prefix,
            'type'          => 'receipt',
            'currentNumber' => 0,
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(201);

        return $series;
    }

    // -------------------------------------------------------------------------
    // Test 1 — Body field deduplication
    // -------------------------------------------------------------------------

    /**
     * POST twice with the same idempotencyKey in the body.
     * Both must return HTTP 201 and the exact same receipt id.
     * Only one row should exist in the database for that key.
     */
    public function testBodyKeyDeduplicatesReceipt(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $key  = 'body-key-' . uniqid('', true);
        $body = array_merge($this->minimalReceiptBody(), ['idempotencyKey' => $key]);

        $first = $this->apiPost('/api/v1/receipts', $body, ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(201, 'First POST should return 201');
        $this->assertArrayHasKey('id', $first);

        $second = $this->apiPost('/api/v1/receipts', $body, ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(201, 'Second POST (same key) should also return 201');
        $this->assertArrayHasKey('id', $second);

        $this->assertSame(
            $first['id'],
            $second['id'],
            'Both responses must return the same receipt id',
        );

        // Verify only one row in the database carries this key
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $count = $em->getRepository(Receipt::class)->count(['idempotencyKey' => $key]);
        $this->assertSame(1, $count, 'Exactly one receipt must exist for this idempotency key');
    }

    // -------------------------------------------------------------------------
    // Test 2 — Header vs body precedence (documents the actual behaviour)
    // -------------------------------------------------------------------------

    /**
     * Header `Idempotency-Key` takes precedence over body `idempotencyKey`
     * when both are present — clients setting both intentionally are
     * expressing their preference via the standard HTTP header.
     */
    public function testHeaderKeyTakesPrecedenceOverBodyKey(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $keyA = 'header-key-A-' . uniqid('', true);
        $keyB = 'body-key-B-' . uniqid('', true);

        // POST 1: header=A, body=B → header wins, persisted with key A
        $body1 = array_merge($this->minimalReceiptBody(), ['idempotencyKey' => $keyB]);
        $first = $this->apiPost(
            '/api/v1/receipts',
            $body1,
            ['X-Company' => $companyId, 'Idempotency-Key' => $keyA],
        );
        $this->assertResponseStatusCodeSame(201);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $receiptByKeyA = $em->getRepository(Receipt::class)->findOneBy(['idempotencyKey' => $keyA]);
        $receiptByKeyB = $em->getRepository(Receipt::class)->findOneBy(['idempotencyKey' => $keyB]);

        $this->assertNotNull(
            $receiptByKeyA,
            'Header key A wins when both header and body keys are sent',
        );
        $this->assertNull(
            $receiptByKeyB,
            'Body key B must NOT be stored when a header is also sent',
        );
        $this->assertSame(
            $first['id'],
            (string) $receiptByKeyA->getId(),
            'Receipt returned in POST 1 must be the one stored with key A',
        );

        // POST 2: same header A, different body key → still dedupes against POST 1
        $body2 = array_merge($this->minimalReceiptBody(), ['idempotencyKey' => 'unrelated-' . uniqid()]);
        $second = $this->apiPost(
            '/api/v1/receipts',
            $body2,
            ['X-Company' => $companyId, 'Idempotency-Key' => $keyA],
        );
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame(
            $first['id'],
            $second['id'],
            'POST 2 with the same header A must dedupe to POST 1 regardless of body key',
        );

        // POST 3: body B alone (no header) → creates a new receipt because B was never used
        $body3 = array_merge($this->minimalReceiptBody(), ['idempotencyKey' => $keyB]);
        $third = $this->apiPost(
            '/api/v1/receipts',
            $body3,
            ['X-Company' => $companyId],
        );
        $this->assertResponseStatusCodeSame(201);
        $this->assertNotSame(
            $first['id'],
            $third['id'],
            'POST 3 (body B, no header) must NOT dedupe against POST 1 (which used header A)',
        );
    }

    // -------------------------------------------------------------------------
    // Test 3 — Different keys → different receipts
    // -------------------------------------------------------------------------

    /**
     * Two distinct idempotency keys must produce two distinct receipts.
     */
    public function testDifferentKeysMakeDifferentReceipts(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $keyAlpha = 'key-alpha-' . uniqid('', true);
        $keyBeta  = 'key-beta-'  . uniqid('', true);

        $bodyAlpha = array_merge($this->minimalReceiptBody(), ['idempotencyKey' => $keyAlpha]);
        $bodyBeta  = array_merge($this->minimalReceiptBody(), ['idempotencyKey' => $keyBeta]);

        $alpha = $this->apiPost('/api/v1/receipts', $bodyAlpha, ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(201);

        $beta = $this->apiPost('/api/v1/receipts', $bodyBeta, ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(201);

        $this->assertNotSame(
            $alpha['id'],
            $beta['id'],
            'Different idempotency keys must produce different receipts',
        );
    }

    // -------------------------------------------------------------------------
    // Test 4 — No key → no deduplication
    // -------------------------------------------------------------------------

    /**
     * Two POST requests without any idempotency key must produce two distinct
     * receipts. ReceiptManager has no fingerprint-based fallback dedup.
     */
    public function testNoKeyProducesDuplicateReceipts(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $body = $this->minimalReceiptBody(); // no idempotencyKey field

        $first = $this->apiPost('/api/v1/receipts', $body, ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(201);

        $second = $this->apiPost('/api/v1/receipts', $body, ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(201);

        $this->assertNotSame(
            $first['id'],
            $second['id'],
            'Without an idempotency key, two identical POSTs must create two distinct receipts',
        );
    }

    // -------------------------------------------------------------------------
    // Test 5 — Cross-company isolation (or lack thereof — current limitation)
    // -------------------------------------------------------------------------

    /**
     * CURRENT LIMITATION DOCUMENTED HERE.
     *
     * The unique index on receipt.idempotency_key is GLOBAL — there is no
     * compound index on (company_id, idempotency_key). This means:
     *
     *   - Company A uses key K → OK.
     *   - Company B tries to use key K → the findOneBy() in ReceiptManager will
     *     find Company A's receipt and RETURN IT to Company B's request, leaking
     *     another company's document. Alternatively, if the key is truly distinct
     *     across companies, a collision would occur.
     *
     * For random UUIDs (v4/v7) used as idempotency keys the probability of
     * collision is negligible in practice, but the semantic guarantee is absent
     * and the data-leak risk is real when a key chosen by one company happens to
     * match a key chosen by another.
     *
     * This test demonstrates the limitation by confirming that the same key used
     * by two different companies will collide at the DB level (UniqueConstraint).
     * It uses the single seeded admin user who belongs to one company, so we
     * simulate the scenario by inspecting what would happen if two companies
     * chose the same short key string (e.g. "sale-001").
     *
     * Since the test environment has only one company available through the admin
     * user, we test the observable symptom indirectly:
     *
     *   POST key K as company A → receipt R1.
     *   Attempt to bypass manager and insert a second receipt with key K via EM.
     *   Expect UniqueConstraintViolationException (same as what Company B would
     *   hit if it tried to use key K after Company A already used it).
     *
     * If you add multi-company support, the correct fix is to change the unique
     * index to (company_id, idempotency_key) and update ReceiptManager::create()
     * to scope the findOneBy() to the current company.
     */
    public function testCrossCompanyIdempotencyCollisionDocumentedAsLimitation(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $sharedKey = 'shared-key-' . uniqid('', true);

        // Company A creates a receipt with this key via the normal API path
        $body = array_merge($this->minimalReceiptBody(), ['idempotencyKey' => $sharedKey]);
        $this->apiPost('/api/v1/receipts', $body, ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(201);

        // Attempt to insert a second receipt with the SAME key directly via
        // EntityManager (bypassing ReceiptManager), simulating what would happen
        // if another company's request reached the DB after the manager's guard
        // has already let it through (race condition or cross-company scenario).
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        // Fetch a Company entity to attach to the "other" receipt
        $company = $em->getRepository(\App\Entity\Company::class)->find($companyId);
        $this->assertNotNull($company, 'Company must exist in the test database');

        $duplicate = new Receipt();
        $duplicate->setCompany($company);
        $duplicate->setStatus(ReceiptStatus::DRAFT);
        $duplicate->setIdempotencyKey($sharedKey); // same key — will violate the UNIQUE index
        $duplicate->setNumber('COLLISION-TEST');
        $duplicate->setCurrency('RON');
        $duplicate->setIssueDate(new \DateTime());

        $em->persist($duplicate);

        try {
            $em->flush();
            // If we reach here the UNIQUE constraint is missing — that is a bug.
            $this->fail(
                'Expected a UniqueConstraintViolationException because the UNIQUE index on ' .
                'receipt.idempotency_key must prevent two rows sharing the same key. ' .
                'If this line is reached the DB constraint is absent.',
            );
        } catch (UniqueConstraintViolationException $e) {
            // Expected: the DB-level UNIQUE constraint is working as a last line of defence.
            $this->addToAssertionCount(1);
        } finally {
            // Roll the EntityManager back so subsequent tests start clean
            $em->clear();
        }
    }

    // -------------------------------------------------------------------------
    // Test 6 — Replay after issue
    // -------------------------------------------------------------------------

    /**
     * Create a receipt with key K, issue it, then re-POST create with key K.
     * The already-issued receipt must be returned unchanged; status must not
     * revert to draft.
     */
    public function testReplayAfterIssueReturnsIssuedReceipt(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $series = $this->createReceiptSeries($companyId);
        $key    = 'replay-issued-' . uniqid('', true);
        $body   = array_merge($this->minimalReceiptBody(), [
            'idempotencyKey'   => $key,
            'documentSeriesId' => $series['id'],
        ]);

        // Create
        $created = $this->apiPost('/api/v1/receipts', $body, ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame('draft', $created['status']);

        // Issue
        $issued = $this->apiPost(
            '/api/v1/receipts/' . $created['id'] . '/issue',
            [],
            ['X-Company' => $companyId],
        );
        $this->assertResponseStatusCodeSame(200);
        $this->assertSame('issued', $issued['status']);

        // Re-POST create with same key → must return the already-issued receipt
        $replay = $this->apiPost('/api/v1/receipts', $body, ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(201);

        $this->assertSame(
            $created['id'],
            $replay['id'],
            'Replay after issue must return the same receipt id',
        );

        // Verify the status was NOT reset to draft
        $detail = $this->apiGet(
            '/api/v1/receipts/' . $created['id'],
            ['X-Company' => $companyId],
        );
        $this->assertResponseStatusCodeSame(200);
        $this->assertSame(
            'issued',
            $detail['status'],
            'Status must remain "issued" — re-POSTing with the same key must not revert to draft',
        );
    }

    // -------------------------------------------------------------------------
    // Test 7 — Database UNIQUE constraint enforced at the storage level
    // -------------------------------------------------------------------------

    /**
     * Bypass ReceiptManager and insert two Receipt entities with the same
     * idempotencyKey via EntityManager directly.
     * Doctrine must throw UniqueConstraintViolationException, proving the DB
     * constraint is the last line of defence against concurrent duplicates.
     */
    public function testDatabaseUniqueConstraintViaEntityManager(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        /** @var EntityManagerInterface $em */
        $em      = static::getContainer()->get(EntityManagerInterface::class);
        $company = $em->getRepository(\App\Entity\Company::class)->find($companyId);
        $this->assertNotNull($company);

        $sharedKey = 'db-unique-' . uniqid('', true);

        $r1 = new Receipt();
        $r1->setCompany($company);
        $r1->setStatus(ReceiptStatus::DRAFT);
        $r1->setIdempotencyKey($sharedKey);
        $r1->setNumber('DB-UNIQUE-1');
        $r1->setCurrency('RON');
        $r1->setIssueDate(new \DateTime());

        $r2 = new Receipt();
        $r2->setCompany($company);
        $r2->setStatus(ReceiptStatus::DRAFT);
        $r2->setIdempotencyKey($sharedKey); // identical key
        $r2->setNumber('DB-UNIQUE-2');
        $r2->setCurrency('RON');
        $r2->setIssueDate(new \DateTime());

        $em->persist($r1);
        $em->flush(); // first insert must succeed

        $em->persist($r2);

        try {
            $em->flush(); // second insert must violate the UNIQUE constraint
            $this->fail(
                'UniqueConstraintViolationException expected when inserting a second ' .
                'receipt with the same idempotencyKey via EntityManager.',
            );
        } catch (UniqueConstraintViolationException $e) {
            $this->addToAssertionCount(1);
        } finally {
            $em->clear();
        }
    }
}
