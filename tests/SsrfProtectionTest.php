<?php declare(strict_types=1);

namespace EdgeTelemetrics\React\Http\Tests;

use EdgeTelemetrics\React\Http\Browser;
use ReflectionMethod;

class SsrfProtectionTest extends \React\Tests\Http\TestCase
{
    /**
     * @dataProvider provideBlockedAddresses
     */
    public function testBlockedAddresses(string $ip): void
    {
        $method = new ReflectionMethod(Browser::class, 'isBlockedAddress');

        $this->assertTrue($method->invoke(null, $ip), $ip . ' should be blocked');
    }

    /**
     * @dataProvider provideAllowedAddresses
     */
    public function testAllowedAddresses(string $ip): void
    {
        $method = new ReflectionMethod(Browser::class, 'isBlockedAddress');

        $this->assertFalse($method->invoke(null, $ip), $ip . ' should be allowed');
    }

    public static function provideBlockedAddresses(): array
    {
        return [
            'loopback' => ['127.0.0.1'],
            'private 10/8' => ['10.0.0.1'],
            'private 172.16/12' => ['172.16.0.1'],
            'private 192.168/16' => ['192.168.1.1'],
            'link-local metadata' => ['169.254.169.254'],
            'cgnat low' => ['100.64.0.1'],
            'cgnat high' => ['100.127.255.255'],
            'benchmarking low' => ['198.18.0.1'],
            'benchmarking high' => ['198.19.255.255'],
            'unspecified' => ['0.0.0.0'],
            'multicast low' => ['224.0.0.1'],
            'multicast high' => ['239.255.255.255'],
            'reserved' => ['240.0.0.1'],
            'broadcast' => ['255.255.255.255'],
            'ipv6 loopback' => ['::1'],
            'ipv6 unspecified' => ['::'],
            'ipv4-mapped loopback' => ['::ffff:127.0.0.1'],
            'ipv4-mapped private' => ['::ffff:10.0.0.1'],
            'ipv4-mapped public' => ['::ffff:8.8.8.8'],
            'ipv6 unique local' => ['fc00::1'],
            'ipv6 unique local fd' => ['fd12::1'],
            'ipv6 link local' => ['fe80::1'],
            'ipv6 multicast' => ['ff02::1'],
            'documentation' => ['2001:db8::1'],
            'unparsable' => ['not-an-ip'],
        ];
    }

    public static function provideAllowedAddresses(): array
    {
        return [
            'public 8.8.8.8' => ['8.8.8.8'],
            'public 1.1.1.1' => ['1.1.1.1'],
            'above private 172/12' => ['172.32.0.1'],
            'below cgnat' => ['100.63.255.255'],
            'above cgnat' => ['100.128.0.0'],
            'below benchmarking' => ['198.17.255.255'],
            'above benchmarking' => ['198.20.0.0'],
            'below multicast' => ['223.255.255.255'],
            'public ipv6' => ['2606:4700:4700::1111'],
        ];
    }
}
