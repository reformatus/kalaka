<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Forms;

use Kalaka\Forms\Api\FieldAnswer;
use Kalaka\Forms\Api\FieldType;
use Kalaka\Forms\Api\FieldTypeInput;
use Kalaka\Forms\FieldType\ConsentFieldType;
use Kalaka\Forms\FieldType\DateFieldType;
use Kalaka\Forms\FieldType\EmailFieldType;
use Kalaka\Forms\FieldType\NameFieldType;
use Kalaka\Forms\FieldType\PhoneFieldType;
use Kalaka\Forms\FieldType\TextFieldType;
use Kalaka\Foundation\Api\Error\InvalidInput;
use PHPUnit\Framework\TestCase;

/** AC6, AC7 and AC8: the core field types that need no WordPress, answer by answer (D6, D10 to D16). */
final class FieldTypesTest extends TestCase
{
    private function answer(FieldType $type, mixed $value, array $attributes = []): FieldAnswer
    {
        return $type->answer(new FieldTypeInput($value, $attributes));
    }

    public function testANameIsTrimmedWithItsInnerWhitespaceCollapsedAndKeepsItsLetters(): void
    {
        $answer = $this->answer(new NameFieldType(), "  Árvíztűrő \t Tükörfúrógép\n");

        self::assertSame('Árvíztűrő Tükörfúrógép', $answer->value);
        self::assertNull($answer->error);
    }

    public function testANameHasNoPatternButALengthLimitOf200Characters(): void
    {
        $type = new NameFieldType();

        self::assertSame('ifj. Kovács-Nagy 3. János', $this->answer($type, 'ifj. Kovács-Nagy 3. János')->value);
        self::assertSame(str_repeat('ő', 200), $this->answer($type, str_repeat('ő', 200))->value);
        self::assertSame('Legfeljebb 200 karaktert írhatsz ide.', $this->answer($type, str_repeat('ő', 201))->error);
    }

    public function testAPhoneIsStoredInInternationalFormWithoutSpacesHyphensAndParentheses(): void
    {
        $type = new PhoneFieldType();

        self::assertSame('+36301234567', $this->answer($type, '+36 30 123-4567')->value);
        self::assertSame('+36301234567', $this->answer($type, ' +36 (30) 123 4567 ')->value);
        self::assertSame('+12345678', $this->answer($type, '+12345678')->value);
        self::assertSame('+123456789012345', $this->answer($type, '+123456789012345')->value);
    }

    /** D6 (owner ruling 2026-11-10): a Hungarian domestic number, `06` and 8 or 9 digits, is stored as `+36…`. */
    public function testADomesticHungarianPhoneIsStoredWithPlus36(): void
    {
        $type = new PhoneFieldType();

        self::assertSame('+36301234567', $this->answer($type, '06 30 123 4567')->value);
        self::assertSame('+36301234567', $this->answer($type, '06-30-123-4567')->value);
        self::assertSame('+3612345678', $this->answer($type, '06 (1) 234 5678')->value);
        self::assertSame('+441234567890', $this->answer($type, '+44 1234 567890')->value);
    }

    /** @return iterable<string, array{string}> */
    public static function badPhones(): iterable
    {
        yield 'domestic, too short' => ['06 30 123 45'];
        yield 'domestic, too long' => ['06 30 123 45678'];
        yield 'bare subscriber number' => ['30 123 4567'];
        yield 'no plus' => ['36301234567'];
        yield 'too short' => ['+1234567'];
        yield 'too long' => ['+1234567890123456'];
        yield 'zero after the plus' => ['+06301234567'];
        yield 'letters' => ['+36 30 abc 4567'];
    }

    /** @dataProvider badPhones */
    public function testAPhoneNotInInternationalFormIsRefusedWithTheExample(string $phone): void
    {
        $answer = $this->answer(new PhoneFieldType(), $phone);

        self::assertSame('Adj meg egy érvényes telefonszámot, például 06301234567 vagy +36301234567.', $answer->error);
        self::assertNull($answer->value);
    }

    public function testInvalidUtf8IsRefusedAsInvalidInputNotStoredRaw(): void
    {
        foreach ([new TextFieldType(), new NameFieldType(), new EmailFieldType(), new DateFieldType()] as $type) {
            try {
                $this->answer($type, "  \xC3\x28 ");
                self::fail($type->blockName() . ' accepted invalid UTF-8.');
            } catch (InvalidInput) {
                self::assertTrue(true);
            }
        }
    }

    public function testADateIsARealCalendarDayWithNoRange(): void
    {
        $type = new DateFieldType();

        self::assertSame('2027-02-03', $this->answer($type, '2027-02-03')->value);
        self::assertSame('2028-02-29', $this->answer($type, '2028-02-29')->value);
        self::assertSame('1900-01-01', $this->answer($type, '1900-01-01')->value);
        self::assertSame('2999-12-31', $this->answer($type, '2999-12-31')->value);
    }

    /** @return iterable<string, array{string}> */
    public static function badDates(): iterable
    {
        yield 'no such day' => ['2027-02-30'];
        yield 'not a leap year' => ['2027-02-29'];
        yield 'dots' => ['2027.02.03'];
        yield 'day first' => ['03-02-2027'];
        yield 'short month' => ['2027-2-3'];
        yield 'month 13' => ['2027-13-01'];
        yield 'with a time' => ['2027-02-03 10:00'];
    }

    /** @dataProvider badDates */
    public function testAnythingButARealDayIsRefused(string $date): void
    {
        self::assertSame('Adj meg egy érvényes dátumot.', $this->answer(new DateFieldType(), $date)->error);
    }

    public function testATextIsTrimmedAndLimitedTo500CharactersOnOneLine(): void
    {
        $type = new TextFieldType();

        self::assertSame('Egy sor', $this->answer($type, "  Egy sor \n")->value);
        self::assertSame('első második', $this->answer($type, "első\nmásodik")->value);
        self::assertSame(str_repeat('a', 500), $this->answer($type, str_repeat('a', 500))->value);
        self::assertSame('Legfeljebb 500 karaktert írhatsz ide.', $this->answer($type, str_repeat('a', 501))->error);
    }

    public function testAMultilineTextKeepsItsLinesAndTakes5000Characters(): void
    {
        $type = new TextFieldType();
        $attributes = ['multiline' => true];

        self::assertSame("első\nmásodik", $this->answer($type, "első\r\nmásodik", $attributes)->value);
        self::assertSame(str_repeat('a', 5000), $this->answer($type, str_repeat('a', 5000), $attributes)->value);
        self::assertSame(
            'Legfeljebb 5000 karaktert írhatsz ide.',
            $this->answer($type, str_repeat('a', 5001), $attributes)->error
        );
    }

    public function testAConsentGivenIsStoredAsOne(): void
    {
        self::assertSame('1', $this->answer(new ConsentFieldType(), true)->value);
    }

    /** @return iterable<string, array{FieldType, mixed}> */
    public static function wrongTypes(): iterable
    {
        yield 'a list for a name' => [new NameFieldType(), ['a']];
        yield 'a number for a phone' => [new PhoneFieldType(), 36301234567];
        yield 'a bool for a date' => [new DateFieldType(), true];
        yield 'a list for a text' => [new TextFieldType(), ['a']];
        yield 'a string for a consent' => [new ConsentFieldType(), 'yes'];
        yield 'a number for a consent' => [new ConsentFieldType(), 1];
    }

    /** @dataProvider wrongTypes */
    public function testAValueOfTheWrongJsonTypeIsInvalidInput(FieldType $type, mixed $value): void
    {
        $this->expectException(InvalidInput::class);

        $this->answer($type, $value);
    }
}
