<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Admin;

use Kalaka\Admin\Api\HeldView;
use Kalaka\Admin\Api\ViewQuery;
use Kalaka\Admin\View\ViewShape;
use Kalaka\Registrations\Api\Requests;
use Kalaka\Tests\Integration\Registrations\TestRequestSource;

/**
 * A grid row carries `awaits_answer`, computed per page by Registrations' `Requests` at a fixed cost; it is a row
 * value, not a column. (Shapes) grid rows carry awaits_answer: bool, PHP-computed per page; not filterable,
 * sortable or summable (alt: a per-request-source SQL predicate seam, after opening). [UNAPPROVED N351]
 */
final class ViewAwaitsAnswerTest extends AdminTestCase
{
    private const EVENT = 7;

    private function seed(): void
    {
        global $wpdb;
        // An offer awaiting, an offer answered (a refusal is an answer), an aid request awaiting, a withdrawn
        // registration with an unanswered offer, and one with no request.
        $this->addRegistration(self::EVENT, '00A0', 'registered', '2026-11-30 08:00:00');
        $this->addRegistration(self::EVENT, '00A2', 'registered', '2026-11-30 08:01:00');
        $this->addRegistration(self::EVENT, '00A5', 'waitlisted', '2026-11-30 08:02:00');
        $this->addRegistration(self::EVENT, '00A8', 'withdrawn', '2026-11-30 08:03:00');
        $this->addRegistration(self::EVENT, '00B0', 'registered', '2026-11-30 08:04:00');
        $this->addValue(self::EVENT, '00A0', 'support', 'church');
        $this->addValue(self::EVENT, '00A2', 'support', 'church');
        $this->addValue(self::EVENT, '00A5', 'aid_asked', '5000');
        $this->addValue(self::EVENT, '00A8', 'support', 'church');
        $this->addValue(self::EVENT, '00B0', 'support', 'nincs');
        // Another event's request never counts here.
        $this->addRegistration(self::EVENT + 1, '00A2');
        $this->addValue(self::EVENT + 1, '00A2', 'support', 'church');
        $wpdb->insert($wpdb->prefix . 'kalaka_answers', [
            'event_id' => self::EVENT,
            'code' => '00A2',
            'request' => TestRequestSource::OFFER,
            'approved' => 0,
            'answered_at' => '2026-12-04 09:00:00.000000',
        ]);
    }

    private function query(): ViewQuery
    {
        return new ViewQuery(requests: new Requests([new TestRequestSource()]));
    }

    private function held(): HeldView
    {
        $shape = ViewShape::of([], [['column' => 'code', 'write' => false], ['column' => 'status', 'write' => false]]);

        return new HeldView(self::EVENT, 1, null, null, $shape, null);
    }

    public function testRowsCarryAwaitsAnswerByTheOneRule(): void
    {
        $this->seed();

        $page = $this->query()->page($this->held(), limit: 10);

        $awaits = [];
        foreach ($page->rows as $row) {
            self::assertIsBool($row->awaitsAnswer);
            self::assertSame(['code', 'status'], array_keys($row->values), 'Not a column.');
            $awaits[$row->key->code] = $row->awaitsAnswer;
        }
        self::assertSame(
            ['00A0' => true, '00A2' => false, '00A5' => true, '00A8' => false, '00B0' => false],
            $awaits
        );
        self::assertSame(['code', 'status'], array_column($page->columns, 'column'));
    }

    public function testTheBatchAgreesWithAwaits(): void
    {
        $this->seed();
        $requests = new Requests([new TestRequestSource()]);

        foreach ($this->query()->page($this->held(), limit: 10)->rows as $row) {
            $status = \Kalaka\Registrations\Api\RegistrationStatus::from((string) $row->values['status']);
            self::assertSame($requests->awaits($row->key, $status), $row->awaitsAnswer, $row->key->code);
        }
    }

    public function testThePageCostsTheSameStatementsForOneRowAndForFive(): void
    {
        $this->seed();

        $cost = function (int $limit): int {
            $count = 0;
            $capture = static function (string $sql) use (&$count): string {
                ++$count;

                return $sql;
            };
            add_filter('query', $capture);
            try {
                $this->query()->page($this->held(), limit: $limit);
            } finally {
                remove_filter('query', $capture);
            }

            return $count;
        };

        $one = $cost(1);
        $five = $cost(5);
        self::assertSame($one, $five);
    }

    public function testWithNoSourceThePageReadsNothingExtra(): void
    {
        $this->seed();
        $count = static function (ViewQuery $query, HeldView $held): int {
            $n = 0;
            $capture = static function (string $sql) use (&$n): string {
                ++$n;

                return $sql;
            };
            add_filter('query', $capture);
            try {
                $query->page($held, limit: 5);
            } finally {
                remove_filter('query', $capture);
            }

            return $n;
        };

        $none = $count(new ViewQuery(requests: new Requests([])), $this->held());
        $with = $count($this->query(), $this->held());

        self::assertSame(2, $with - $none, 'The field values and the answers, one statement each.');
    }
}
