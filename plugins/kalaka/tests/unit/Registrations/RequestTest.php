<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Registrations;

use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Registrations\Api\AnswerRead;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\Request;
use Kalaka\Registrations\Api\RequestKind;
use PHPUnit\Framework\TestCase;

/** A request's id names it for the answer act and keys its answers; an answer's id is the future mail key. */
final class RequestTest extends TestCase
{
    /** @return array<string, array{string, bool}> */
    public static function ids(): array
    {
        return [
            'the aid request' => ['aid', true],
            'an offer by its key' => ['offer:egyhazi_2', true],
            'a key with a dot and a dash' => ['offer:a.b-c', true],
            'empty' => ['', false],
            'upper case' => ['Aid', false],
            'a space' => ['offer: x', false],
            'a second colon' => ['offer:a:b', false],
            'a trailing newline' => ["aid\n", false],
            'too long' => ['offer:' . str_repeat('a', 59), false],
        ];
    }

    /** @dataProvider ids */
    public function testTheIdHasTheRequestShape(string $id, bool $valid): void
    {
        if (!$valid) {
            $this->expectException(InvalidInput::class);
        }

        $request = new Request($id, RequestKind::Offer, 'Teszt');

        self::assertSame($id, $request->id);
        self::assertSame([], $request->askFields);
        self::assertNull($request->answerField);
    }

    public function testEachAnsweringActHasItsOwnSubjectKeyedByItsId(): void
    {
        $key = new RegistrationKey(12, '00A0');
        $at = new \DateTimeImmutable();
        $money = Money::of('1000', Currency::HUF);
        $first = new AnswerRead(7, $key, 'aid', true, $money, null, $at, 3);
        $second = new AnswerRead(8, $key, 'aid', true, $money, null, $at, 3);

        $subject = $first->subject();
        self::assertSame(['answer', 12, '7'], [$subject->type, $subject->eventId, $subject->key]);
        self::assertNotSame($first->subject()->key, $second->subject()->key);
    }
}
