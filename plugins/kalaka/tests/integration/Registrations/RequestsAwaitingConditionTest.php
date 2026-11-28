<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Registrations;

use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Registrations\Api\Answer;
use Kalaka\Registrations\Api\AwaitingRule;
use Kalaka\Registrations\Api\FieldTest;
use Kalaka\Registrations\Api\FieldTestKind;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\Request;
use Kalaka\Registrations\Api\Requests;
use Kalaka\Registrations\Api\RequestSource;
use Kalaka\Registrations\Api\SeatPool;
use Kalaka\Registrations\Api\RequestTerms;

/** The grid's "awaits an answer" condition is compiled by core from the sources' rules alone. */
final class RequestsAwaitingConditionTest extends RegistrationsTestCase
{
    private function source(?AwaitingRule $rule): RequestSource
    {
        return new class ($rule) implements RequestSource {
            public function __construct(private readonly ?AwaitingRule $rule)
            {
            }

            public function requestsOf(RegistrationKey $registration, array $values): array
            {
                return [];
            }

            public function isAnswered(Request $request, array $values): bool
            {
                return true;
            }

            public function awaitingRule(int $eventId): ?AwaitingRule
            {
                return $this->rule;
            }

            public function seatPool(Request $request): ?SeatPool
            {
                return null;
            }

            public function terms(
                RegistrationKey $registration,
                Request $request,
                array $values,
                \DateTimeImmutable $now,
            ): ?RequestTerms {
                return null;
            }

            public function writeAnswer(
                RegistrationKey $registration,
                Request $request,
                Answer $answer,
                Context $context,
            ): void {
            }
        };
    }

    /** Registered with nothing waiting is 0; any other status is NULL (N/A), as the CASE has no ELSE. */
    private const NO = "CASE WHEN r.status = 'registered' THEN 0 END";

    public function testWithNoSourceTheConditionIsNoForRegisteredAndNotApplicableForTheRest(): void
    {
        self::assertSame([self::NO, []], (new Requests([]))->awaitingCondition(7, 'wp_kalaka_field_values'));
    }

    public function testASourceThatNeverAwaitsAddsNothing(): void
    {
        self::assertSame([self::NO, []], (new Requests([$this->source(null)]))->awaitingCondition(7, 't'));
    }

    public function testEachTestIsAnExistsOverTheFieldValuesWithPlaceholdersOnly(): void
    {
        $requests = new Requests([$this->source(new AwaitingRule([[
            new FieldTest('a', FieldTestKind::Filled),
            new FieldTest('b', FieldTestKind::Empty),
            new FieldTest('c', FieldTestKind::OneOf, ['x', 'y']),
            new FieldTest('d', FieldTestKind::NoneOf, ['z']),
        ]]))]);

        [$sql, $args] = $requests->awaitingCondition(7, 't');

        $exists = 'EXISTS (SELECT 1 FROM %i f WHERE f.event_id = r.event_id AND f.code = r.code'
            . ' AND f.field_key = %s AND ';
        self::assertSame(
            "CASE WHEN r.status = 'registered' THEN (("
            . '(' . $exists . 'LENGTH(f.value) > 0)'
            . ' AND NOT ' . $exists . 'LENGTH(f.value) > 0)'
            . ' AND ' . $exists . 'BINARY f.value IN (%s, %s))'
            . ' AND NOT ' . $exists . 'BINARY f.value IN (%s))))) END',
            $sql
        );
        self::assertSame(['t', 'a', 't', 'b', 't', 'c', 'x', 'y', 't', 'd', 'z'], $args);
    }

    public function testTheGroupsAndTheSourcesAreOrredAndOnlyARegisteredRegistrationAwaits(): void
    {
        $one = new FieldTest('a', FieldTestKind::Filled);
        $two = new FieldTest('b', FieldTestKind::Filled);
        $requests = new Requests([
            $this->source(new AwaitingRule([[$one], [$two]])),
            $this->source(null),
            $this->source(new AwaitingRule([[$one, $two]])),
        ]);

        [$sql] = $requests->awaitingCondition(7, 't');

        self::assertSame(2, substr_count($sql, ' OR '));
        self::assertSame(1, substr_count($sql, "r.status = 'registered'"));
        self::assertStringStartsWith("CASE WHEN r.status = 'registered' THEN (", $sql);
        self::assertStringEndsWith(') END', $sql);
    }
}
