<?php

namespace App\Tests\Unit;

use App\Service\Anaf\SeriesDetector;
use PHPUnit\Framework\TestCase;

class SeriesDetectorTest extends TestCase
{
    private SeriesDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new SeriesDetector();
    }

    public function testDetectSimple(): void
    {
        $result = $this->detector->detect('WAS0001');
        $this->assertSame('WAS', $result['prefix']);
        $this->assertSame(1, $result['number']);
    }

    public function testDetectWithDash(): void
    {
        $result = $this->detector->detect('FCT-123');
        $this->assertSame('FCT', $result['prefix']);
        $this->assertSame(123, $result['number']);
    }

    public function testDetectLowercase(): void
    {
        $result = $this->detector->detect('abc456');
        $this->assertSame('ABC', $result['prefix']);
        $this->assertSame(456, $result['number']);
    }

    public function testDetectLeadingZeros(): void
    {
        $result = $this->detector->detect('INV00042');
        $this->assertSame('INV', $result['prefix']);
        $this->assertSame(42, $result['number']);
    }

    public function testReturnsNullForEmpty(): void
    {
        $this->assertNull($this->detector->detect(''));
    }

    public function testReturnsNullForNA(): void
    {
        $this->assertNull($this->detector->detect('N/A'));
    }

    public function testReturnsNullForNumberOnly(): void
    {
        $this->assertNull($this->detector->detect('12345'));
    }

    public function testReturnsNullForUnrecognizablePattern(): void
    {
        $this->assertNull($this->detector->detect('123-ABC'));
    }

    public function testPrefixCappedAt10Chars(): void
    {
        // Prefix longer than 10 chars should not match
        $result = $this->detector->detect('ABCDEFGHIJK123');
        $this->assertNull($result);
    }

    public function testPrefixExactly10Chars(): void
    {
        $result = $this->detector->detect('ABCDEFGHIJ123');
        $this->assertSame('ABCDEFGHIJ', $result['prefix']);
        $this->assertSame(123, $result['number']);
    }
}
