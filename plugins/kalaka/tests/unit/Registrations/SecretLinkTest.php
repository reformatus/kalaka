<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Registrations;

use Kalaka\Registrations\SecretLink;
use PHPUnit\Framework\TestCase;

/** AC7: the secret of a registration's link. */
final class SecretLinkTest extends TestCase
{
    public function testASecretIs22Base64UrlCharactersOf16Bytes(): void
    {
        $seen = [];
        for ($i = 0; $i < 1000; $i++) {
            $secret = SecretLink::generate();
            self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{22}$/', $secret);
            self::assertSame(16, strlen((string) base64_decode(strtr($secret, '-_', '+/') . '==', true)));
            $seen[$secret] = true;
        }

        self::assertCount(1000, $seen);
    }
}
