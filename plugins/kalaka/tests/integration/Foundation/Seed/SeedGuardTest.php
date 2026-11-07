<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation\Seed;

/** AC4 (the savepoint-mode part: the seed; the drop is in SchemaDropTest). */
final class SeedGuardTest extends SeedTestCase
{
    /** @return iterable<string, array{string}> */
    public static function productionLikeTypes(): iterable
    {
        yield 'staging' => ['staging'];
        yield 'production' => ['production'];
    }

    /** @dataProvider productionLikeTypes */
    public function testTheSeedRefusesBeforeWritingAnything(string $type): void
    {
        $message = $this->refusalOf(fn () => $this->seeded(false, $type));

        self::assertSame(
            'Refused: the environment type is "' . $type . '"; the seed runs only on local or development sites.',
            $message
        );
        self::assertSame([], $this->seededLogins());
        self::assertSame(0, $this->eventCount());
        self::assertSame([], $this->entries());
    }

    /** @return iterable<string, array{string}> */
    public static function developmentTypes(): iterable
    {
        yield 'local' => ['local'];
        yield 'development' => ['development'];
    }

    /** @dataProvider developmentTypes */
    public function testLocalAndDevelopmentPass(string $type): void
    {
        $lines = $this->seeded(false, $type);

        self::assertCount(5 + 1, $lines);
        self::assertSame(5, $this->eventCount());
    }
}
