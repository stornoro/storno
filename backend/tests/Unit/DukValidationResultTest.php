<?php

namespace App\Tests\Unit;

use App\Model\Declaration\DukValidationResult;
use PHPUnit\Framework\TestCase;

class DukValidationResultTest extends TestCase
{
    public function testValidResult(): void
    {
        $result = new DukValidationResult(valid: true);
        $this->assertTrue($result->valid);
        $this->assertEmpty($result->errors);
        $this->assertEmpty($result->warnings);
    }

    public function testInvalidResultWithErrors(): void
    {
        $result = new DukValidationResult(
            valid: false,
            errors: ['Field X is required', 'Invalid CIF'],
        );
        $this->assertFalse($result->valid);
        $this->assertCount(2, $result->errors);
        $this->assertSame('Field X is required', $result->errors[0]);
        $this->assertSame('Invalid CIF', $result->errors[1]);
    }

    public function testValidResultWithWarnings(): void
    {
        $result = new DukValidationResult(
            valid: true,
            warnings: ['WARNING: Field Y is deprecated'],
        );
        $this->assertTrue($result->valid);
        $this->assertEmpty($result->errors);
        $this->assertCount(1, $result->warnings);
    }

    public function testInvalidResultWithErrorsAndWarnings(): void
    {
        $result = new DukValidationResult(
            valid: false,
            errors: ['Missing CIF'],
            warnings: ['WARNING: Old format'],
        );
        $this->assertFalse($result->valid);
        $this->assertCount(1, $result->errors);
        $this->assertCount(1, $result->warnings);
    }

    public function testReadonlyProperties(): void
    {
        $result = new DukValidationResult(valid: true, errors: ['test'], warnings: ['warn']);

        // Properties should be readonly — verify they're accessible
        $this->assertTrue($result->valid);
        $this->assertSame(['test'], $result->errors);
        $this->assertSame(['warn'], $result->warnings);
    }
}
