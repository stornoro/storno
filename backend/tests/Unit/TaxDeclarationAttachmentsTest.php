<?php

namespace App\Tests\Unit;

use App\Entity\TaxDeclaration;
use PHPUnit\Framework\TestCase;

final class TaxDeclarationAttachmentsTest extends TestCase
{
    public function testApiDataExposesAttachmentsWithoutContent(): void
    {
        $content = str_repeat('x', 1000);
        $declaration = (new TaxDeclaration())->setData([
            'input' => ['an' => 2026],
            'attachments' => [['name' => 'contract.pdf', 'contentBase64' => base64_encode($content)]],
        ]);

        $api = $declaration->getDataForApi();

        self::assertSame(['an' => 2026], $api['input']);
        self::assertSame('contract.pdf', $api['attachments'][0]['name']);
        self::assertSame(1000, $api['attachments'][0]['size']);
        self::assertSame('application/pdf', $api['attachments'][0]['mime']);
        self::assertTrue($api['attachments'][0]['stored']);
        self::assertArrayNotHasKey('contentBase64', $api['attachments'][0]);
        // The stored data is untouched.
        self::assertArrayHasKey('contentBase64', $declaration->getData()['attachments'][0]);
    }

    public function testApiDataWithoutAttachmentsIsUnchanged(): void
    {
        $declaration = (new TaxDeclaration())->setData(['rows' => ['R1' => 10]]);

        self::assertSame(['rows' => ['R1' => 10]], $declaration->getDataForApi());
        self::assertNull((new TaxDeclaration())->getDataForApi());
    }

    public function testMergeKeepsStoredContentWhenClientSendsTheApiShapeBack(): void
    {
        $stored = ['input' => ['an' => 2026], 'attachments' => [['name' => 'contract.pdf', 'contentBase64' => 'QUJD']]];

        $merged = TaxDeclaration::mergeAttachments($stored, [
            'input' => ['an' => 2027],
            'attachments' => [['name' => 'contract.pdf', 'size' => 3, 'mime' => 'application/pdf', 'stored' => true]],
        ]);

        self::assertSame(['an' => 2027], $merged['input']);
        self::assertSame('QUJD', $merged['attachments'][0]['contentBase64']);
    }

    public function testMergeKeepsStoredAttachmentsWhenClientOmitsThem(): void
    {
        $stored = ['attachments' => [['name' => 'contract.pdf', 'contentBase64' => 'QUJD']]];

        $merged = TaxDeclaration::mergeAttachments($stored, ['input' => ['an' => 2026]]);

        self::assertSame('QUJD', $merged['attachments'][0]['contentBase64']);
    }

    public function testMergeAcceptsNewContentAndDropsRemovedFiles(): void
    {
        $stored = ['attachments' => [['name' => 'old.pdf', 'contentBase64' => 'QUJD']]];

        $merged = TaxDeclaration::mergeAttachments($stored, ['attachments' => [['name' => 'new.pdf', 'contentBase64' => 'REVG']]]);

        self::assertCount(1, $merged['attachments']);
        self::assertSame('new.pdf', $merged['attachments'][0]['name']);
        self::assertSame('REVG', $merged['attachments'][0]['contentBase64']);
    }
}
