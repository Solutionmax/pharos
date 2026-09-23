<?php

namespace Tests\Unit;

use App\Support\IpAddress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class IpAddressTest extends TestCase
{
    public static function internal(): array
    {
        return [
            ['127.0.0.1'], ['10.0.0.1'], ['172.16.5.4'], ['192.168.18.162'], ['169.254.169.254'],
            ['100.64.0.1'], ['100.127.255.254'], ['0.0.0.0'], ['::1'], ['fd00::1'], ['fe80::1'],
            ['::ffff:127.0.0.1'], ['::ffff:100.64.0.1'], ['224.0.0.1'], ['not an address'],
        ];
    }

    #[DataProvider('internal')]
    public function test_non_public_addresses_are_internal(string $ip): void
    {
        $this->assertTrue(IpAddress::isInternal($ip));
    }

    public function test_public_addresses_are_not_internal(): void
    {
        foreach (['1.1.1.1', '169.58.83.63', '100.128.0.1', '2606:4700:4700::1111'] as $ip) {
            $this->assertFalse(IpAddress::isInternal($ip), $ip);
        }
    }

    public function test_the_classification_safe_http_uses_is_unchanged(): void
    {
        // CGNAT stays reachable for SafeHttp::isPublic, as before; only mail is stricter.
        $this->assertTrue(IpAddress::isPublic('100.64.0.1'));
        $this->assertFalse(IpAddress::isPublic('10.0.0.1'));
        $this->assertTrue(IpAddress::isNeverReachable('::ffff:169.254.169.254'));
        $this->assertFalse(IpAddress::isNeverReachable('10.0.0.1'));
    }
}
