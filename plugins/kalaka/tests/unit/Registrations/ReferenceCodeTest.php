<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Registrations;

use Kalaka\Registrations\ReferenceCode;
use PHPUnit\Framework\TestCase;

/** AC2: the reference-code rule, over every string it could accept in four characters. */
final class ReferenceCodeTest extends TestCase
{
    public function testExactlyFiveThousandOfTheFourteenThousandFourCharacterShapesAreValid(): void
    {
        $shapes = 0;
        $valid = 0;
        for ($leading = 0; $leading < 100; $leading++) {
            foreach (str_split(ReferenceCode::LETTERS) as $letter) {
                for ($last = 0; $last < 10; $last++) {
                    $shapes++;
                    if (ReferenceCode::isValid(sprintf('%02d%s%d', $leading, $letter, $last))) {
                        $valid++;
                    }
                }
            }
        }

        self::assertSame(14000, $shapes);
        self::assertSame(5000, $valid);
        self::assertCount(5000, ReferenceCode::fourCharacterCodes());
    }

    /** @return iterable<string, array{string, bool}> */
    public static function codes(): iterable
    {
        yield 'valid' => ['12A3', true];
        yield 'leading digits swapped' => ['21A3', false];
        yield 'one character wrong' => ['12A4', false];
        yield 'letter and digit swapped' => ['1A23', false];
        yield 'lower case' => ['12a3', false];
        yield 'accented letter' => ['12Á3', false];
        yield 'three characters' => ['999', false];
        yield 'four digits' => ['1000', false];
        yield 'empty' => ['', false];
        yield 'extended' => ['12A37', true];
        yield 'extended to 16 characters' => ['12A3000000000000', true];
        yield 'extended with a wrong head' => ['12A47', false];
        yield 'extended with a letter' => ['12A3X', false];
        yield '17 characters' => ['12A30000000000000', false];
        yield 'trailing newline' => ["12A3\n", false];
    }

    /** @dataProvider codes */
    public function testIsValid(string $code, bool $expected): void
    {
        self::assertSame($expected, ReferenceCode::isValid($code));
    }
}
