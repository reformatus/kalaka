<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Forms;

use Kalaka\Forms\Engine\ClientAddress;
use PHPUnit\Framework\TestCase;

/** S14: the address a guest's rate limit counts by; forwarded headers only from a named proxy. */
final class ClientAddressTest extends TestCase
{
    public function testItIsRemoteAddrAndAForwardedHeaderIsIgnoredWithoutANamedProxy(): void
    {
        self::assertSame('192.0.2.10', ClientAddress::of([
            'REMOTE_ADDR' => '192.0.2.10',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.7',
        ], []));
    }

    public function testAnIpv6AddressCountsByItsSlash64Network(): void
    {
        $address = ['REMOTE_ADDR' => '2001:db8:1:2:aaaa:bbbb:cccc:1'];
        self::assertSame('2001:db8:1:2::/64', ClientAddress::of($address, []));
        self::assertSame(
            ClientAddress::of(['REMOTE_ADDR' => '2001:db8:1:2::1'], []),
            ClientAddress::of(['REMOTE_ADDR' => '2001:db8:1:2:ffff::9'], [])
        );
    }

    public function testNoValidAddressIsNull(): void
    {
        self::assertNull(ClientAddress::of([], []));
        self::assertNull(ClientAddress::of(['REMOTE_ADDR' => 'not-an-ip'], []));
    }

    public function testFromANamedProxyTheRightmostHopThatIsNotAProxyCounts(): void
    {
        $server = [
            'REMOTE_ADDR' => '10.0.0.5',
            // The client may write anything to the left; the proxies append on the right.
            'HTTP_X_FORWARDED_FOR' => '203.0.113.99, 198.51.100.7, 10.0.0.4',
        ];

        self::assertSame('198.51.100.7', ClientAddress::of($server, ['10.0.0.0/24']));
        self::assertSame('10.0.0.4', ClientAddress::of($server, ['10.0.0.5']));
    }

    public function testAProxyWithoutAUsableHeaderCountsAsItself(): void
    {
        self::assertSame('10.0.0.5', ClientAddress::of(['REMOTE_ADDR' => '10.0.0.5'], ['10.0.0.5']));
        self::assertSame('10.0.0.5', ClientAddress::of([
            'REMOTE_ADDR' => '10.0.0.5',
            'HTTP_X_FORWARDED_FOR' => 'garbage, 10.0.0.5',
        ], ['10.0.0.5']));
    }

    public function testRangesMatchByTheirPrefixAndABadRangeMatchesNothing(): void
    {
        $server = ['REMOTE_ADDR' => '172.16.5.9', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7'];

        self::assertSame('198.51.100.7', ClientAddress::of($server, ['172.16.0.0/12']));
        self::assertSame('172.16.5.9', ClientAddress::of($server, ['172.32.0.0/12']));
        self::assertSame('172.16.5.9', ClientAddress::of($server, ['172.16.0.0/33', 'nonsense', '2001:db8::/32']));
        self::assertSame('198.51.100.7', ClientAddress::of(
            ['REMOTE_ADDR' => '2001:db8::5', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7'],
            ['2001:db8::/32']
        ));
    }

    public function testTheConstantTakesAListOrACommaSeparatedString(): void
    {
        self::assertSame(['10.0.0.1', '10.1.0.0/16'], ClientAddress::proxyList(' 10.0.0.1, 10.1.0.0/16 ,'));
        self::assertSame(['10.0.0.1'], ClientAddress::proxyList(['10.0.0.1', '', 7]));
        self::assertSame([], ClientAddress::proxyList(null));
    }
}
