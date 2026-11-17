<?php

declare(strict_types=1);

namespace Kalaka\Forms\Engine;

/**
 * The address a rate limit counts a guest by (S14): `REMOTE_ADDR`, never a forwarded header, unless the
 * site names its reverse proxies in the `wp-config.php` constant `KALAKA_TRUSTED_PROXIES` (addresses or
 * CIDR ranges, as an array or a comma-separated string). Only a request that comes from a named proxy
 * takes its client from `X-Forwarded-For`: the rightmost entry that is not a named proxy. Whether a
 * site needs the constant is checked at deploy (OPS1).
 *
 * An IPv6 address counts by its /64 network, which one subscriber usually holds whole.
 */
final class ClientAddress
{
    public const PROXIES = 'KALAKA_TRUSTED_PROXIES';

    /** The current request's client, or null when it has no valid address. */
    public static function current(): ?string
    {
        $proxies = [];
        if (defined(self::PROXIES)) {
            /** @var mixed $configured */
            $configured = constant(self::PROXIES);
            $proxies = self::proxyList($configured);
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked as IP addresses
        return self::of($_SERVER, $proxies);
    }

    /**
     * The client of a request with these server variables, behind these proxies.
     *
     * @param array<array-key, mixed> $server
     * @param list<string> $proxies
     */
    public static function of(array $server, array $proxies): ?string
    {
        $remote = self::address($server['REMOTE_ADDR'] ?? null);
        if ($remote === null || !self::isProxy($remote, $proxies)) {
            return $remote === null ? null : self::counted($remote);
        }

        $forwarded = $server['HTTP_X_FORWARDED_FOR'] ?? null;
        $hops = is_string($forwarded) ? array_reverse(explode(',', $forwarded)) : [];
        foreach ($hops as $hop) {
            $address = self::address(trim($hop));
            if ($address === null) {
                // A malformed hop: nothing to its left can be trusted.
                break;
            }
            if (!self::isProxy($address, $proxies)) {
                return self::counted($address);
            }
        }

        return self::counted($remote);
    }

    /**
     * The configured proxies as a list.
     *
     * @return list<string>
     */
    public static function proxyList(mixed $configured): array
    {
        if (is_string($configured)) {
            $configured = explode(',', $configured);
        }
        if (!is_array($configured)) {
            return [];
        }
        $proxies = [];
        foreach ($configured as $proxy) {
            if (is_string($proxy) && trim($proxy) !== '') {
                $proxies[] = trim($proxy);
            }
        }

        return $proxies;
    }

    private static function address(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $address = filter_var(trim($value), FILTER_VALIDATE_IP);

        return is_string($address) ? $address : null;
    }

    /** IPv4 as it is; IPv6 as its /64 network. */
    private static function counted(string $address): string
    {
        $packed = inet_pton($address);
        if ($packed === false || strlen($packed) === 4) {
            return $address;
        }
        $network = inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8));

        return ($network === false ? $address : $network) . '/64';
    }

    /** @param list<string> $proxies */
    private static function isProxy(string $address, array $proxies): bool
    {
        foreach ($proxies as $proxy) {
            if (self::inRange($address, $proxy)) {
                return true;
            }
        }

        return false;
    }

    /** Whether the address is the proxy, or inside its CIDR range. */
    private static function inRange(string $address, string $proxy): bool
    {
        [$base, $bits] = array_pad(explode('/', $proxy, 2), 2, null);
        $packed = inet_pton($address);
        $range = is_string($base) && filter_var($base, FILTER_VALIDATE_IP) !== false ? inet_pton($base) : false;
        if ($packed === false || $range === false || strlen($packed) !== strlen($range)) {
            return false;
        }
        $length = strlen($packed) * 8;
        if ($bits === null) {
            $bits = $length;
        } elseif (preg_match('/^\d+$/', $bits) === 1 && (int) $bits <= $length) {
            $bits = (int) $bits;
        } else {
            return false;
        }
        $bytes = intdiv($bits, 8);
        if (substr($packed, 0, $bytes) !== substr($range, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($packed[$bytes]) & $mask) === (ord($range[$bytes]) & $mask);
    }
}
