<?php

namespace App\Tests\Api;

/**
 * Tests for ProductCategory CRUD (Version20260425200000) and its integration
 * with Product.categoryId (ProductController).
 *
 * Isolation strategy
 * ──────────────────
 * Every test that creates categories does so inside Company A (the first company
 * that belongs to the org-1 admin).  Cross-company isolation tests additionally
 * use Company B from a different organization (org-2), authenticated as
 * user@localhost.com who is the owner of org-2.
 *
 * Fixture recap (from DataFixtures)
 * ──────────────────────────────────
 *   admin@localhost.com  → org-1 (ADMIN role)    → companies 1-3
 *   user@localhost.com   → org-2 (OWNER role)    → companies 4-5
 *   angajat@localhost.com → org-1 (EMPLOYEE role) → view-only (no product.edit)
 */
class ProductCategoryTest extends ApiTestCase
{
    // ──────────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    private function minimalProductPayload(array $overrides = []): array
    {
        return array_merge([
            'name'          => 'Cat-test product ' . uniqid('', true),
            'unitOfMeasure' => 'buc',
            'vatRate'       => '21',
            'defaultPrice'  => '10.00',
        ], $overrides);
    }

    /**
     * Create a category and return the full response body.
     * Asserts 201 so that callers can rely on the returned data being valid.
     */
    private function createCategory(string $companyId, array $payload = []): array
    {
        $defaults = [
            'name'      => 'Category ' . uniqid('', true),
            'color'     => '#7c3aed',
            'sortOrder' => 0,
        ];

        $data = $this->apiPost(
            '/api/v1/product-categories',
            array_merge($defaults, $payload),
            ['X-Company' => $companyId]
        );

        $this->assertResponseStatusCodeSame(201, 'createCategory() helper failed; response: ' . json_encode($data));

        return $data;
    }

    /**
     * Create a product and return its response body (201 asserted).
     */
    private function createProduct(string $companyId, array $overrides = []): array
    {
        $data = $this->apiPost(
            '/api/v1/products',
            $this->minimalProductPayload($overrides),
            ['X-Company' => $companyId]
        );

        $this->assertResponseStatusCodeSame(201, 'createProduct() helper failed; response: ' . json_encode($data));

        return $data;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 1. GET empty list — a fresh company context yields { data: [] }
    // ──────────────────────────────────────────────────────────────────────────

    public function testGetEmptyList(): void
    {
        // ion.popescu@gmail.com owns org-3 which has a single company and no
        // categories seeded, giving us a reliable "empty" state.
        $this->login('ion.popescu@gmail.com');
        $companyId = $this->getFirstCompanyId();

        $data = $this->apiGet('/api/v1/product-categories', ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertArrayHasKey('data', $data);
        $this->assertIsArray($data['data']);
        $this->assertCount(0, $data['data'], 'A freshly seeded company must have zero categories');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 2. POST create — full payload, 201, all fields in response
    // ──────────────────────────────────────────────────────────────────────────

    public function testPostCreate(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $data = $this->apiPost('/api/v1/product-categories', [
            'name'      => 'Cafele',
            'color'     => '#7c3aed',
            'sortOrder' => 0,
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(201);
        $this->assertArrayHasKey('id', $data);
        $this->assertSame('Cafele', $data['name']);
        $this->assertSame('#7c3aed', $data['color']);
        $this->assertSame(0, $data['sortOrder']);
        $this->assertArrayHasKey('createdAt', $data);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 3. POST without name → 400 with specific error message
    // ──────────────────────────────────────────────────────────────────────────

    public function testPostWithoutNameReturns400(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $data = $this->apiPost('/api/v1/product-categories', [
            'color'     => '#ff0000',
            'sortOrder' => 1,
        ], ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(400);
        $this->assertArrayHasKey('error', $data);
        $this->assertSame('name is required.', $data['error']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 4. GET list ordering — sorted ascending by sortOrder, then alphabetically
    // ──────────────────────────────────────────────────────────────────────────

    public function testListReturnsSortedBySortOrderThenName(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        // Per-run suffix so accumulated rows from prior test runs against the
        // shared dev/test fixture don't contaminate the assertion.
        $suffix = '-' . substr(uniqid('', true), -8);

        $this->createCategory($companyId, ['name' => 'Branza' . $suffix, 'sortOrder' => 2]);
        $this->createCategory($companyId, ['name' => 'Alune'  . $suffix, 'sortOrder' => 0]);
        $this->createCategory($companyId, ['name' => 'Ceai'   . $suffix, 'sortOrder' => 1]);

        $data = $this->apiGet('/api/v1/product-categories', ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);

        $returned = array_values(array_filter(
            array_column($data['data'], 'name'),
            fn (string $n) => str_ends_with($n, $suffix),
        ));

        $this->assertSame(
            ['Alune' . $suffix, 'Ceai' . $suffix, 'Branza' . $suffix],
            $returned,
            'Categories must be ordered by sortOrder asc',
        );
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 5. PATCH update — change name + color, updatedAt is set
    // ──────────────────────────────────────────────────────────────────────────

    public function testPatchUpdate(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $cat = $this->createCategory($companyId, ['name' => 'Original', 'color' => '#000000']);
        $catId = $cat['id'];

        $this->assertNull($cat['updatedAt'] ?? null, 'updatedAt should be null before any update');

        $updated = $this->apiPatch(
            '/api/v1/product-categories/' . $catId,
            ['name' => 'Renamed', 'color' => '#ffffff'],
            ['X-Company' => $companyId]
        );

        $this->assertResponseStatusCodeSame(200);
        $this->assertSame('Renamed', $updated['name']);
        $this->assertSame('#ffffff', $updated['color']);
        $this->assertNotNull($updated['updatedAt'], 'updatedAt must be set after PATCH');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 6. PATCH empty name → 400
    // ──────────────────────────────────────────────────────────────────────────

    public function testPatchEmptyNameReturns400(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $cat = $this->createCategory($companyId);

        $data = $this->apiPatch(
            '/api/v1/product-categories/' . $cat['id'],
            ['name' => ''],
            ['X-Company' => $companyId]
        );

        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('name cannot be empty.', $data['error']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 7. DELETE → 200 { message: "Category deleted." }; subsequent GET list
    //    no longer includes the deleted category
    // ──────────────────────────────────────────────────────────────────────────

    public function testDeleteCategory(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $cat = $this->createCategory($companyId, ['name' => 'ToDelete']);
        $catId = $cat['id'];

        $deleteResp = $this->apiDelete(
            '/api/v1/product-categories/' . $catId,
            ['X-Company' => $companyId]
        );

        $this->assertResponseStatusCodeSame(200);
        $this->assertSame('Category deleted.', $deleteResp['message']);

        // Verify it no longer appears in the list
        $list = $this->apiGet('/api/v1/product-categories', ['X-Company' => $companyId]);
        $ids = array_column($list['data'], 'id');
        $this->assertNotContains($catId, $ids, 'Deleted category must not appear in the list');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 8. DELETE removes the category link from products but NOT the products
    //    themselves (ON DELETE SET NULL in the FK).
    // ──────────────────────────────────────────────────────────────────────────

    public function testDeleteCategoryNullifiesProductCategoryLink(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        // Create a category
        $cat = $this->createCategory($companyId, ['name' => 'LinkTest']);

        // Create a product linked to that category
        $product = $this->createProduct($companyId, ['categoryId' => $cat['id']]);
        $productId = $product['id'];

        // Confirm the link is set on create
        $detail = $this->apiGet('/api/v1/products/' . $productId, ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertNotNull($detail['category'], 'Product must have a category before deletion');
        $this->assertSame($cat['id'], $detail['category']['id']);

        // Delete the category
        $this->apiDelete('/api/v1/product-categories/' . $cat['id'], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200);

        // The product must still exist
        $afterDelete = $this->apiGet('/api/v1/products/' . $productId, ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(200, 'Product must still exist after its category is deleted');

        // …but its category link must now be null
        $this->assertNull(
            $afterDelete['category'],
            'Product.category must be null after the linked category is deleted (ON DELETE SET NULL)'
        );
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 9. Cross-company isolation — a category created by org-1 is invisible
    //    to org-2's company (GET returns 404 or empty; PATCH/DELETE return 404)
    // ──────────────────────────────────────────────────────────────────────────

    public function testCrossCompanyIsolation(): void
    {
        // ── Org-1 (admin@localhost.com) creates a category ──
        $this->login('admin@localhost.com');
        $companyA = $this->getFirstCompanyId(); // company-1 (org-1)

        $cat = $this->createCategory($companyA, ['name' => 'Org1-only']);
        $catId = $cat['id'];

        // ── Org-2 (user@localhost.com) tries to interact with it ──
        $this->login('user@localhost.com');
        $companyB = $this->getFirstCompanyId(); // company-4 (org-2)

        // GET list: must not include the org-1 category
        $list = $this->apiGet('/api/v1/product-categories', ['X-Company' => $companyB]);
        $this->assertResponseStatusCodeSame(200);
        $ids = array_column($list['data'], 'id');
        $this->assertNotContains($catId, $ids, 'Org-2 list must not expose an org-1 category');

        // PATCH: must return 404
        $this->apiPatch(
            '/api/v1/product-categories/' . $catId,
            ['name' => 'Hijacked'],
            ['X-Company' => $companyB]
        );
        $this->assertResponseStatusCodeSame(404, 'Org-2 must not be able to PATCH an org-1 category');

        // DELETE: must return 404
        $this->apiDelete(
            '/api/v1/product-categories/' . $catId,
            ['X-Company' => $companyB]
        );
        $this->assertResponseStatusCodeSame(404, 'Org-2 must not be able to DELETE an org-1 category');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 10. Permissions — EMPLOYEE role (view-only) cannot POST/PATCH/DELETE
    // ──────────────────────────────────────────────────────────────────────────

    public function testEmployeeCannotMutateCategories(): void
    {
        // angajat@localhost.com is EMPLOYEE in org-1 → has product.view but NOT product.edit
        $this->login('angajat@localhost.com');

        // org-1 has multiple companies; the employee has no restriction so they
        // auto-resolve to the first company.  We need to explicitly pass the
        // company header because the auto-resolve only kicks in when there's
        // exactly one company.
        $this->login('admin@localhost.com');
        $companyId = $this->getFirstCompanyId();

        // Pre-create a category as admin so we have something to target
        $cat = $this->createCategory($companyId, ['name' => 'PermTarget']);

        // Now switch to the employee
        $this->login('angajat@localhost.com');

        // POST → 403
        $this->apiPost('/api/v1/product-categories', [
            'name'      => 'Forbidden create',
            'sortOrder' => 0,
        ], ['X-Company' => $companyId]);
        $this->assertResponseStatusCodeSame(403, 'Employee POST must be 403');

        // PATCH → 403
        $this->apiPatch(
            '/api/v1/product-categories/' . $cat['id'],
            ['name' => 'Forbidden update'],
            ['X-Company' => $companyId]
        );
        $this->assertResponseStatusCodeSame(403, 'Employee PATCH must be 403');

        // DELETE → 403
        $this->apiDelete(
            '/api/v1/product-categories/' . $cat['id'],
            ['X-Company' => $companyId]
        );
        $this->assertResponseStatusCodeSame(403, 'Employee DELETE must be 403');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 11. Product.create with a valid categoryId — product persisted with
    //     category; product:detail response contains category sub-object
    // ──────────────────────────────────────────────────────────────────────────

    public function testProductCreateWithValidCategoryId(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $cat = $this->createCategory($companyId, [
            'name'      => 'ValidCategory',
            'color'     => '#aabbcc',
            'sortOrder' => 5,
        ]);

        $product = $this->createProduct($companyId, ['categoryId' => $cat['id']]);
        $productId = $product['id'];

        // Fetch detail
        $detail = $this->apiGet('/api/v1/products/' . $productId, ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertNotNull($detail['category'], 'Product:detail must include the category sub-object');
        $this->assertSame($cat['id'], $detail['category']['id']);
        $this->assertSame('ValidCategory', $detail['category']['name']);
        $this->assertSame('#aabbcc', $detail['category']['color']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 12. Product.create with a categoryId from a different company → silently
    //     ignored; product persisted with category: null
    // ──────────────────────────────────────────────────────────────────────────

    public function testProductCreateWithCrossCompanyCategoryIdIgnored(): void
    {
        // Create a category in org-2 (user@localhost.com)
        $this->login('user@localhost.com');
        $companyB = $this->getFirstCompanyId(); // org-2 company
        $alienCat = $this->createCategory($companyB, ['name' => 'Alien category']);

        // Switch to org-1 and try to attach the org-2 category
        $this->login('admin@localhost.com');
        $companyA = $this->getFirstCompanyId(); // org-1 company

        $product = $this->createProduct($companyA, ['categoryId' => $alienCat['id']]);

        $this->assertNull(
            $product['category'],
            'Cross-company categoryId must be silently dropped; product.category must be null'
        );
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 13. Product.update with categoryId: null → 200, product.category cleared
    // ──────────────────────────────────────────────────────────────────────────

    public function testProductUpdateWithNullCategoryIdClearsCategory(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        // Create a category and a product linked to it
        $cat     = $this->createCategory($companyId, ['name' => 'ClearMe']);
        $product = $this->createProduct($companyId, ['categoryId' => $cat['id']]);

        $this->assertNotNull($product['category'], 'Product must start with a category set');

        // Now clear the category by PATCHing with null
        $updated = $this->apiPatch(
            '/api/v1/products/' . $product['id'],
            ['categoryId' => null],
            ['X-Company' => $companyId]
        );

        $this->assertResponseStatusCodeSame(200);
        $this->assertNull($updated['category'], 'Product.category must be null after PATCH with categoryId: null');
    }
}
