<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Admin;

use Kalaka\Foundation\Api\Decision\Decisions;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Decision\DecisionRules;
use Kalaka\Tests\Integration\Foundation\Evaluation\TestRule;

/** S22 and S38: the detail read of one registration, through views and grants. */
final class RegistrationDetailTest extends RegistrationDetailTestCase
{
    /** @var array{recorder: int, charge: int, payment: int, refund: int} */
    private array $seeded = ['recorder' => 0, 'charge' => 0, 'payment' => 0, 'refund' => 0];

    private function decisions(): Decisions
    {
        $rules = new DecisionRules();
        $rules->add(
            new TestRule(
                'confirmation',
                ['unconfirmed' => 'Nincs visszaigazolva', 'confirmed' => 'Visszaigazolva'],
                label: 'Visszaigazolás'
            ),
            true
        );
        $rules->add(new TestRule('payment_hold', ['held' => 'Zárolva'], label: 'Fizetési zárolás'), true);

        return new Decisions(rules: $rules);
    }

    /** A registration with a form, its answers, a decision and a full ledger. */
    private function seedDetail(): void
    {
        $form = $this->detailForm($this->field('date', 'szuletesi_datum', ['label' => 'Születési dátum']));
        $this->addRegistration('00A0');
        $this->addSubmission('00A0', $form->id, $form->version);
        $this->addValue('00A0', 'name', 'Teszt Elek');
        $this->addValue('00A0', 'email', 'teszt.elek@example.com');
        $this->addValue('00A0', 'szuletesi_datum', '2009-04-17');
        $this->addDecision('00A0', 'confirmation', 'unconfirmed');
        $this->addDecision('00A0', 'payment_hold', 'held');

        $recorder = $this->namedUser('Teszt Könyvelő');
        $charge = $this->addLine('charge', '60000', 'charge:1', overrides: [
            'source_type' => 'fee_component',
            'source_key' => 'fixed_price',
            'value_date' => null,
            'method' => null,
        ]);
        $payment = $this->addLine('manual_payment', '-35000', 'manual:p1', overrides: [
            'source_type' => 'wp_user',
            'source_key' => (string) $recorder,
        ]);
        $this->addNote($payment, 'Közlemény: 7KQ4 Teszt Elek');
        $refund = $this->addLine('manual_refund', '5000', 'manual:r1', overrides: [
            'source_type' => 'wp_user',
            'source_key' => (string) $recorder,
            'method' => 'cash',
            'correction' => 1,
        ]);
        $this->addNote($refund, 'Elírás: 35 000 helyett 30 000 érkezett');

        $this->seeded = [
            'recorder' => $recorder,
            'charge' => $charge,
            'payment' => $payment,
            'refund' => $refund,
        ];
    }

    public function testFullAccessSeesTheWholeRegistration(): void
    {
        $this->seedDetail();

        $detail = $this->detail($this->admin->userId, decisions: $this->decisions());

        self::assertSame(
            [
                'code' => '00A0',
                'name' => 'Teszt Elek',
                'email' => 'teszt.elek@example.com',
                'serial' => 1,
                'status' => 'registered',
                'status_label' => 'Rögzített',
                'registered_at' => '2026-11-30T08:00:00Z',
                'submitted_at' => '2026-11-30T08:00:00Z',
                'decisions' => [
                    [
                        'kind' => 'confirmation',
                        'label' => 'Visszaigazolás',
                        'state' => 'unconfirmed',
                        'state_label' => 'Nincs visszaigazolva',
                        'effective_at' => '2026-11-30T08:00:00Z',
                    ],
                    [
                        'kind' => 'payment_hold',
                        'label' => 'Fizetési zárolás',
                        'state' => 'held',
                        'state_label' => 'Zárolva',
                        'effective_at' => '2026-11-30T08:00:00Z',
                    ],
                ],
            ],
            $detail['registration']
        );

        self::assertSame(
            [
                [
                    'key' => 'name',
                    'label' => 'Név',
                    'type' => 'kalaka/name-field',
                    'value' => 'Teszt Elek',
                    'text' => 'Teszt Elek',
                ],
                [
                    'key' => 'email',
                    'label' => 'E-mail-cím',
                    'type' => 'kalaka/email-field',
                    'value' => 'teszt.elek@example.com',
                    'text' => 'teszt.elek@example.com',
                ],
                [
                    'key' => 'szuletesi_datum',
                    'label' => 'Születési dátum',
                    'type' => 'kalaka/date-field',
                    'value' => '2009-04-17',
                    'text' => '2009. április 17.',
                ],
            ],
            $detail['fields']
        );

        $money = $detail['money'];
        self::assertSame('HUF', $money['currency']);
        self::assertSame(
            [[
                'payer' => 'participant',
                'label' => 'Jelentkező',
                'charged' => '60000.00000000',
                'paid' => '30000.00000000',
                'balance' => '30000.00000000',
            ]],
            $money['payers']
        );
        self::assertSame(
            ['charged' => '60000.00000000', 'paid' => '30000.00000000', 'balance' => '30000.00000000'],
            $money['total']
        );
        self::assertSame(
            [
                $this->lineRow(
                    $this->seeded['refund'],
                    'manual_refund',
                    'Visszatérítés',
                    '5000.00000000',
                    'cash',
                    'Készpénz',
                    'Elírás: 35 000 helyett 30 000 érkezett',
                    true
                ),
                $this->lineRow(
                    $this->seeded['payment'],
                    'manual_payment',
                    'Befizetés',
                    '-35000.00000000',
                    'bank_transfer',
                    'Átutalás',
                    'Közlemény: 7KQ4 Teszt Elek',
                    false
                ),
                [
                    'id' => $this->seeded['charge'],
                    'kind' => 'charge',
                    'kind_label' => 'Díj',
                    'payer' => 'participant',
                    'amount' => '60000.00000000',
                    'value_date' => null,
                    'paid_at' => null,
                    'recorded_at' => '2026-11-30T08:00:00Z',
                    'method' => null,
                    'method_label' => null,
                    'note' => null,
                    'correction' => false,
                    'recorded_by' => null,
                ],
            ],
            $money['lines']
        );
        self::assertSame(
            [
                ['value' => 'bank_transfer', 'label' => 'Átutalás'],
                ['value' => 'cash', 'label' => 'Készpénz'],
                ['value' => 'card_terminal', 'label' => 'Bankkártya'],
            ],
            $money['methods']
        );
        self::assertSame(
            ['view_money' => true, 'record_payment' => true, 'record_refund' => true],
            $detail['can']
        );
    }

    /** @return array<string, mixed> */
    private function lineRow(
        int $id,
        string $kind,
        string $kindLabel,
        string $amount,
        string $method,
        string $methodLabel,
        string $note,
        bool $correction,
    ): array {
        return [
            'id' => $id,
            'kind' => $kind,
            'kind_label' => $kindLabel,
            'payer' => 'participant',
            'amount' => $amount,
            'value_date' => '2026-12-02T23:00:00Z',
            'paid_at' => null,
            'recorded_at' => '2026-11-30T08:00:00Z',
            'method' => $method,
            'method_label' => $methodLabel,
            'note' => $note,
            'correction' => $correction,
            'recorded_by' => 'Teszt Könyvelő',
        ];
    }

    /** A2: a grant holder sees the columns of the grant's view and nothing else. */
    public function testAGrantHolderSeesOnlyTheViewsColumns(): void
    {
        $this->seedDetail();
        $user = $this->plainUser();
        $this->grant($user, [], [
            ['column' => 'code', 'write' => false],
            ['column' => 'status', 'write' => false],
            ['column' => 'field:name', 'write' => false],
            ['column' => 'decision:confirmation', 'write' => false],
        ], []);

        $detail = $this->detail($user, decisions: $this->decisions());

        self::assertSame(
            [
                'code' => '00A0',
                'name' => 'Teszt Elek',
                'status' => 'registered',
                'status_label' => 'Rögzített',
                'decisions' => [[
                    'kind' => 'confirmation',
                    'label' => 'Visszaigazolás',
                    'state' => 'unconfirmed',
                    'state_label' => 'Nincs visszaigazolva',
                    'effective_at' => '2026-11-30T08:00:00Z',
                ]],
            ],
            $detail['registration']
        );
        self::assertSame(
            [[
                'key' => 'name',
                'label' => 'Név',
                'type' => 'kalaka/name-field',
                'value' => 'Teszt Elek',
                'text' => 'Teszt Elek',
            ]],
            $detail['fields']
        );
        self::assertArrayNotHasKey('money', $detail);
        self::assertSame(
            ['view_money' => false, 'record_payment' => false, 'record_refund' => false],
            $detail['can']
        );
    }

    public function testWithoutAGrantTheReadIsNotFound(): void
    {
        $this->addRegistration('00A0');

        $this->expectException(NotFound::class);
        $this->detail($this->plainUser());
    }

    public function testAGrantWhoseViewExcludesTheRowIsNotFound(): void
    {
        $this->addRegistration('00A0');
        $user = $this->plainUser();
        $this->grant($user, [['column' => 'status', 'in' => ['waitlisted']]], [
            ['column' => 'code', 'write' => false],
        ], []);

        $this->expectException(NotFound::class);
        $this->detail($user);
    }

    public function testAnUnknownCodeIsNotFound(): void
    {
        $this->addRegistration('00A0');

        $this->expectException(NotFound::class);
        $this->detail($this->admin->userId, '00A1');
    }

    public function testAnUnknownEventIsNotFound(): void
    {
        $this->addRegistration('00A0');

        $this->expectException(NotFound::class);
        $this->detail($this->admin->userId, event: $this->event + 1000);
    }

    /** C3-1: a grant on one event never reaches the same code in another event. */
    public function testTheSameCodeInAnotherEventIsNotFoundForAHolderOfThisEvent(): void
    {
        $other = $this->create();
        $this->addRegistration('00A0');
        $this->addRegistration('00A0', event: $other);
        $user = $this->plainUser();
        $this->grant($user, [], [['column' => 'code', 'write' => false]], []);

        $this->expectException(NotFound::class);
        $this->detail($user, event: $other);
    }

    /** C3-1: full access reaches every event, so another event's registration reads. */
    public function testFullAccessReadsARegistrationInAnotherEvent(): void
    {
        $other = $this->create();
        $this->addRegistration('00A0', event: $other);

        $detail = $this->detail($this->admin->userId, event: $other);

        self::assertSame('00A0', $detail['registration']['code']);
    }

    /** The money is only there through the grant whose view holds the row. */
    public function testASecondGrantWithMoneyViewDoesNotShowMoneyOutsideItsView(): void
    {
        $this->seedDetail();
        $user = $this->plainUser();
        // The money-view grant's view excludes the registration; the other grant's view holds it.
        $this->grant($user, [['column' => 'status', 'in' => ['waitlisted']]], [
            ['column' => 'code', 'write' => false],
        ], ['kalaka_view_money']);
        $this->grant($user, [], [
            ['column' => 'code', 'write' => false],
        ], []);

        $detail = $this->detail($user, decisions: $this->decisions());

        self::assertArrayNotHasKey('money', $detail);
        self::assertSame(
            ['view_money' => false, 'record_payment' => false, 'record_refund' => false],
            $detail['can']
        );

        // A holder of the money-view action inside its view sees the money.
        $holder = $this->plainUser();
        $this->grant($holder, [], [['column' => 'code', 'write' => false]], ['kalaka_view_money']);

        $shown = $this->detail($holder, decisions: $this->decisions());

        self::assertArrayHasKey('money', $shown);
        self::assertSame(
            ['view_money' => true, 'record_payment' => false, 'record_refund' => false],
            $shown['can']
        );
    }

    /** The owner's ruling 2026-11-18: money-write includes money-view. */
    public function testMoneyWriteIncludesMoneyView(): void
    {
        $this->seedDetail();

        $cases = [
            'kalaka_record_payment' => ['view_money' => true, 'record_payment' => true, 'record_refund' => false],
            'kalaka_record_refund' => ['view_money' => true, 'record_payment' => false, 'record_refund' => true],
        ];
        foreach ($cases as $action => $can) {
            $user = $this->plainUser();
            $this->grant($user, [], [['column' => 'code', 'write' => false]], [$action]);

            $detail = $this->detail($user, decisions: $this->decisions());

            self::assertArrayHasKey('money', $detail, $action);
            self::assertSame($can, $detail['can'], $action);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function statuses(): iterable
    {
        yield 'registered' => ['registered', 'Rögzített'];
        yield 'waitlisted' => ['waitlisted', 'Várólistás'];
        yield 'withdrawn' => ['withdrawn', 'Visszavont'];
        yield 'removed' => ['removed', 'Törölt'];
    }

    /** @dataProvider statuses */
    public function testTheStatusLabelIsTheGridsWordCapitalised(string $status, string $label): void
    {
        $this->addRegistration('00A0', $status);
        $user = $this->plainUser();
        $this->grant($user, [], [
            ['column' => 'code', 'write' => false],
            ['column' => 'status', 'write' => false],
        ], []);

        $detail = $this->detail($user);

        self::assertSame($status, $detail['registration']['status']);
        self::assertSame($label, $detail['registration']['status_label']);
    }

    public function testTheRouteAnswersTheDetailOverRest(): void
    {
        $this->seedDetail();
        wp_set_current_user($this->admin->userId);

        $response = $this->rest('GET', '/kalaka-admin/v1/events/' . $this->event . '/registrations/00A0');

        self::assertSame(200, $response->get_status());
        $data = $response->get_data();
        self::assertIsArray($data);
        self::assertSame('00A0', $data['registration']['code']);
        self::assertArrayHasKey('money', $data);
        self::assertTrue($data['can']['view_money']);

        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        self::assertSame(
            403,
            $this->rest('GET', '/kalaka-admin/v1/events/' . $this->event . '/registrations/00A0')->get_status()
        );

        wp_set_current_user($this->admin->userId);
        self::assertSame(
            404,
            $this->rest('GET', '/kalaka-admin/v1/events/' . $this->event . '/registrations/00B0')->get_status()
        );
    }

    public function testNoStatementReadsTheSecret(): void
    {
        $this->seedDetail();
        $statements = [];
        $capture = static function (string $sql) use (&$statements): string {
            $statements[] = $sql;

            return $sql;
        };
        add_filter('query', $capture);
        try {
            $this->detail($this->admin->userId, decisions: $this->decisions());
        } finally {
            remove_filter('query', $capture);
        }

        self::assertNotSame([], $statements);
        foreach ($statements as $sql) {
            self::assertStringNotContainsString('secret', $sql);
        }
    }

    private function rest(string $method, string $route): \WP_REST_Response
    {
        $request = new \WP_REST_Request($method, $route);
        $response = rest_do_request($request);
        self::assertInstanceOf(\WP_REST_Response::class, $response);

        return $response;
    }
}
