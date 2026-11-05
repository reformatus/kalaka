<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Foundation;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Registrations\Api\RegistrationKey;
use PHPUnit\Framework\TestCase;

final class SubjectTest extends TestCase
{
    public function testTheFactoriesMakeTheDocumentedSubjects(): void
    {
        $registration = Subject::registration(new RegistrationKey(12, '00A0'));
        self::assertSame(['registration', 12, '00A0'], [$registration->type, $registration->eventId, $registration->key]);

        $event = Subject::event(12);
        self::assertSame(['event', 12, '12'], [$event->type, $event->eventId, $event->key]);
    }

    public function testARecordOutsideAnEventHasNoEvent(): void
    {
        $preset = new Subject('preset', null, '381');
        self::assertNull($preset->eventId);
    }

    /** @return iterable<string, array{string, ?int, string}> */
    public static function invalid(): iterable
    {
        yield 'upper case type' => ['Registration', 12, '00A0'];
        yield 'type starting with a digit' => ['1registration', 12, '00A0'];
        yield 'empty type' => ['', 12, '00A0'];
        yield 'type of 51 characters' => [str_repeat('a', 51), 12, '00A0'];
        yield 'empty key' => ['registration', 12, ''];
        yield 'key of 65 characters' => ['registration', 12, str_repeat('0', 65)];
        yield 'event 0' => ['registration', 0, '00A0'];
        yield 'negative event' => ['registration', -1, '00A0'];
    }

    /** @dataProvider invalid */
    public function testAnInvalidSubjectIsRefused(string $type, ?int $eventId, string $key): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Subject($type, $eventId, $key);
    }

    public function testTheLimitsThemselvesAreAllowed(): void
    {
        $subject = new Subject(str_repeat('a', 50), 1, str_repeat('ő', 64));
        self::assertSame(64, mb_strlen($subject->key));
    }
}
