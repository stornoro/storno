<?php

namespace App\Tests\Api;

/**
 * Tests for Product.color (hex swatch) introduced in Version20260425180000.
 *
 * Each test is fully self-contained: it creates its own product so the suite
 * can run in any order and against a freshly-seeded test database.
 */
class ProductColorTest extends ApiTestCase
{
    // ──────────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Minimum valid product payload for company-1 (org-1).
     * Accepts an optional array of overrides to merge in.
     */
    private function minimalProductPayload(array $overrides = []): array
    {
        return array_merge([
            'name'          => 'Color-test product ' . uniqid('', true),
            'unitOfMeasure' => 'buc',
            'vatRate'       => '21',
            'defaultPrice'  => '10.00',
        ], $overrides);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 1. POST with a valid, already-normalised hex color → 201, color echoed back
    // ──────────────────────────────────────────────────────────────────────────

    public function testPostWithValidHexColorPersists(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $data = $this->apiPost(
            '/api/v1/products',
            $this->minimalProductPayload(['color' => '#1e40af']),
            ['X-Company' => $companyId]
        );

        $this->assertResponseStatusCodeSame(201);
        $this->assertArrayHasKey('color', $data);
        $this->assertSame('#1e40af', $data['color']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 2. PATCH with "1E40AF" (no hash, uppercase) → 200, normalized to "#1e40af"
    // ──────────────────────────────────────────────────────────────────────────

    public function testPatchNormalizesColorWithoutHashAndUppercase(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        // Create a product first
        $created = $this->apiPost(
            '/api/v1/products',
            $this->minimalProductPayload(),
            ['X-Company' => $companyId]
        );
        $this->assertResponseStatusCodeSame(201);
        $productId = $created['id'];

        // Patch with no-hash, uppercase hex
        $updated = $this->apiPatch(
            '/api/v1/products/' . $productId,
            ['color' => '1E40AF'],
            ['X-Company' => $companyId]
        );

        $this->assertResponseStatusCodeSame(200);
        $this->assertSame('#1e40af', $updated['color'], 'Expected color to be normalized to "#1e40af"');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 3. POST with invalid color string → 201, color is null (silently dropped)
    // ──────────────────────────────────────────────────────────────────────────

    public function testPostWithInvalidColorYieldsNull(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $data = $this->apiPost(
            '/api/v1/products',
            $this->minimalProductPayload(['color' => 'not-a-color']),
            ['X-Company' => $companyId]
        );

        $this->assertResponseStatusCodeSame(201);
        $this->assertArrayHasKey('color', $data);
        $this->assertNull($data['color'], 'Invalid color string must be silently dropped (null)');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 4. POST without color key → 201, color is null
    // ──────────────────────────────────────────────────────────────────────────

    public function testPostWithoutColorKeyYieldsNull(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        $data = $this->apiPost(
            '/api/v1/products',
            $this->minimalProductPayload(), // no color key
            ['X-Company' => $companyId]
        );

        $this->assertResponseStatusCodeSame(201);
        $this->assertArrayHasKey('color', $data);
        $this->assertNull($data['color']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 5. PATCH with color: "" (empty string) → 200, color cleared to null
    // ──────────────────────────────────────────────────────────────────────────

    public function testPatchEmptyStringClearsColor(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        // Start with a color
        $created = $this->apiPost(
            '/api/v1/products',
            $this->minimalProductPayload(['color' => '#ff0000']),
            ['X-Company' => $companyId]
        );
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame('#ff0000', $created['color']);

        $productId = $created['id'];

        // Clear it via empty string
        $updated = $this->apiPatch(
            '/api/v1/products/' . $productId,
            ['color' => ''],
            ['X-Company' => $companyId]
        );

        $this->assertResponseStatusCodeSame(200);
        $this->assertNull($updated['color'], 'Empty string color must be stored as null');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 6. GET list → every item in data[] has a "color" key (even if null)
    // ──────────────────────────────────────────────────────────────────────────

    public function testListResponseIncludesColorKeyOnEachProduct(): void
    {
        $this->login();
        $companyId = $this->getFirstCompanyId();

        // Seed one product without color and one with color to cover both cases
        $this->apiPost(
            '/api/v1/products',
            $this->minimalProductPayload(),
            ['X-Company' => $companyId]
        );
        $this->assertResponseStatusCodeSame(201);

        $this->apiPost(
            '/api/v1/products',
            $this->minimalProductPayload(['color' => '#abc123']),
            ['X-Company' => $companyId]
        );
        $this->assertResponseStatusCodeSame(201);

        $list = $this->apiGet('/api/v1/products', ['X-Company' => $companyId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertNotEmpty($list['data'], 'Product list must not be empty');

        foreach ($list['data'] as $item) {
            $this->assertArrayHasKey(
                'color',
                $item,
                "Every product in the list must expose the 'color' key (even when null). Missing on product id=" . ($item['id'] ?? '?')
            );
        }
    }
}
