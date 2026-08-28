<?php

namespace Tests\Unit;

use App\Services\SafeHttp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SafeHttpTest extends TestCase
{
    public static function privateAddresses(): array
    {
        return [
            'loopback' => ['127.0.0.1'],
            'private class A' => ['10.0.0.1'],
            'private class B' => ['172.16.5.4'],
            'private class C' => ['192.168.18.162'],
            'cloud metadata' => ['169.254.169.254'],
            'IPv6 loopback' => ['::1'],
            'IPv6 unique local' => ['fd00::1'],
        ];
    }

    #[DataProvider('privateAddresses')]
    public function test_it_refuses_addresses_on_our_own_networks(string $ip): void
    {
        $this->assertFalse((new SafeHttp)->isPublic($ip));
    }

    public function test_it_allows_ordinary_public_addresses(): void
    {
        $safe = new SafeHttp(allowedHosts: []);

        $this->assertTrue($safe->isPublic('169.58.83.63'));
        $this->assertTrue($safe->isPublic('1.1.1.1'));
    }

    public function test_a_literal_private_address_is_refused_without_dns(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('link-local');

        (new SafeHttp(allowedHosts: []))->resolve('169.254.169.254');
    }

    public function test_a_url_without_a_host_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);

        (new SafeHttp(allowedHosts: []))->to('not-a-url');
    }

    public function test_a_host_on_the_allowlist_may_be_private(): void
    {
        // Self-hosted Pharos often sits on the same internal network as the
        // identity provider, where a private issuer is the normal case.
        $safe = new SafeHttp(allowedHosts: ['id.intern.example.net', '192.168.18.163']);

        $this->assertSame('192.168.18.163', $safe->resolve('192.168.18.163'));
    }

    public function test_the_allowlist_does_not_open_link_local(): void
    {
        // Cloud metadata is the address the guard exists for. No setting reaches it.
        $safe = new SafeHttp(allowedHosts: ['169.254.169.254']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('link-local');

        $safe->resolve('169.254.169.254');
    }

    public function test_a_host_that_is_not_on_the_allowlist_is_still_refused(): void
    {
        $safe = new SafeHttp(allowedHosts: ['id.intern.example.net']);

        $this->expectException(\RuntimeException::class);

        $safe->resolve('10.0.0.5');
    }

    public function test_an_ordinary_private_address_is_still_refused_by_default(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('private or local network');

        (new SafeHttp(allowedHosts: []))->resolve('192.168.18.163');
    }

    // ---------- IPv4 hiding inside IPv6 ----------

    public static function mappedAddresses(): array
    {
        return [
            'mapped cloud metadata' => ['::ffff:169.254.169.254'],
            'mapped cloud metadata, hex form' => ['::ffff:a9fe:a9fe'],
            'mapped loopback' => ['::ffff:127.0.0.1'],
            'mapped private' => ['::ffff:10.0.0.1'],
        ];
    }

    #[DataProvider('mappedAddresses')]
    public function test_an_ipv4_address_wrapped_in_ipv6_is_still_not_public(string $ip): void
    {
        // filter_var sees IPv6 and waves it through; the socket connects to the
        // IPv4 address inside.
        $this->assertFalse((new SafeHttp)->isPublic($ip));
    }

    public function test_wrapping_link_local_in_ipv6_does_not_slip_past_the_never_list(): void
    {
        $safe = new SafeHttp;

        $this->assertTrue($safe->isNeverReachable('::ffff:169.254.169.254'));
        $this->assertTrue($safe->isNeverReachable('::ffff:a9fe:a9fe'));
        $this->assertTrue($safe->isNeverReachable('::ffff:127.0.0.1'));
        // Private is refused by isPublic(), not by the never-list.
        $this->assertFalse($safe->isNeverReachable('::ffff:10.0.0.1'));
    }

    public function test_this_machine_is_never_reachable_whatever_the_allowlist_says(): void
    {
        $safe = new SafeHttp(allowedHosts: ['127.0.0.1', '::1', '0.0.0.0']);

        foreach (['127.0.0.1', '127.8.8.8', '::1', '0.0.0.0'] as $ip) {
            $this->assertTrue($safe->isNeverReachable($ip), $ip);
        }

        $this->expectException(\RuntimeException::class);
        $safe->resolve('127.0.0.1');
    }

    public function test_a_public_ipv6_address_still_passes(): void
    {
        $safe = new SafeHttp;

        $this->assertTrue($safe->isPublic('2606:4700:4700::1111'));
        $this->assertFalse($safe->isNeverReachable('2606:4700:4700::1111'));
    }

    // ---------- targets the administrator owns ----------

    public function test_a_webhook_target_may_be_private_but_never_this_machine_or_link_local(): void
    {
        $safe = new SafeHttp(allowedHosts: []);

        $this->assertNull($safe->forbiddenAddress('http://192.168.18.161:5678/webhook'));
        $this->assertNull($safe->forbiddenAddress('https://1.1.1.1/hook'));
        $this->assertSame('169.254.169.254', $safe->forbiddenAddress('http://169.254.169.254/latest/meta-data'));
        $this->assertSame('127.0.0.1', $safe->forbiddenAddress('http://127.0.0.1:8080/x'));
        // parse_url keeps the brackets; they must not hide the address.
        $this->assertSame('169.254.169.254', $safe->forbiddenAddress('http://[::ffff:169.254.169.254]/x'));
        $this->assertSame('::1', $safe->forbiddenAddress('http://[::1]/x'));
        // A name that does not resolve cannot be reached: nothing to refuse.
        $this->assertNull($safe->forbiddenAddress('https://hooks.example.net/pharos'));
    }

    public function test_a_webhook_to_link_local_is_refused_before_any_request(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('never');

        (new SafeHttp(allowedHosts: []))->toOwn('http://169.254.169.254/latest/meta-data');
    }
}
