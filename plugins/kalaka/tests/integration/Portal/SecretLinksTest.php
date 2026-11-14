<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Portal;

use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\SecretLinks;

/** SB-10: the link carries the key beside the secret; Registrations matches it with `hash_equals()`. */
final class SecretLinksTest extends PortalTestCase
{
    public function testTheLinkCarriesTheKeyAndTheSecretAndMatchesOnlyTheCurrentSecret(): void
    {
        $key = $this->stored('12A3');
        $links = new SecretLinks();
        $row = $this->rowOf('kalaka_registrations', 5, '12A3');
        self::assertIsArray($row);
        $secret = (string) $row['secret'];

        self::assertSame(
            'https://example.org/allapot/?a=1&kalaka_link=5.12A3.' . $secret,
            $links->url($key, 'https://example.org/allapot/?a=1')
        );
        self::assertEquals($key, $links->fromLink('5.12A3.' . $secret));
        self::assertTrue($links->matches($key, $secret));
        self::assertFalse($links->matches($key, $secret . 'x'));
        self::assertFalse($links->matches($key, str_repeat('A', 22)));
        self::assertNull($links->fromLink('5.12a3.' . $secret));
        self::assertNull($links->fromLink('05.12A3.' . $secret));
        self::assertNull($links->fromLink('5.12A3.' . $secret . '.'));
        self::assertNull($links->fromLink('5.12A3'));
        self::assertNull($links->url(new RegistrationKey(5, '00A0'), 'https://example.org/'));
        self::assertNull($links->activeToken(new RegistrationKey(5, '00A0'), $this->utc(self::NOW)));
    }

    public function testTheSecretGoesIntoNoStatementOfAMatch(): void
    {
        $key = $this->stored('12A3');
        $row = $this->rowOf('kalaka_registrations', 5, '12A3');
        self::assertIsArray($row);
        $secret = (string) $row['secret'];
        \Kalaka\Foundation\ObjectCache::clear();

        $statements = $this->statementsDuring(static function () use ($key, $secret): void {
            (new SecretLinks())->fromLink('5.12A3.' . $secret);
            (new SecretLinks())->verifyActiveToken('v1.5.12A3.1.' . str_repeat('a', 43), new \DateTimeImmutable('@1'));
        });

        self::assertNotSame([], $statements);
        foreach ($statements as $statement) {
            self::assertStringNotContainsString($secret, $statement);
        }
        unset($key);
    }
}
