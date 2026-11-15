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
            ['127.0.0.1'], ['10.0.0.1'], ['172.16.5.4'], ['192.168.10.162'], ['169.254.169.254'],
            ['100.64.0.1'], ['100.127.255.254'], ['0.0.0.0'], ['::1'], ['fd00::1'], ['fe80::1'],
            ['::ffff:127.0.0.1'], ['::ffff:100.64.0.1'], ['224.0.0.1'], ['not an address'],
            ['198.18.0.1'], ['198.19.255.254'], ['192.0.0.8'], ['239.255.255.250'], ['ff02::1'],
            'NAT64 private' => ['64:ff9b::a00:1'], 'NAT64 metadata' => ['64:ff9b::a9fe:a9fe'],
            'NAT64 CGNAT' => ['64:ff9b::6440:1'], '6to4 private' => ['2002:c0a8:0101::1'],
            '6to4 loopback' => ['2002:7f00:1::'], 'local use NAT64' => ['64:ff9b:1::a00:1'],
            'IPv4 compatible' => ['::10.0.0.1'],
        ];
    }

    #[DataProvider('internal')]
    public function test_non_public_addresses_are_internal(string $ip): void
    {
        $this->assertTrue(IpAddress::isInternal($ip));
    }

    public function test_public_addresses_are_not_internal(): void
    {
        foreach (['1.1.1.1', '169.253.1.1', '100.128.0.1', '2606:4700:4700::1111', '64:ff9b::101:101', '2002:101:101::1', '198.20.0.1'] as $ip) {
            $this->assertFalse(IpAddress::isInternal($ip), $ip);
        }
    }

    public function test_is_public_is_still_the_filter_var_reading(): void
    {
        // isPublic() is what filter_var calls public; CGNAT passes it. No
        // decision about a destination uses it on its own: they use isInternal().
        $this->assertTrue(IpAddress::isPublic('100.64.0.1'));
        $this->assertFalse(IpAddress::isPublic('10.0.0.1'));
        $this->assertTrue(IpAddress::isNeverReachable('::ffff:169.254.169.254'));
        $this->assertFalse(IpAddress::isNeverReachable('10.0.0.1'));
    }

    public function test_nat64_and_6to4_are_read_as_the_ipv4_address_they_carry(): void
    {
        $this->assertSame('169.254.169.254', IpAddress::canonical('64:ff9b::a9fe:a9fe'));
        $this->assertSame('10.0.0.1', IpAddress::canonical('64:ff9b::10.0.0.1'));
        $this->assertSame('192.168.1.1', IpAddress::canonical('2002:c0a8:101::'));
        $this->assertSame('192.168.1.1', IpAddress::canonical('2002:c0a8:101:5::1'));
        $this->assertSame('127.0.0.1', IpAddress::canonical('::ffff:127.0.0.1'));
        // Anything else is left as it was.
        $this->assertSame('2606:4700:4700::1111', IpAddress::canonical('2606:4700:4700::1111'));
        $this->assertSame('::1', IpAddress::canonical('::1'));
        $this->assertSame('10.0.0.1', IpAddress::canonical('10.0.0.1'));
        $this->assertSame('not an address', IpAddress::canonical('not an address'));
    }

    public function test_this_machine_and_link_local_inside_nat64_or_6to4_are_never_reachable(): void
    {
        foreach (['64:ff9b::a9fe:a9fe', '64:ff9b::7f00:1', '2002:a9fe:a9fe::', '2002:7f00:1::1'] as $ip) {
            $this->assertTrue(IpAddress::isNeverReachable($ip), $ip);
        }
        $this->assertTrue(IpAddress::inRange('64:ff9b::a00:1', '10.0.0.0/8'));
        $this->assertFalse(IpAddress::inRange('64:ff9b::101:101', '10.0.0.0/8'));
    }
}
