<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Admin;

use Kalaka\Admin\Api\AggregateKind;
use Kalaka\Admin\Api\AggregateSpec;
use Kalaka\Admin\Api\ColumnFilter;
use Kalaka\Admin\Api\FilterKind;
use Kalaka\Admin\Api\HeldView;
use Kalaka\Admin\Api\Narrowing;
use Kalaka\Admin\Api\SearchWord;
use Kalaka\Admin\Api\ViewQuery;
use Kalaka\Admin\View\ViewShape;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Registrations\FieldValue\FieldValueRepository;
use Kalaka\Registrations\Api\RegistrationStatus;
use Kalaka\Registrations\Api\Requests;
use Kalaka\Tests\Integration\Registrations\TestRequestSource;

/**
 * "Awaits an answer" is a grid column of its own with a filter, a sort and a count, all over one SQL expression
 * (`RequestSource::awaitingRule()`, compiled by core); it equals `registered` and `Requests::states()` saying a request
 * awaits, for every status. The row's mark comes from the same SQL. [UNAPPROVED N668] [UNAPPROVED N669]
 */
final class ViewAwaitsAnswerTest extends AdminTestCase
{
    private const EVENT = 7;

    /** @var array<string, bool> code → whether it awaits (the expected, from the fixture's meaning) */
    private const EXPECTED = [
        '00A0' => true,  // church offer chosen, no answer
        '00A2' => false, // church offer, refused (an answer)
        '00A5' => false, // aid asked, waitlisted: awaits only once registered
        '00A8' => false, // withdrawn, offer unanswered
        '00B0' => false, // no request: another offer chosen
        '00B3' => true,  // aid asked, no award
        '00B6' => false, // aid asked, award 0 (a refusal is an answer)
        '00B9' => false, // aid asked, award granted
        '00E2' => false, // removed, offer unanswered
        '00E5' => true,  // offer and aid, the offer answered, the aid not
        '00E8' => false, // aid asked as an empty value: no request
    ];

    private function seed(): void
    {
        $rows = [
            ['00A0', 'registered', ['support' => 'church']],
            ['00A2', 'registered', ['support' => 'church', 'support_answer' => 'refused']],
            ['00A5', 'waitlisted', ['aid_asked' => '5000']],
            ['00A8', 'withdrawn', ['support' => 'church']],
            ['00B0', 'registered', ['support' => 'nincs']],
            ['00B3', 'registered', ['aid_asked' => '5000']],
            ['00B6', 'registered', ['aid_asked' => '5000', 'aid_award' => '0']],
            ['00B9', 'registered', ['aid_asked' => '5000', 'aid_award' => '3000']],
            ['00E2', 'removed', ['support' => 'church']],
            ['00E5', 'registered', ['support' => 'church', 'support_answer' => 'approved', 'aid_asked' => '2000']],
            ['00E8', 'registered', ['aid_asked' => '']],
        ];
        foreach ($rows as $index => [$code, $status, $values]) {
            $this->addRegistration(self::EVENT, $code, $status, sprintf('2026-11-30 08:%02d:00', $index));
            foreach ($values as $key => $value) {
                $this->addValue(self::EVENT, $code, $key, $value);
            }
        }
        // Another event's request never counts here.
        $this->addRegistration(self::EVENT + 1, '00A2');
        $this->addValue(self::EVENT + 1, '00A2', 'support', 'church');
    }

    private function query(bool $source = true): ViewQuery
    {
        return new ViewQuery(requests: new Requests($source ? [new TestRequestSource()] : []));
    }

    private function held(bool $listed = true): HeldView
    {
        $columns = [['column' => 'code', 'write' => false], ['column' => 'status', 'write' => false]];
        if ($listed) {
            $columns[] = ['column' => 'awaits_answer', 'write' => false];
        }

        return new HeldView(self::EVENT, 1, null, null, ViewShape::of([], $columns), null);
    }

    private function narrowing(string $operator, bool $value): Narrowing
    {
        return new Narrowing([ColumnFilter::of('awaits_answer', FilterKind::YesNo, $operator, $value)]);
    }

    /** @return list<string> */
    private function codes(?string $sort = null, bool $descending = false, ?Narrowing $narrowing = null): array
    {
        $page = $this->query()->page($this->held(), $sort, $descending, 50, 0, [], $narrowing);

        return array_map(static fn ($row): string => $row->key->code, $page->rows);
    }

    public function testTheColumnEqualsRegisteredAndTheRequestStatesSayingAwaiting(): void
    {
        $this->seed();
        $requests = new Requests([new TestRequestSource()]);

        $page = $this->query()->page($this->held(), limit: 50);

        self::assertCount(count(self::EXPECTED), $page->rows);
        foreach ($page->rows as $row) {
            $code = $row->key->code;
            $status = RegistrationStatus::from((string) $row->values['status']);
            $states = $requests->states($row->key);
            $byStates = $status === RegistrationStatus::Registered
                && array_filter($states, static fn ($state): bool => $state->awaits()) !== [];

            $values = [];
            foreach ((new FieldValueRepository())->forRegistration($row->key) as $value) {
                $values[$value->fieldKey] = $value->value;
            }
            $rule = (new TestRequestSource())->awaitingRule(self::EVENT);
            $byRule = $status === RegistrationStatus::Registered && $rule !== null && $rule->matches($values);

            self::assertSame($byRule, $row->awaitsAnswer, $code . ' by the rule in PHP');
            self::assertSame($byStates, $row->awaitsAnswer, $code . ' by the request states');
            self::assertSame(self::EXPECTED[$code], $row->awaitsAnswer, $code . ' by the fixture');
            self::assertSame(
                $status === RegistrationStatus::Registered && $requests->awaits($row->key, $status),
                $row->awaitsAnswer,
                $code . ' by Requests::awaits()'
            );
            self::assertSame($row->awaitsAnswer ? '1' : '0', $row->values['awaits_answer'], $code);
        }
    }

    public function testAwaitsAndThePanelsRequestStatesAgreeOnEveryFixtureForEveryStatus(): void
    {
        $this->seed();
        $requests = new Requests([new TestRequestSource()]);
        $page = $this->query()->page($this->held(), limit: 50);

        foreach ($page->rows as $row) {
            $unanswered = array_filter(
                $requests->states($row->key),
                static fn ($state): bool => $state->awaits()
            ) !== [];
            foreach (RegistrationStatus::cases() as $status) {
                // The panel (RegistrationDetail::requestsPart()) says 'awaiting' for an unanswered request only
                // while the registration is registered, 'inactive' otherwise.
                $panel = $status === RegistrationStatus::Registered && $unanswered;
                self::assertSame(
                    $panel,
                    $requests->awaits($row->key, $status),
                    $row->key->code . ' as ' . $status->value
                );
            }
            $actual = RegistrationStatus::from((string) $row->values['status']);
            self::assertSame(
                $row->awaitsAnswer,
                $requests->awaits($row->key, $actual),
                $row->key->code . ' grid column equals the fact'
            );
        }
    }

    public function testTheSqlComparesValuesExactlyAsPhpDoes(): void
    {
        $rows = [
            ['00A0', ['support' => 'church', 'support_answer' => 'Approved']], // not "approved": awaits
            ['00A2', ['support' => 'church', 'support_answer' => 'approved']], // answered
            ['00A5', ['support' => 'Church']], // not "church": no request
            ['00A8', ['aid_asked' => '5000', 'aid_award' => ' ']], // a space is a value: answered
            ['00B0', ['support' => 'church', 'support_answer' => 'approved ']], // not "approved": awaits
        ];
        foreach ($rows as $index => [$code, $values]) {
            $this->addRegistration(self::EVENT, $code, 'registered', sprintf('2026-11-30 08:%02d:00', $index));
            foreach ($values as $key => $value) {
                $this->addValue(self::EVENT, $code, $key, $value);
            }
        }
        $rule = (new TestRequestSource())->awaitingRule(self::EVENT) ?? throw new \LogicException();

        $page = $this->query()->page($this->held(), limit: 50);

        self::assertCount(count($rows), $page->rows);
        $byCode = [];
        foreach ($page->rows as $row) {
            $values = [];
            foreach ((new FieldValueRepository())->forRegistration($row->key) as $value) {
                $values[$value->fieldKey] = $value->value;
            }
            self::assertSame($rule->matches($values), $row->awaitsAnswer, $row->key->code . ' SQL equals PHP');
            $byCode[$row->key->code] = $row->awaitsAnswer;
        }
        self::assertSame(['00A0' => true, '00A2' => false, '00A5' => false, '00A8' => false, '00B0' => true], $byCode);
    }

    public function testAnUnlistedColumnIsStillMarkedOnTheRowButNotAValue(): void
    {
        $this->seed();

        $page = $this->query()->page($this->held(false), limit: 50);

        foreach ($page->rows as $row) {
            self::assertSame(['code', 'status'], array_keys($row->values));
            self::assertSame(self::EXPECTED[$row->key->code], $row->awaitsAnswer);
        }
    }

    public function testWithNoSourceNothingAwaits(): void
    {
        $this->seed();

        $page = $this->query(false)->page($this->held(), limit: 50);

        foreach ($page->rows as $row) {
            self::assertFalse($row->awaitsAnswer);
            self::assertSame('0', $row->values['awaits_answer']);
        }
    }

    public function testFilterYesAndNo(): void
    {
        $this->seed();

        self::assertSame(['00A0', '00B3', '00E5'], $this->codes(narrowing: $this->narrowing('is', true)));
        self::assertSame(['00A0', '00B3', '00E5'], $this->codes(narrowing: $this->narrowing('isNot', false)));

        $no = $this->codes(narrowing: $this->narrowing('is', false));
        self::assertSame(['00A2', '00A5', '00A8', '00B0', '00B6', '00B9', '00E2', '00E8'], $no);
        self::assertSame($no, $this->codes(narrowing: $this->narrowing('isNot', true)));

        $page = $this->query()->page($this->held(), narrowing: $this->narrowing('is', true));
        self::assertSame(3, $page->total);
        self::assertSame(3, $this->query()->count($this->held(), $this->narrowing('is', true)));
    }

    public function testSearchingTheShownWordsFindsTheColumnsYesAndNo(): void
    {
        $this->seed();
        $search = function (string $text, string $stored): array {
            $page = $this->query()->page(
                $this->held(),
                limit: 50,
                narrowing: new Narrowing([], [new SearchWord($text, ['awaits_answer' => [$stored]])])
            );

            return array_map(static fn ($row): string => $row->key->code, $page->rows);
        };

        $yes = $search('igen', '1');
        sort($yes);
        self::assertSame(['00A0', '00B3', '00E5'], $yes);
        self::assertCount(count(self::EXPECTED) - 3, $search('nem', '0'));
        self::assertNotContains('00A0', $search('nem', '0'));
    }

    public function testNoAnswerIsNotAFilterOfTheColumn(): void
    {
        $this->seed();

        $this->expectException(InvalidInput::class);
        $this->query()->page($this->held(), narrowing: new Narrowing([
            ColumnFilter::of('awaits_answer', FilterKind::YesNo, 'is', ColumnFilter::NO_ANSWER),
        ]));
    }

    public function testSortYesFirstDescendingAndLastAscending(): void
    {
        $this->seed();

        $descending = $this->codes('awaits_answer', true);
        self::assertSame(['00A0', '00B3', '00E5'], array_slice($descending, 0, 3));
        $ascending = $this->codes('awaits_answer');
        self::assertSame(['00A0', '00B3', '00E5'], array_slice($ascending, -3));
        self::assertSame(['00A2', '00A5'], array_slice($ascending, 0, 2), 'ties fall back to the default order');
    }

    public function testTheCountIsTheNumberOfAwaitingRegistrations(): void
    {
        $this->seed();
        $spec = new AggregateSpec('awaits_answer', AggregateKind::Yes);

        self::assertSame([3], $this->query()->aggregates($this->held(), [$spec]));
        self::assertSame(
            [3],
            $this->query()->aggregates($this->held(), [$spec], new Narrowing([
                ColumnFilter::of('status', FilterKind::Members, 'isAny', ['registered']),
            ]))
        );
        self::assertSame(
            [0],
            $this->query()->aggregates($this->held(), [$spec], new Narrowing([
                ColumnFilter::of('status', FilterKind::Members, 'isAny', ['waitlisted']),
            ]))
        );
        self::assertSame([0], $this->query(false)->aggregates($this->held(), [$spec]));
    }

    public function testAViewThatDoesNotListTheColumnCannotFilterSortOrCountByIt(): void
    {
        $this->seed();
        $unlisted = $this->held(false);
        $attempts = [
            fn () => $this->query()->page($unlisted, 'awaits_answer'),
            fn () => $this->query()->page($unlisted, narrowing: $this->narrowing('is', true)),
            fn () => $this->query()->count($unlisted, $this->narrowing('is', true)),
            fn () => $this->query()->aggregates($unlisted, [new AggregateSpec('awaits_answer', AggregateKind::Yes)]),
        ];
        foreach ($attempts as $attempt) {
            try {
                $attempt();
                self::fail('Expected InvalidInput.');
            } catch (InvalidInput) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testTheColumnIsNeitherAFilterTermNorACountedMember(): void
    {
        foreach (
            [
                fn () => ViewShape::of(
                    [['column' => 'awaits_answer', 'in' => ['1']]],
                    [['column' => 'code', 'write' => false]]
                ),
                fn () => ViewShape::of(
                    [],
                    [['column' => 'awaits_answer', 'write' => false]],
                    ['awaits_answer' => ['1']]
                ),
            ] as $attempt
        ) {
            try {
                $attempt();
                self::fail('Expected InvalidInput.');
            } catch (InvalidInput) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame(
            'awaits_answer',
            ViewShape::of([], [['column' => 'awaits_answer', 'write' => false]])->columns[0]['column']
        );
    }

    public function testThePageCostsTheSameStatementsForOneRowAndForFiveAndNoMoreThanWithNoSource(): void
    {
        $this->seed();

        $cost = function (ViewQuery $query, int $limit): int {
            $count = 0;
            $capture = static function (string $sql) use (&$count): string {
                ++$count;

                return $sql;
            };
            add_filter('query', $capture);
            try {
                $query->page($this->held(), limit: $limit);
            } finally {
                remove_filter('query', $capture);
            }

            return $count;
        };

        self::assertSame($cost($this->query(), 1), $cost($this->query(), 5));
        self::assertSame($cost($this->query(false), 5), $cost($this->query(), 5));
    }
}
