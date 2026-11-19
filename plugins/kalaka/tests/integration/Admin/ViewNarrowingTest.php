<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Admin;

use Kalaka\Admin\Api\ColumnFilter;
use Kalaka\Admin\Api\FilterKind;
use Kalaka\Admin\Api\HeldView;
use Kalaka\Admin\Api\Narrowing;
use Kalaka\Admin\Api\SearchWord;
use Kalaka\Admin\Api\ViewQuery;
use Kalaka\Admin\Grid\FieldDefinition;
use Kalaka\Admin\Grid\FieldDefinitions;
use Kalaka\Admin\Grid\GridModel;
use Kalaka\Admin\Grid\GridNarrowing;
use Kalaka\Admin\View\ViewShape;
use Kalaka\Foundation\Api\Decision\Decisions;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Decision\DecisionRules;
use Kalaka\Tests\Integration\Foundation\Evaluation\TestRule;
use Kalaka\Registrations\Api\Serials;

/**
 * S37, round 5: the grid's ad hoc filters and search on top of a held view. They only narrow, only on the
 * view's listed columns, fold case and accents, and number the sorszám as `RegistrationList` does.
 */
final class ViewNarrowingTest extends AdminTestCase
{
    private const EVENT = 7;

    private const COLUMNS = [
        'serial', 'code', 'status', 'field:lunch', 'field:programs', 'field:photo', 'field:nights',
        'field:note', 'field:born', 'decision:confirmation',
    ];

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        $rows = [
            // code, status, time, lunch, programs, photo, nights, note, born, confirmation
            [
                '00A0', 'registered', '08:00:00', 'veg', '["hike","craft"]', '1', '3', 'Ödön kér', '2010-03-03',
                'confirmed',
            ],
            ['00A2', 'registered', '08:01:00', 'VEG', '["sport"]', '0', '2.5', 'Ütő lesz', '2012-07-12', 'pending'],
            ['00A5', 'registered', '08:02:00', 'normal', '["craft"]', null, '', 'Árpád és Őrs', null, 'confirmed'],
            ['00A8', 'waitlisted', '08:03:00', 'normal', null, '1', '4', 'Űrlap 100% kész', '2014-01-01', null],
            ['00B0', 'waitlisted', '08:04:00', 'veg', '["hike"]', null, '10', null, '2010-03-03', 'confirmed'],
            ['00B3', 'withdrawn', '08:05:00', 'veg', null, '0', '1', 'x_y', '2009-12-31', 'confirmed'],
        ];
        foreach ($rows as [$code, $status, $time, $lunch, $programs, $photo, $nights, $note, $born, $confirmation]) {
            $this->addRegistration(self::EVENT, $code, $status, '2026-11-30 ' . $time);
            foreach (
                [
                    'lunch' => $lunch, 'programs' => $programs, 'photo' => $photo, 'nights' => $nights,
                    'note' => $note, 'born' => $born,
                ] as $key => $value
            ) {
                if ($value !== null) {
                    $this->addValue(self::EVENT, $code, $key, $value);
                }
            }
            if ($confirmation !== null) {
                $this->addDecision(self::EVENT, $code, 'confirmation', 'pending');
                $this->addDecision(self::EVENT, $code, 'confirmation', $confirmation);
            }
        }
        // Another event's rows never match.
        $this->addRegistration(self::EVENT + 1, '00A0');
        $this->addValue(self::EVENT + 1, '00A0', 'note', 'Ödön kér');
        $this->addValue(self::EVENT + 1, '00A0', 'nights', '3');
    }

    /** @param list<array<string, mixed>> $filter */
    private function view(array $filter = [], ?string $bound = null, ?int $grant = 1): HeldView
    {
        $columns = array_map(static fn (string $c): array => ['column' => $c, 'write' => false], self::COLUMNS);

        return new HeldView(self::EVENT, $grant, 1, 'Nézet', ViewShape::of($filter, $columns), $bound);
    }

    /** @return array<string, FieldDefinition> */
    private static function fields(): array
    {
        $options = ['veg' => 'Vegetáriánus', 'normal' => 'Normál'];
        $programs = ['hike' => 'Kirándulás', 'craft' => 'Kézműves', 'sport' => 'Sport'];

        return [
            'lunch' => new FieldDefinition('lunch', 'kalaka/choice-field', 'Ebéd', $options, true, 'choice'),
            'programs' => new FieldDefinition(
                'programs',
                'kalaka/choice-field',
                'Programok',
                $programs,
                false,
                'choice'
            ),
            'photo' => new FieldDefinition('photo', FieldDefinitions::CONSENT_TYPE, 'Fénykép', [], false, 'choice'),
            'nights' => new FieldDefinition('nights', 'kalaka/number-field', 'Éjszakák', [], false, 'number'),
            'note' => new FieldDefinition('note', 'kalaka/text-field', 'Megjegyzés', [], false, 'text'),
            'born' => new FieldDefinition('born', 'kalaka/date-field', 'Születési dátum', [], false, 'date'),
        ];
    }

    /**
     * The codes of the rows a narrowing leaves, in the sorszám order.
     *
     * @param list<array<string, mixed>> $filters
     * @return list<string>
     */
    private function codes(HeldView $view, array $filters = [], ?string $search = null): array
    {
        // A declared confirmation kind gives the decision column its Hungarian label, as a module's rule does.
        $rules = new DecisionRules();
        $rules->add(
            new TestRule(
                'confirmation',
                ['pending' => 'Függőben', 'confirmed' => 'Visszaigazolva'],
                label: 'Visszaigazolás'
            ),
            false
        );
        $model = new GridModel(decisions: new Decisions(rules: $rules));
        $narrowing = (new GridNarrowing($model))->of($view, self::fields(), $filters, $search);
        $page = (new ViewQuery())->page($view, limit: ViewQuery::MAX_LIMIT, narrowing: $narrowing);
        self::assertSame(
            count($page->rows),
            $page->total,
            'the total counts the narrowed set'
        );
        self::assertSame($page->total, (new ViewQuery())->count($view, $narrowing));

        return array_map(static fn ($row): string => $row->key->code, $page->rows);
    }

    /** The codes one filter leaves in the whole view. */
    private function where(string $field, string $operator, mixed $value): array
    {
        return $this->codes($this->view(), [self::filter($field, $operator, $value)]);
    }

    private static function filter(string $field, string $operator, mixed $value): array
    {
        return ['field' => $field, 'operator' => $operator, 'value' => $value];
    }

    public function testNoNarrowingChangesNothing(): void
    {
        self::assertSame(['00A0', '00A2', '00A5', '00A8', '00B0', '00B3'], $this->codes($this->view()));
    }

    public function testStatusAndChoiceFiltersAreAnyOrNone(): void
    {
        $view = $this->view();

        self::assertSame(['00A8', '00B0'], $this->where('status', 'isAny', ['waitlisted']));
        self::assertSame(
            ['00A0', '00A2', '00A5'],
            $this->codes($view, [self::filter('status', 'isNone', ['waitlisted', 'withdrawn'])])
        );
        // The collation folds case, as the view's own filter does: VEG is veg.
        self::assertSame(['00A0', '00A2', '00B0', '00B3'], $this->where('field:lunch', 'isAny', ['veg']));
        self::assertSame(['00A5', '00A8'], $this->where('field:lunch', 'isNone', ['veg']));
        // A multiple choice holds the member; no answer is none of them.
        self::assertSame(['00A0', '00B0'], $this->where('field:programs', 'isAny', ['hike']));
        self::assertSame(
            ['00A2', '00A8', '00B3'],
            $this->codes($view, [self::filter('field:programs', 'isNone', ['hike', 'craft'])])
        );
    }

    public function testYesNoKeepsTrueFalseAndNoAnswerApart(): void
    {
        $view = $this->view();

        self::assertSame(['00A0', '00A8'], $this->where('field:photo', 'is', true));
        self::assertSame(['00A2', '00B3'], $this->where('field:photo', 'is', false));
        // Not yes is no and no answer; not no is yes and no answer. Nothing is folded into „no”.
        self::assertSame(['00A2', '00A5', '00B0', '00B3'], $this->where('field:photo', 'isNot', true));
        self::assertSame(['00A0', '00A5', '00A8', '00B0'], $this->where('field:photo', 'isNot', false));
        self::assertSame(
            ['00A5', '00B0'],
            $this->codes($view, [self::filter(ColumnFilter::UNANSWERED, 'isAny', ['field:photo'])])
        );
    }

    public function testNumberFiltersAndRanges(): void
    {
        $view = $this->view();

        self::assertSame(['00A8'], $this->where('field:nights', 'is', 4));
        self::assertSame(['00A2'], $this->where('field:nights', 'is', '2.5'));
        self::assertSame(['00A2', '00B3'], $this->where('field:nights', 'lessThan', 3));
        self::assertSame(['00A8', '00B0'], $this->where('field:nights', 'greaterThan', 3));
        self::assertSame(['00A0', '00A2', '00A8'], $this->where('field:nights', 'between', [2.5, 4]));
        // No value is no number: never matched, even by a range that holds 0.
        self::assertSame(['00A2', '00B3'], $this->where('field:nights', 'between', [0, 2.5]));
    }

    public function testDateTextAndCodeFilters(): void
    {
        $view = $this->view();

        self::assertSame(['00A0', '00B0', '00B3'], $this->where('field:born', 'before', '2011-01-01'));
        self::assertSame(['00A2', '00A8'], $this->where('field:born', 'after', '2012-01-01T00:00:00'));
        self::assertSame(['00A0'], $this->where('code', 'contains', '0a0'));
        // Accents and case fold, and the LIKE characters of the text are plain: ö/ő and ü/ű included.
        self::assertSame(['00A0'], $this->where('field:note', 'contains', 'ODON'));
        self::assertSame(['00A2'], $this->where('field:note', 'contains', 'utő'));
        self::assertSame(['00A5'], $this->where('field:note', 'contains', 'orS'));
        self::assertSame(['00A8'], $this->where('field:note', 'contains', 'urlap'));
        self::assertSame(['00A8'], $this->where('field:note', 'contains', '100%'));
        self::assertSame(['00B3'], $this->where('field:note', 'contains', 'x_y'));
    }

    public function testDecisionFiltersAndUnanswered(): void
    {
        $view = $this->view();

        self::assertSame(
            ['00A0', '00A5', '00B0', '00B3'],
            $this->codes($view, [self::filter('decision:confirmation', 'isAny', ['confirmed'])])
        );
        // None of „pending” includes the registration with no decision at all.
        self::assertSame(
            ['00A0', '00A5', '00A8', '00B0', '00B3'],
            $this->codes($view, [self::filter('decision:confirmation', 'isNone', ['pending'])])
        );
        self::assertSame(
            ['00A8'],
            $this->codes($view, [self::filter(ColumnFilter::UNANSWERED, 'isAny', ['decision:confirmation'])])
        );
        // „Nincs válasz” on several columns: some of them unanswered. An empty value is no answer.
        $this->addValue(self::EVENT, '00B0', 'note', '');
        self::assertSame(
            ['00A5', '00B0'],
            $this->codes($view, [self::filter(ColumnFilter::UNANSWERED, 'isAny', ['field:note', 'field:photo'])])
        );
        self::assertSame(
            ['00B0'],
            $this->codes($view, [self::filter(ColumnFilter::UNANSWERED, 'isAny', ['field:note'])])
        );
        self::assertSame(
            ['00A5', '00B0'],
            $this->codes($view, [self::filter(ColumnFilter::UNANSWERED, 'isAny', ['field:photo'])])
        );
    }

    public function testTheSorszamRangeIsNumberedAsTheRegistrationListNumbersIt(): void
    {
        $view = $this->view();
        $serials = (new Serials())->forEvent(self::EVENT);

        // Registered and waitlisted are counted separately: a range of 1 to 2 holds both counts.
        $both = $this->codes($view, [self::filter('serial', 'between', [1, 2])]);
        $expected = array_keys(array_filter($serials, static fn (int $n): bool => $n >= 1 && $n <= 2));
        sort($expected);
        self::assertSame($expected, $both);

        foreach ([[1, 1], [2, 3], [3, 3], [1, 9], [4, 9]] as [$from, $to]) {
            $got = $this->codes($view, [self::filter('serial', 'between', [$from, $to])]);
            $want = array_keys(array_filter($serials, static fn (int $n): bool => $n >= $from && $n <= $to));
            sort($want);
            $sorted = $got;
            sort($sorted);
            self::assertSame($want, $sorted, "range $from to $to");
        }
        // A withdrawn registration has no sorszám and is never in a range.
        self::assertNotContains('00B3', $this->where('serial', 'between', [0, 99]));
    }

    public function testSearchMatchesWhatTheCellsShowIgnoringCaseAndAccents(): void
    {
        $view = $this->view();

        // The shown words of the status, a choice, a yes/no and a decision, not their keys.
        self::assertSame(['00A8', '00B0'], $this->codes($view, [], 'várólistás'));
        self::assertSame(['00A8', '00B0'], $this->codes($view, [], 'varolistas'));
        self::assertSame(['00A0', '00A2', '00B0', '00B3'], $this->codes($view, [], 'VEGETARIANUS'));
        self::assertSame(['00A0', '00A8'], $this->codes($view, [], 'igen'));
        self::assertSame(['00A2', '00B3'], $this->codes($view, [], 'nem'));
        self::assertSame(['00A0', '00A5', '00B0', '00B3'], $this->codes($view, [], 'visszaigazolva'));
        // Text, accents of every kind: ö, ü, ő, ű, á.
        self::assertSame(['00A0'], $this->codes($view, [], 'odon'));
        self::assertSame(['00A2'], $this->codes($view, [], 'UTO'));
        self::assertSame(['00A5'], $this->codes($view, [], 'orS arpad'));
        self::assertSame(['00A8'], $this->codes($view, [], 'űrlap'));
        // The code, the sorszám, a date as shown („2012. 07.”) and a number as shown („2,5”).
        self::assertSame(['00B3'], $this->codes($view, [], '00b3'));
        self::assertSame(['00A2'], $this->codes($view, [], '2012. 07.'));
        self::assertSame(['00A2'], $this->codes($view, [], '2,5'));
        self::assertSame([], $this->codes($view, [], 'nincsilyen'));
        // Several words must each match somewhere in the row.
        self::assertSame(['00A0'], $this->codes($view, [], 'odon igen'));
        // The stored key of a choice is not shown, so it matches nothing: „veg” is in „Vegetáriánus” only.
        self::assertSame(['00A0', '00A2', '00B0', '00B3'], $this->codes($view, [], 'veg'));
        self::assertSame([], $this->codes($view, [], 'normal-key'));
    }

    public function testFiltersAndSearchOnlyNarrowTheViewsOwnFilter(): void
    {
        $parameter = [['column' => 'field:lunch', 'parameter' => true]];
        $grantHolder = $this->view($parameter, 'normal');
        $full = $this->view($parameter, null, null);

        self::assertSame(['00A5', '00A8'], $this->codes($grantHolder));
        // The whole set of values does not widen what the view holds.
        self::assertSame(
            ['00A5', '00A8'],
            $this->codes($grantHolder, [self::filter('field:lunch', 'isAny', ['veg', 'normal'])])
        );
        self::assertSame([], $this->codes($grantHolder, [self::filter('field:lunch', 'isAny', ['veg'])]));
        self::assertSame(['00A5'], $this->codes($grantHolder, [self::filter('status', 'isAny', ['registered'])]));
        // A search matching only rows outside the view finds none of them.
        self::assertSame([], $this->codes($grantHolder, [], 'odon'));
        self::assertSame(['00A5'], $this->codes($grantHolder, [], 'arpad'));
        // Full access on the parameter view holds the whole of it, narrowed the same way.
        self::assertSame(['00A0', '00A2', '00A5', '00A8', '00B0', '00B3'], $this->codes($full));
        self::assertSame(['00A5', '00A8'], $this->codes($full, [self::filter('field:lunch', 'isAny', ['normal'])]));
    }

    public function testASearchOrFilterOnAColumnTheViewDoesNotListFindsNothingAndIsRefused(): void
    {
        $columns = [['column' => 'code', 'write' => false], ['column' => 'status', 'write' => false]];
        $view = new HeldView(self::EVENT, 1, 1, 'Szűk', ViewShape::of([], $columns), null);

        // A hidden column's values are not searched: „odon” is only in the note, which the view does not list.
        self::assertSame([], $this->codes($view, [], 'odon'));
        self::assertSame(['00A0'], $this->codes($view, [], '00a0'));
        $this->expectException(InvalidInput::class);
        $this->codes($view, [self::filter('field:note', 'contains', 'odon')]);
    }

    /** @dataProvider refused */
    public function testBadFiltersAreRefused(mixed $filters, ?string $search = null): void
    {
        $view = $this->view();
        $this->expectException(InvalidInput::class);
        $narrowing = (new GridNarrowing())->of($view, self::fields(), $filters, $search);
        (new ViewQuery())->page($view, narrowing: $narrowing);
    }

    /** @return iterable<string, array{0: mixed, 1?: ?string}> */
    public static function refused(): iterable
    {
        yield 'not json' => ['{'];
        yield 'not a list' => ['{"field": "status"}'];
        yield 'no field' => [[['operator' => 'isAny', 'value' => ['x']]]];
        yield 'unknown column' => [[self::filter('field:ghost', 'contains', 'x')]];
        yield 'unknown operator' => [[self::filter('status', 'contains', 'x')]];
        yield 'an operator of another type' => [[self::filter('field:nights', 'contains', '1')]];
        yield 'a list that is not a list' => [[self::filter('status', 'isAny', 'registered')]];
        yield 'an empty list' => [[self::filter('status', 'isAny', [])]];
        yield 'a text that is not text' => [[self::filter('code', 'contains', ['a'])]];
        yield 'an empty text' => [[self::filter('code', 'contains', '   ')]];
        yield 'a number that is not a number' => [[self::filter('field:nights', 'is', 'abc')]];
        yield 'a range with one end' => [[self::filter('serial', 'between', [1])]];
        yield 'a range with a fraction' => [[self::filter('serial', 'between', [1.5, 3])]];
        yield 'a range operator on the sorszám that is not between' => [[self::filter('serial', 'is', 1)]];
        yield 'a date that is not a day' => [[self::filter('field:born', 'before', '2011-02-30')]];
        yield 'a yes/no that is not a bool' => [[self::filter('field:photo', 'is', 'yes')]];
        yield 'the same column twice' => [[
            self::filter('status', 'isAny', ['registered']),
            self::filter('status', 'isNone', ['removed']),
        ]];
        yield 'unanswered on the code' => [[self::filter(ColumnFilter::UNANSWERED, 'isAny', ['code'])]];
        yield 'unanswered on an unlisted column' => [[
            self::filter(ColumnFilter::UNANSWERED, 'isAny', ['field:ghost']),
        ]];
        yield 'too many filters' => [
            array_fill(0, Narrowing::MAX_FILTERS + 1, self::filter('status', 'isAny', ['registered'])),
        ];
        yield 'too many values' => [[
            self::filter('status', 'isAny', array_map('strval', range(1, Narrowing::MAX_VALUES + 1))),
        ]];
        yield 'too long a text' => [[self::filter('code', 'contains', str_repeat('a', Narrowing::MAX_SEARCH + 1))]];
        yield 'too long a search' => [[], str_repeat('a', Narrowing::MAX_SEARCH + 1)];
        yield 'too many words' => [[], implode(' ', range(1, Narrowing::MAX_WORDS + 1))];
    }

    public function testTheQueryRefusesAFilterThatDoesNotFitItsColumn(): void
    {
        $view = $this->view();
        $this->expectException(InvalidInput::class);
        (new ViewQuery())->page(
            $view,
            narrowing: new Narrowing([ColumnFilter::of('status', FilterKind::Text, 'contains', 'x')])
        );
    }

    public function testTheSumRowFollowsTheNarrowing(): void
    {
        $view = $this->view();
        $model = new GridModel();
        $waitlisted = [self::filter('status', 'isAny', ['waitlisted'])];
        $narrowing = (new GridNarrowing())->of($view, self::fields(), $waitlisted, null);

        $all = $model->sums($view, self::fields());
        $narrowed = $model->sums($view, self::fields(), $narrowing);

        self::assertSame(6, $all['code']['n']);
        self::assertSame(2, $narrowed['code']['n']);
        self::assertSame('14', $narrowed['field:nights']['n']);
        self::assertSame('20.5', $all['field:nights']['n']);
        self::assertSame(1, $narrowed['field:photo']['n']);
        self::assertSame(2, $all['field:photo']['n']);
    }

    public function testAWordWithNoKeysMatchesNothingInThatColumn(): void
    {
        $view = $this->view();
        $word = new SearchWord('zzz', ['status' => []]);

        $page = (new ViewQuery())->page($view, narrowing: new Narrowing([], [$word]));

        self::assertSame(0, $page->total);
    }
}
