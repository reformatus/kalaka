<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Registrations;

use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Registrations\Api\RegistrationKey;
use PHPUnit\Framework\TestCase;

/** A registration key holds a code in canonical form only: no other spelling reaches a read, a lock or a write. */
final class RegistrationKeyTest extends TestCase
{
    public function testAValidCodeIsKeptAsGiven(): void
    {
        $key = new RegistrationKey(7, '12A37');

        self::assertSame(7, $key->eventId);
        self::assertSame('12A37', $key->code);
    }

    /** @return iterable<string, array{string}> */
    public static function otherSpellings(): iterable
    {
        yield 'lower case' => ['12a3'];
        yield 'trailing space' => ['12A3 '];
        yield 'leading space' => [' 12A3'];
        yield 'trailing NUL' => ["12A3\0"];
        yield 'zero-width space' => ["1\u{200B}2A3"];
        yield 'fullwidth' => ['１２Ａ３'];
        yield 'Arabic-Indic digit' => ["12A\u{0663}"];
        yield 'combining accent' => ["12A3\u{0301}"];
        yield 'accented letter' => ['12Á3'];
        yield 'check fails' => ['12A4'];
        yield 'not a code' => ['ZZZZ'];
        yield 'empty' => [''];
    }

    /** @dataProvider otherSpellings */
    public function testAnyOtherSpellingIsInvalidInputWithNoContext(string $code): void
    {
        try {
            new RegistrationKey(7, $code);
            self::fail('The key was built.');
        } catch (InvalidInput $e) {
            self::assertSame('Hibás vagy hiányzó adat.', $e->getMessage());
        }
    }
}
