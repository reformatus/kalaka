<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Registrations;

use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Registrations\Api\Answer;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\Request;
use Kalaka\Registrations\Api\Requests;
use Kalaka\Registrations\Api\RequestSource;
use Kalaka\Registrations\Api\SqlPredicate;

/** The grid's "awaits an answer" condition is built from the sources' predicates alone. [UNAPPROVED N669] */
final class RequestsAwaitingPredicateTest extends RegistrationsTestCase
{
    private function source(?SqlPredicate $predicate): RequestSource
    {
        return new class ($predicate) implements RequestSource {
            public function __construct(private readonly ?SqlPredicate $predicate)
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

            public function awaitingPredicate(int $eventId, string $fieldValues): ?SqlPredicate
            {
                return $this->predicate;
            }

            public function seatPool(Request $request): ?string
            {
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

    public function testWithNoSourceTheConditionIsFalse(): void
    {
        $predicate = (new Requests([]))->awaitingPredicate(7, 'wp_kalaka_field_values');

        self::assertSame('0 = 1', $predicate->sql);
        self::assertSame([], $predicate->args);
    }

    public function testASourceThatNeverAwaitsAddsNothing(): void
    {
        $predicate = (new Requests([$this->source(null)]))->awaitingPredicate(7, 't');

        self::assertSame('0 = 1', $predicate->sql);
    }

    public function testTheSourcesAreOrredAndOnlyARegisteredRegistrationAwaits(): void
    {
        $requests = new Requests([
            $this->source(new SqlPredicate('a = %s', ['x'])),
            $this->source(null),
            $this->source(new SqlPredicate('b = %d OR c = %s', [4, 'y'])),
        ]);

        $predicate = $requests->awaitingPredicate(7, 't');

        self::assertSame(
            "r.status = 'registered' AND ((a = %s) OR (b = %d OR c = %s))",
            $predicate->sql
        );
        self::assertSame(['x', 4, 'y'], $predicate->args);
    }
}
