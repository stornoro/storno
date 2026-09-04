<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\Security\OutboundUrlPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OutboundUrlPolicyTest extends TestCase
{
    /** @var array<string, array<int, string>> */
    private const DNS = [
        'example.com' => ['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946'],
        'only-v6.example.com' => ['2606:2800:220:1:248:1893:25c8:1946'],
        'evil-loopback.example.com' => ['127.0.0.1'],
        'evil-rfc1918.example.com' => ['93.184.216.34', '10.0.0.5'],
        'evil-linklocal.example.com' => ['169.254.169.254'],
        'evil-cgnat.example.com' => ['100.64.1.1'],
        'evil-ula.example.com' => ['fd00::1'],
        'evil-v6-linklocal.example.com' => ['fe80::1'],
        'evil-mapped.example.com' => ['::ffff:93.184.216.34'],
        'evil-multicast.example.com' => ['224.0.0.1'],
        'evil-zero.example.com' => ['0.0.0.0'],
        'unresolvable.example.com' => [],
    ];

    private function policy(): OutboundUrlPolicy
    {
        return new OutboundUrlPolicy(static fn (string $host): array => self::DNS[$host] ?? []);
    }

    public function testAcceptsPublicHttpsUrlAndReturnsTrimmedUrl(): void
    {
        $this->assertSame(
            'https://example.com/hook?x=1',
            $this->policy()->assertAllowed("  https://example.com/hook?x=1 \n"),
        );
    }

    public function testAcceptsPublicHttpUrlWhenHttpsNotRequired(): void
    {
        $this->assertSame('http://example.com/', $this->policy()->assertAllowed('http://example.com/'));
    }

    public function testAcceptsIpv6OnlyPublicHost(): void
    {
        $this->assertSame('https://only-v6.example.com', $this->policy()->assertAllowed('https://only-v6.example.com'));
    }

    public function testAcceptsPublicIpLiteral(): void
    {
        $this->assertSame('https://93.184.216.34/x', $this->policy()->assertAllowed('https://93.184.216.34/x'));
    }

    public function testAcceptsAllowedPort(): void
    {
        $this->assertSame(
            'https://example.com:443/hook',
            $this->policy()->assertAllowed('https://example.com:443/hook', ['allowedPorts' => [443]]),
        );
    }

    #[DataProvider('rejectedUrls')]
    public function testRejectsUrl(string $url, array $opts = []): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(OutboundUrlPolicy::ERROR_MESSAGE);

        $this->policy()->assertAllowed($url, $opts);
    }

    public static function rejectedUrls(): iterable
    {
        yield 'empty' => [''];
        yield 'not a url' => ['example.com/path'];
        yield 'ftp scheme' => ['ftp://example.com/'];
        yield 'file scheme' => ['file:///etc/passwd'];
        yield 'gopher scheme' => ['gopher://example.com/'];
        yield 'http when https only' => ['http://example.com/', ['httpsOnly' => true]];
        yield 'userinfo' => ['https://user:pass@example.com/'];
        yield 'userinfo without password' => ['https://user@example.com/'];
        yield 'disallowed port' => ['https://example.com:8443/', ['allowedPorts' => [443]]];
        yield 'default http port when only 443 allowed' => ['http://example.com/', ['allowedPorts' => [443]]];
        yield 'whitespace inside' => ["https://exa mple.com/"];
        yield 'control char' => ["https://example.com/\x00/x"];
        yield 'localhost' => ['https://localhost/'];
        yield 'localhost uppercase' => ['https://LOCALHOST/'];
        yield 'localhost trailing dot' => ['https://localhost./'];
        yield 'sub.localhost' => ['https://api.localhost/'];
        yield '.internal' => ['https://metadata.google.internal/'];
        yield '.local' => ['https://printer.local/'];
        yield 'docker db' => ['https://db:5432/'];
        yield 'docker redis' => ['https://redis/'];
        yield 'docker centrifugo' => ['https://centrifugo:8000/api'];
        yield 'docker backend' => ['https://backend/'];
        yield 'docker frontend' => ['https://frontend/'];
        yield 'docker java-services' => ['https://java-services:8080/'];
        yield 'loopback v4 literal' => ['https://127.0.0.1/'];
        yield 'loopback v4 other' => ['https://127.9.9.9/'];
        yield 'loopback v6 literal' => ['https://[::1]/'];
        yield 'unspecified v6' => ['https://[::]/'];
        yield 'link-local v4 (cloud metadata)' => ['http://169.254.169.254/latest/meta-data/'];
        yield 'link-local v6' => ['https://[fe80::1]/'];
        yield 'rfc1918 10/8' => ['https://10.1.2.3/'];
        yield 'rfc1918 172.16/12' => ['https://172.31.255.255/'];
        yield 'rfc1918 192.168/16' => ['https://192.168.1.1/'];
        yield 'cgnat 100.64/10' => ['https://100.127.0.1/'];
        yield 'ula fc00::/7' => ['https://[fc00::1]/'];
        yield 'ula fd' => ['https://[fdab::1]/'];
        yield 'multicast v4' => ['https://239.1.1.1/'];
        yield 'multicast v6' => ['https://[ff02::1]/'];
        yield 'this network 0/8' => ['https://0.0.0.0/'];
        yield 'broadcast' => ['https://255.255.255.255/'];
        yield 'ipv4-mapped ipv6' => ['https://[::ffff:127.0.0.1]/'];
        yield 'ipv4-mapped ipv6 public' => ['https://[::ffff:93.184.216.34]/'];
        yield 'ipv4-compatible ipv6' => ['https://[::127.0.0.1]/'];
        yield 'nat64 embedding loopback' => ['https://[64:ff9b::7f00:1]/'];
        yield 'decimal ipv4 form' => ['https://2130706433/'];
        yield 'octal ipv4 form' => ['https://0177.0.0.1/'];
        yield 'dns to loopback' => ['https://evil-loopback.example.com/'];
        yield 'dns with one private record' => ['https://evil-rfc1918.example.com/'];
        yield 'dns to link-local' => ['https://evil-linklocal.example.com/'];
        yield 'dns to cgnat' => ['https://evil-cgnat.example.com/'];
        yield 'dns to ula' => ['https://evil-ula.example.com/'];
        yield 'dns to v6 link-local' => ['https://evil-v6-linklocal.example.com/'];
        yield 'dns to mapped' => ['https://evil-mapped.example.com/'];
        yield 'dns to multicast' => ['https://evil-multicast.example.com/'];
        yield 'dns to 0.0.0.0' => ['https://evil-zero.example.com/'];
        yield 'unresolvable' => ['https://unresolvable.example.com/'];
    }

    public function testAssertHostAllowedAcceptsPublicHostAndNormalizes(): void
    {
        $this->assertSame('example.com', $this->policy()->assertHostAllowed(' Example.COM. '));
        $this->assertSame('2606:2800:220:1:248:1893:25c8:1946', $this->policy()->assertHostAllowed('[2606:2800:220:1:248:1893:25c8:1946]'));
    }

    #[DataProvider('rejectedHosts')]
    public function testAssertHostAllowedRejects(string $host): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(OutboundUrlPolicy::ERROR_MESSAGE);

        $this->policy()->assertHostAllowed($host);
    }

    public static function rejectedHosts(): iterable
    {
        yield 'empty' => [''];
        yield 'localhost' => ['localhost'];
        yield 'docker db' => ['db'];
        yield 'loopback' => ['127.0.0.1'];
        yield 'rfc1918' => ['192.168.0.10'];
        yield 'internal suffix' => ['smtp.corp.internal'];
        yield 'host with path' => ['example.com/x'];
        yield 'host with userinfo' => ['a@example.com'];
        yield 'dns to private' => ['evil-rfc1918.example.com'];
        yield 'unresolvable' => ['unresolvable.example.com'];
    }

    public function testIsForbiddenIpTreatsGarbageAsForbidden(): void
    {
        $this->assertTrue(OutboundUrlPolicy::isForbiddenIp('not-an-ip'));
        $this->assertFalse(OutboundUrlPolicy::isForbiddenIp('8.8.8.8'));
        $this->assertFalse(OutboundUrlPolicy::isForbiddenIp('2001:4860:4860::8888'));
        $this->assertTrue(OutboundUrlPolicy::isForbiddenIp('2001:db8::1'));
    }
}
