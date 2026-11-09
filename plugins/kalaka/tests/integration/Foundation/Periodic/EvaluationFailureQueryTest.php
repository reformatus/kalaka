<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation\Periodic;

use Kalaka\Foundation\Evaluation\EvaluationFailureQuery;
use Kalaka\Registrations\Api\RegistrationKey;

/** D10: `EvaluationFailureQuery::flaggedIn()`. */
final class EvaluationFailureQueryTest extends PeriodicTestCase
{
    public function testItReturnsTheFlagsOfTheGivenEventsAscendingByEventAndCode(): void
    {
        $this->seedFlag(7, '00B0');
        $this->seedFlag(3, '00A2');
        $this->seedFlag(7, '00A0');
        $this->seedFlag(9, '00A0');
        $this->seedFlag(3, '00A0');

        $keys = (new EvaluationFailureQuery())->flaggedIn([7, 3]);

        self::assertEquals(
            [
                new RegistrationKey(3, '00A0'),
                new RegistrationKey(3, '00A2'),
                new RegistrationKey(7, '00A0'),
                new RegistrationKey(7, '00B0'),
            ],
            $keys
        );
    }

    public function testItIsOneStatementWhateverTheNumberOfEvents(): void
    {
        foreach ([1, 2, 3, 4, 5, 6] as $event) {
            $this->seedFlag($event, '00A0');
        }

        $few = $this->statementsDuring(fn () => (new EvaluationFailureQuery())->flaggedIn([1, 2]));
        $many = $this->statementsDuring(fn () => (new EvaluationFailureQuery())->flaggedIn([1, 2, 3, 4, 5, 6]));

        self::assertCount(1, $few);
        self::assertCount(1, $many);
    }

    public function testAnEmptyListIssuesNoStatement(): void
    {
        $statements = $this->statementsDuring(
            fn () => self::assertSame([], (new EvaluationFailureQuery())->flaggedIn([]))
        );

        self::assertSame([], $statements);
    }
}
