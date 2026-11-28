<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Admin;

use Kalaka\Foundation\ObjectCache;
use Kalaka\Foundation\Api\Decision\Decisions;
use Kalaka\Admin\Api\RegistrationNotInView;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Admin\Api\RegistrationDetail;
use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Decision\DecisionRules;
use Kalaka\Pricing\Api\ConfirmationRule;
use Kalaka\Pricing\Api\FeeComponent;
use Kalaka\Pricing\Api\FeeComponentInput;
use Kalaka\Pricing\Api\FeeComposition;
use Kalaka\Pricing\Api\FeeStage;
use Kalaka\Pricing\Api\FeeTargets;
use Kalaka\Pricing\Api\ManualConfirmations;
use Kalaka\Pricing\CoreFeeComponents;
use Kalaka\Registrations\Api\Requests;
use Kalaka\Tests\Integration\Foundation\Evaluation\TestRule;
use Kalaka\Tests\Integration\Registrations\AnswerPool;
use Kalaka\Tests\Integration\Registrations\TestRequestSource;

/** S22 and S38: the detail read of one registration, through views and grants. */
final class RegistrationDetailTest extends RegistrationDetailTestCase
{
    /** @var array{recorder: int, charge: int, payment: int, refund: int} */
    private array $seeded = ['recorder' => 0, 'charge' => 0, 'payment' => 0, 'refund' => 0];

    private function decisions(): Decisions
    {
        $rules = new DecisionRules();
        $confirmation = new TestRule(
            'confirmation',
            ['unconfirmed' => 'Nincs visszaigazolva', 'confirmed' => 'Visszaigazolva'],
            label: 'Visszaigazolás'
        );
        $confirmation->marks = [
            'confirmed' => ['icon' => 'yes-alt', 'tone' => 'ok'],
            'unconfirmed' => ['icon' => 'marker', 'tone' => ''],
        ];
        $rules->add($confirmation, true);
        $rules->add(new TestRule('payment_hold', ['held' => 'Zárolva'], label: 'Fizetési zárolás'), true);
        $rules->add(new TestRule('sample_kind', ['held' => 'Zárolva'], label: 'Minta döntés'), true);

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
        $this->addDecision('00A0', 'sample_kind', 'held');

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
            'method' => null,
            'value_date' => '2026-11-30 08:00:00.000000',
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
                        'marks' => [
                            'confirmed' => ['icon' => 'yes-alt', 'tone' => 'ok'],
                            'unconfirmed' => ['icon' => 'marker', 'tone' => ''],
                        ],
                        'effective_at' => '2026-11-30T08:00:00Z',
                    ],
                    [
                        'kind' => 'sample_kind',
                        'label' => 'Minta döntés',
                        'state' => 'held',
                        'state_label' => 'Zárolva',
                        'marks' => [],
                        'effective_at' => '2026-11-30T08:00:00Z',
                    ],
                ],
                'confirmed' => false,
                'manual_confirmation' => null,
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
                    'Kézi visszafizetés',
                    '5000.00000000',
                    null,
                    null,
                    'Elírás: 35 000 helyett 30 000 érkezett',
                    true
                ),
                $this->lineRow(
                    $this->seeded['payment'],
                    'manual_payment',
                    'Kézi befizetés',
                    '-35000.00000000',
                    'bank_transfer',
                    'Átutalás',
                    'Közlemény: 7KQ4 Teszt Elek',
                    false
                ),
                [
                    'id' => $this->seeded['charge'],
                    'kind' => 'charge',
                    'type' => 'fee',
                    'side' => 'owed',
                    'kind_label' => 'Díj',
                    'title' => null,
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
            [
                'answer_request' => true,
                'view_money' => true,
                'record_money' => true,
                'record_charge' => true,
                'record_discount' => true,
                'confirm_manually' => true,
                'unconfirm' => false,
            ],
            $detail['can']
        );
    }

    /**
     * Topic 10: an owed line is named by the fact behind it, never by its sign: a manual charge whose reversal
     * is negative is still „Kézi díj”, a discount „Kézi kedvezmény”; the title and the recorder come from the
     * fact; a line of the fixed price stays „Díj”, type `fee`; and the paid kinds keep their kind as type.
     */
    public function testAnOwedLineIsNamedByItsFactAndCarriesItsTitleAndRecorder(): void
    {
        $this->seedDetail();
        $recorder = $this->seeded['recorder'];
        $ids = [];
        foreach ([['charge', 'Teszt kirándulás'], ['discount', 'Teszt kedvezmény']] as $index => [$type, $title]) {
            $ids[$type] = $this->addManualFee($type, $title, 'manual_fee:f' . $index, $recorder);
        }
        // A withdrawal's reversal of the charge is negative, the discount's own line is negative too.
        $reversed = $this->addLine('charge', '-3000', 'charge:manual:r', overrides: [
            'source_type' => 'fee_component',
            'source_key' => 'manual:' . $ids['charge'],
            'value_date' => null,
            'method' => null,
        ]);
        $discount = $this->addLine('charge', '-3000', 'charge:manual:d', overrides: [
            'source_type' => 'fee_component',
            'source_key' => 'manual:' . $ids['discount'],
            'value_date' => null,
            'method' => null,
        ]);

        $lines = array_column($this->detail($this->admin->userId)['money']['lines'], null, 'id');

        self::assertSame(
            ['manual_charge', 'owed', 'Kézi díj', 'Teszt kirándulás', 'Teszt Könyvelő'],
            [$lines[$reversed]['type'], $lines[$reversed]['side'], $lines[$reversed]['kind_label'],
                $lines[$reversed]['title'], $lines[$reversed]['recorded_by']]
        );
        self::assertSame('charge', $lines[$reversed]['kind']);
        self::assertSame(
            ['manual_discount', 'owed', 'Kézi kedvezmény', 'Teszt kedvezmény', 'Teszt Könyvelő'],
            [$lines[$discount]['type'], $lines[$discount]['side'], $lines[$discount]['kind_label'],
                $lines[$discount]['title'], $lines[$discount]['recorded_by']]
        );
        self::assertSame(['fee', 'owed', 'Díj', null], [
            $lines[$this->seeded['charge']]['type'],
            $lines[$this->seeded['charge']]['side'],
            $lines[$this->seeded['charge']]['kind_label'],
            $lines[$this->seeded['charge']]['title'],
        ]);
        self::assertSame(['manual_payment', 'paid'], [
            $lines[$this->seeded['payment']]['type'],
            $lines[$this->seeded['payment']]['side'],
        ]);
    }

    /** @return array<string, mixed> */
    private function lineRow(
        int $id,
        string $kind,
        string $kindLabel,
        string $amount,
        ?string $method,
        ?string $methodLabel,
        string $note,
        bool $correction,
    ): array {
        return [
            'id' => $id,
            'kind' => $kind,
            'type' => $kind,
            'side' => 'paid',
            'kind_label' => $kindLabel,
            'title' => null,
            'payer' => 'participant',
            'amount' => $amount,
            'value_date' => $correction ? '2026-11-30T08:00:00Z' : '2026-12-02T23:00:00Z',
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
                    'marks' => [
                        'confirmed' => ['icon' => 'yes-alt', 'tone' => 'ok'],
                        'unconfirmed' => ['icon' => 'marker', 'tone' => ''],
                    ],
                    'effective_at' => '2026-11-30T08:00:00Z',
                ]],
                'confirmed' => false,
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
            [
                'answer_request' => false,
                'view_money' => false,
                'record_money' => false,
                'record_charge' => false,
                'record_discount' => false,
                'confirm_manually' => false,
                'unconfirm' => false,
            ],
            $detail['can']
        );
    }

    public function testWithoutAGrantTheReadIsNotFound(): void
    {
        $this->addRegistration('00A0');

        $this->expectException(NotFound::class);
        $this->detail($this->plainUser());
    }

    /** The owner's ruling 2026-11-19 (topic 3): a grant holder of the event is told "no access", not "not found". */
    public function testAGrantWhoseViewExcludesTheRowIsNoAccess(): void
    {
        $this->addRegistration('00A0');
        $user = $this->plainUser();
        $this->grant($user, [['column' => 'status', 'in' => ['waitlisted']]], [
            ['column' => 'code', 'write' => false],
        ], []);

        try {
            $this->detail($user);
            self::fail('The read was not refused.');
        } catch (RegistrationNotInView $refused) {
            self::assertSame(403, $refused->status());
            self::assertSame('kalaka_registration_not_in_view', $refused->errorCode());
        }
    }

    /** The owner's ruling 2026-11-19: a grant holder of the event is told an unknown code does not exist. */
    public function testAGrantHolderAskingForAnUnknownCodeIsNotFound(): void
    {
        $this->addRegistration('00A0');
        $user = $this->plainUser();
        $this->grant($user, [['column' => 'status', 'in' => ['waitlisted']]], [
            ['column' => 'code', 'write' => false],
        ], []);

        $this->expectException(NotFound::class);
        $this->detail($user, '00A1');
    }

    /** A user whose grants are all on other events still gets "not found". */
    public function testAHolderOfAnotherEventsGrantOnlyIsNotFound(): void
    {
        $other = $this->create();
        $this->addRegistration('00A0');
        $this->addRegistration('00A0', event: $other);
        $user = $this->plainUser();
        $this->grant($user, [], [['column' => 'code', 'write' => false]], [], event: $other);

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

    /** The owner's ruling 2026-11-19 (topic 3): the two times are view columns like any other. */
    public function testTheTimesAreShownOnlyWhereTheViewListsThem(): void
    {
        $this->seedDetail();
        $none = $this->plainUser();
        $this->grant($none, [], [['column' => 'code', 'write' => false]], []);
        $registered = $this->plainUser();
        $this->grant($registered, [], [
            ['column' => 'code', 'write' => false],
            ['column' => 'registered_at', 'write' => false],
        ], []);
        $submitted = $this->plainUser();
        $this->grant($submitted, [], [
            ['column' => 'code', 'write' => false],
            ['column' => 'submitted_at', 'write' => false],
        ], []);

        $a = $this->detail($none)['registration'];
        self::assertArrayNotHasKey('registered_at', $a);
        self::assertArrayNotHasKey('submitted_at', $a);
        $b = $this->detail($registered)['registration'];
        self::assertSame('2026-11-30T08:00:00Z', $b['registered_at']);
        self::assertArrayNotHasKey('submitted_at', $b);
        $c = $this->detail($submitted)['registration'];
        self::assertSame('2026-11-30T08:00:00Z', $c['submitted_at']);
        self::assertArrayNotHasKey('registered_at', $c);
    }

    /** A waitlisted registration has no registered time to show. */
    public function testAWaitlistedRegistrationHasNoRegisteredAt(): void
    {
        $this->addRegistration('00A0', 'waitlisted');
        $user = $this->plainUser();
        $this->grant($user, [], [
            ['column' => 'code', 'write' => false],
            ['column' => 'registered_at', 'write' => false],
        ], []);

        self::assertArrayNotHasKey('registered_at', $this->detail($user)['registration']);
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
            [
                'answer_request' => false,
                'view_money' => false,
                'record_money' => false,
                'record_charge' => false,
                'record_discount' => false,
                'confirm_manually' => false,
                'unconfirm' => false,
            ],
            $detail['can']
        );

        // A holder of the money-view action inside its view sees the money.
        $holder = $this->plainUser();
        $this->grant($holder, [], [['column' => 'code', 'write' => false]], ['kalaka_view_money']);

        $shown = $this->detail($holder, decisions: $this->decisions());

        self::assertArrayHasKey('money', $shown);
        self::assertSame(
            [
                'answer_request' => false,
                'view_money' => true,
                'record_money' => false,
                'record_charge' => false,
                'record_discount' => false,
                'confirm_manually' => false,
                'unconfirm' => false,
            ],
            $shown['can']
        );
    }

    /** The owner's ruling 2026-11-18: money-write includes money-view. */
    public function testMoneyWriteIncludesMoneyView(): void
    {
        $this->seedDetail();

        $cases = [
            'kalaka_record_money' => [
                'answer_request' => false,
                'view_money' => true,
                'record_money' => true,
                'record_charge' => true,
                'record_discount' => true,
                'confirm_manually' => false,
                'unconfirm' => false,
            ],
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

        $holder = $this->plainUser();
        $this->grant($holder, [['column' => 'status', 'in' => ['waitlisted']]], [
            ['column' => 'code', 'write' => false],
        ], []);
        wp_set_current_user($holder);
        $refused = $this->rest('GET', '/kalaka-admin/v1/events/' . $this->event . '/registrations/00A0');
        self::assertSame(403, $refused->get_status());
        $body = $refused->get_data();
        self::assertIsArray($body);
        self::assertSame('kalaka_registration_not_in_view', $body['code'] ?? null);
        self::assertSame(
            404,
            $this->rest('GET', '/kalaka-admin/v1/events/' . $this->event . '/registrations/00B0')->get_status()
        );

        wp_set_current_user($this->admin->userId);
        self::assertSame(
            404,
            $this->rest('GET', '/kalaka-admin/v1/events/' . $this->event . '/registrations/00B0')->get_status()
        );
    }

    /**
     * C1-1: a form whose revision cannot be read yields the documented degraded null/null, and the
     * detail read still answers.
     */
    public function testAFormWhoseRevisionCannotBeReadLeavesTheContactKeysUnset(): void
    {
        $form = $this->detailForm();
        $this->addRegistration('00A0');
        $this->addSubmission('00A0', $form->id, $form->version);
        $this->addValue('00A0', 'name', 'Teszt Elek');
        $this->addValue('00A0', 'email', 'teszt.elek@example.com');
        wp_delete_post_revision($form->version);

        $detail = $this->detail($this->admin->userId);

        self::assertArrayNotHasKey('name', $detail['registration']);
        self::assertArrayNotHasKey('email', $detail['registration']);

        wp_set_current_user($this->admin->userId);
        $response = $this->rest('GET', '/kalaka-admin/v1/events/' . $this->event . '/registrations/00A0');
        self::assertSame(200, $response->get_status());
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

    /** S20: the manual confirmation flag shows behind the money gate, and the action shows in `can`. */
    public function testTheManualConfirmationFlagShowsWithTheMoneyAndTheActionInCan(): void
    {
        global $wpdb;
        $this->seedDetail();
        $setter = $this->namedUser('Teszt Szervező');
        $wpdb->insert($wpdb->prefix . 'kalaka_manual_confirmations', [
            'event_id' => $this->event,
            'code' => '00A0',
            'version' => 1,
            'set_at' => '2026-11-30 09:15:00.000000',
            'set_by' => $setter,
            'reason' => 'Teszt indok',
        ]);

        $full = $this->detail($this->admin->userId, decisions: $this->decisions());
        self::assertSame(
            ['set_at' => '2026-11-30T09:15:00Z', 'reason' => 'Teszt indok', 'set_by' => 'Teszt Szervező'],
            $full['registration']['manual_confirmation']
        );

        $confirmer = $this->plainUser();
        $this->grant($confirmer, [], [['column' => 'code', 'write' => false]], ['kalaka_confirm_manually']);
        $withoutMoney = $this->detail($confirmer, decisions: $this->decisions());
        self::assertArrayNotHasKey('manual_confirmation', $withoutMoney['registration']);
        self::assertTrue($withoutMoney['can']['confirm_manually']);

        $viewer = $this->plainUser();
        $this->grant($viewer, [], [['column' => 'code', 'write' => false]], ['kalaka_view_money']);
        $withMoney = $this->detail($viewer, decisions: $this->decisions());
        self::assertSame('Teszt indok', $withMoney['registration']['manual_confirmation']['reason']);
        self::assertFalse($withMoney['can']['confirm_manually']);

        $wpdb->update(
            $wpdb->prefix . 'kalaka_manual_confirmations',
            ['revoked_at' => '2026-11-30 10:00:00.000000'],
            ['event_id' => $this->event, 'code' => '00A0']
        );
        ObjectCache::clear();
        self::assertNull($this->detail($viewer, decisions: $this->decisions())['registration']['manual_confirmation']);
    }

    /**
     * The owner's topic 1 ruling, H3: the manual confirmation is offered only on a registered registration,
     * and only to a holder of the action.
     *
     * @dataProvider statuses
     */
    public function testConfirmManuallyIsOfferedOnlyOnARegisteredRegistration(string $status): void
    {
        $this->addRegistration('00A0', $status);
        $confirmer = $this->plainUser();
        $this->grant($confirmer, [], [['column' => 'code', 'write' => false]], ['kalaka_confirm_manually']);
        $viewer = $this->plainUser();
        $this->grant($viewer, [], [['column' => 'code', 'write' => false]], ['kalaka_view_money']);

        self::assertSame($status === 'registered', $this->detail($confirmer)['can']['confirm_manually']);
        self::assertSame($status === 'registered', $this->detail($this->admin->userId)['can']['confirm_manually']);
        self::assertFalse($this->detail($viewer)['can']['confirm_manually'], 'No action.');
    }

    /**
     * D15: `can.record_charge` and `can.record_discount` hold only while the registration is registered and the
     * holder may record money; the money-write action alone says `record_money` whatever the status.
     *
     * @dataProvider statuses
     */
    public function testRecordChargeAndDiscountFollowTheStatusAndTheMoneyRight(string $status): void
    {
        $this->addRegistration('00A0', $status);
        $writer = $this->plainUser();
        $this->grant($writer, [], [['column' => 'code', 'write' => false]], ['kalaka_record_money']);
        $viewer = $this->plainUser();
        $this->grant($viewer, [], [['column' => 'code', 'write' => false]], ['kalaka_view_money']);
        $registered = $status === 'registered';

        foreach ([$writer, $this->admin->userId] as $user) {
            $can = $this->detail($user)['can'];
            self::assertTrue($can['record_money']);
            self::assertSame($registered, $can['record_charge']);
            self::assertSame($registered, $can['record_discount']);
        }
        $can = $this->detail($viewer)['can'];
        self::assertFalse($can['record_money']);
        self::assertFalse($can['record_charge'], 'No right.');
        self::assertFalse($can['record_discount'], 'No right.');
        foreach (['record_payment', 'record_refund', 'record_correction'] as $dropped) {
            self::assertArrayNotHasKey($dropped, $can);
        }
    }

    /**
     * `can.answer_request` holds only while the registration is registered, as the answer act refuses any other
     * status; the grant alone is not enough.
     *
     * @dataProvider statuses
     */
    public function testAnswerRequestIsOfferedOnlyOnARegisteredRegistration(string $status): void
    {
        $this->addRegistration('00A0', $status);
        $partner = $this->plainUser();
        $this->grant($partner, [], [['column' => 'code', 'write' => false]], [Requests::ANSWER]);
        $registered = $status === 'registered';

        self::assertSame($registered, $this->detail($partner)['can']['answer_request']);
        self::assertSame($registered, $this->detail($this->admin->userId)['can']['answer_request']);
    }

    /** D14: `registration.confirmed` is always there, for a holder without the money-view too. */
    public function testTheDetailAlwaysCarriesWhetherTheRegistrationIsConfirmed(): void
    {
        $this->addRegistration('00A0');
        $this->addRegistration('00A2');
        $this->addRegistration('00A5', 'withdrawn');
        $this->addDecision('00A0', 'confirmation', 'confirmed');
        $plain = $this->plainUser();
        $this->grant($plain, [], [['column' => 'code', 'write' => false]], []);

        foreach ([$plain, $this->admin->userId] as $user) {
            self::assertTrue($this->detail($user)['registration']['confirmed']);
            self::assertFalse($this->detail($user, '00A2')['registration']['confirmed']);
            self::assertFalse($this->detail($user, '00A5')['registration']['confirmed']);
        }
    }

    /**
     * The owner's ruling 2026-11-21: `can.unconfirm` holds for a holder of `kalaka_confirm_manually` on a
     * confirmed registration, whatever its payments; never on one that is not confirmed, never without the action.
     */
    public function testCanUnconfirmHoldsOnAConfirmedRegistrationForAHolderOfTheAction(): void
    {
        $this->addRegistration('00A0');
        $this->addRegistration('00A2');
        $this->addDecision('00A0', 'confirmation', 'confirmed');
        $confirmer = $this->plainUser();
        $this->grant($confirmer, [], [['column' => 'code', 'write' => false]], ['kalaka_confirm_manually']);
        $viewer = $this->plainUser();
        $this->grant($viewer, [], [['column' => 'code', 'write' => false]], ['kalaka_view_money']);

        self::assertTrue($this->detail($confirmer)['can']['unconfirm']);
        self::assertTrue($this->detail($this->admin->userId)['can']['unconfirm']);
        self::assertFalse($this->detail($viewer)['can']['unconfirm'], 'No action.');
        self::assertFalse($this->detail($confirmer, '00A2')['can']['unconfirm'], 'Not confirmed.');
        self::assertArrayNotHasKey('unconfirm_warning', $this->detail($confirmer, '00A2')['registration']);
        self::assertArrayNotHasKey('unconfirm_warning', $this->detail($viewer)['registration']);
    }

    /**
     * Q4: a holder with money-view gets the reason-specific warning (T5a payments, T5b nothing due); a holder
     * without it gets the generic one (T5c), which names neither. Null when it would not confirm again.
     */
    public function testTheUnconfirmWarningDependsOnTheHolder(): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'kalaka_fixed_prices', [
            'event_id' => $this->event,
            'amount' => '10000',
            'currency' => 'HUF',
            'version' => 1,
        ]);
        $this->addRegistration('00A0');
        $this->addDecision('00A0', 'confirmation', 'confirmed');
        $blind = $this->plainUser();
        $this->grant($blind, [], [['column' => 'code', 'write' => false]], ['kalaka_confirm_manually']);
        $seeing = $this->plainUser();
        $this->grant(
            $seeing,
            [],
            [['column' => 'code', 'write' => false]],
            ['kalaka_confirm_manually', 'kalaka_view_money']
        );
        $warning = fn (int $user): ?string => $this->detail($user)['registration']['unconfirm_warning'];

        self::assertNull($warning($blind), 'Confirmed, nothing paid: it would stay unconfirmed.');
        self::assertNull($warning($seeing));

        $this->addLine('manual_payment', '-10000', 'manual:p1');
        ObjectCache::clear();
        $generic = 'A jelentkezés a visszavonás után azonnal újra visszaigazolt lesz.';
        self::assertSame($generic, $warning($blind), 'T5c: no reason, no money.');
        self::assertSame(
            'A befizetések már elérik a fizetendő összeget, ezért a jelentkezés a visszavonás után azonnal újra '
            . 'visszaigazolt lesz.',
            $warning($seeing),
            'T5a.'
        );

        $wpdb->delete($wpdb->prefix . 'kalaka_ledger_lines', ['event_id' => $this->event, 'code' => '00A0']);
        $wpdb->delete($wpdb->prefix . 'kalaka_fixed_prices', ['event_id' => $this->event]);
        ObjectCache::clear();
        self::assertSame($generic, $warning($blind));
        self::assertSame(
            'Nincs fizetendő összeg, ezért a jelentkezés a visszavonás után azonnal újra visszaigazolt lesz.',
            $warning($seeing),
            'T5b.'
        );
    }

    /** The warning is T5a, T5b or T5c only: the confirmed mail's sentence (T6) is not part of it (owner 2026-11-27). */
    public function testTheWarningSaysNothingOfTheMailEvenWhileTheConfirmedMailIsOn(): void
    {
        $this->addRegistration('00A0');
        $this->addDecision('00A0', 'confirmation', 'confirmed');
        $blind = $this->plainUser();
        $this->grant($blind, [], [['column' => 'code', 'write' => false]], ['kalaka_confirm_manually']);
        $seeing = $this->plainUser();
        $this->grant(
            $seeing,
            [],
            [['column' => 'code', 'write' => false]],
            ['kalaka_confirm_manually', 'kalaka_view_money']
        );
        $warning = fn (int $user): ?string => $this->detail($user)['registration']['unconfirm_warning'];
        $note = 'levél';
        $generic = 'A jelentkezés a visszavonás után azonnal újra visszaigazolt lesz.';
        $nothingDue = 'Nincs fizetendő összeg, ezért a jelentkezés a visszavonás után azonnal újra '
            . 'visszaigazolt lesz.';
        // No price: nothing is due, so the unconfirm would confirm again.
        self::assertSame($generic, $warning($blind));
        self::assertSame($nothingDue, $warning($seeing));

        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'kalaka_action_mails', [
            'event_id' => $this->event,
            'mail' => 'confirmed',
            'version' => 1,
            'enabled' => 1,
            'body_id' => null,
        ]);
        ObjectCache::clear();

        // The mail is on, and the warning still says nothing of it.
        self::assertSame($generic, $warning($blind), 'T5c only.');
        self::assertSame($nothingDue, $warning($seeing), 'T5b only.');
        self::assertStringNotContainsString($note, (string) $warning($blind));
        self::assertStringNotContainsString($note, (string) $warning($seeing));

        $wpdb->update(
            $wpdb->prefix . 'kalaka_action_mails',
            ['enabled' => 0],
            ['event_id' => $this->event, 'mail' => 'confirmed']
        );
        ObjectCache::clear();
        self::assertSame($generic, $warning($blind), 'Off again: unchanged.');
    }

    public function testTheReconfirmsKeyTellsTheReasonToAMoneyViewHolderAndOnlyYesToOthers(): void
    {
        $this->addRegistration('00A0');
        $this->addDecision('00A0', 'confirmation', 'confirmed');
        $blind = $this->plainUser();
        $this->grant($blind, [], [['column' => 'code', 'write' => false]], ['kalaka_confirm_manually']);
        $seeing = $this->plainUser();
        $this->grant(
            $seeing,
            [],
            [['column' => 'code', 'write' => false]],
            ['kalaka_confirm_manually', 'kalaka_view_money']
        );

        self::assertSame('yes', $this->detail($blind)['registration']['unconfirm_reconfirms']);
        self::assertSame('nothing_due', $this->detail($seeing)['registration']['unconfirm_reconfirms']);
    }

    /** A failing fee component leaves `can.unconfirm` and gives no warning, for either kind of holder (review C3). */
    public function testAFailingFeeComponentGivesNoWarningAndNoReasonForEitherHolder(): void
    {
        $this->addRegistration('00A0');
        $this->addDecision('00A0', 'confirmation', 'confirmed');
        $blind = $this->plainUser();
        $this->grant($blind, [], [['column' => 'code', 'write' => false]], ['kalaka_confirm_manually']);
        $seeing = $this->plainUser();
        $this->grant(
            $seeing,
            [],
            [['column' => 'code', 'write' => false]],
            ['kalaka_confirm_manually', 'kalaka_view_money']
        );
        $broken = new class implements FeeComponent {
            public function id(): string
            {
                return 'broken';
            }

            public function stage(): FeeStage
            {
                return FeeStage::Support;
            }

            public function targets(FeeComponentInput $input): FeeTargets
            {
                throw new \RuntimeException('secret component message');
            }
        };
        $confirmations = new ManualConfirmations(
            rule: new ConfirmationRule(new FeeComposition(new CoreFeeComponents([$broken])))
        );

        foreach ([$blind, $seeing] as $user) {
            $read = (new RegistrationDetail(manualConfirmations: $confirmations))
                ->of(new UserActor($user), $this->event, '00A0');

            self::assertTrue($read['can']['unconfirm']);
            self::assertNull($read['registration']['unconfirm_warning']);
            self::assertNull($read['registration']['unconfirm_reconfirms']);
            self::assertStringNotContainsString('secret component message', (string) wp_json_encode($read));
        }
    }

    /**
     * Slice B: the requests, with their latest answers, show only to a holder of the answer action of a grant whose
     * view holds the registration, or full access; `can.answer_request` says whether the action is carried.
     */
    public function testTheRequestsShowToTheHolderOfTheAnswerActionOnly(): void
    {
        global $wpdb;
        $this->addRegistration('00A0');
        $this->addValue('00A0', 'support', 'church');
        $this->addValue('00A0', 'aid_asked', '12000');
        $read = fn (int $user, ?AnswerPool $pool = null): array => (new RegistrationDetail(
            requests: new Requests([new TestRequestSource(pool: $pool ?? new AnswerPool($this->event))]),
        ))->of(new UserActor($user), $this->event, '00A0');

        $partner = $this->plainUser();
        $this->grant($partner, [], [['column' => 'code', 'write' => false]], [Requests::ANSWER]);
        $viewer = $this->plainUser();
        $this->grant($viewer, [], [['column' => 'code', 'write' => false]], ['kalaka_view_money']);

        $expected = [
            'currency' => 'HUF',
            'items' => [
                [
                    'id' => 'offer:church',
                    'kind' => 'offer',
                    'label' => 'Egyházi támogatás',
                    'state' => 'awaiting',
                    'amount' => null,
                    'answered_at' => null,
                    'seat_full' => false,
                ],
                [
                    'id' => 'aid',
                    'kind' => 'aid',
                    'label' => 'Támogatási kérelem',
                    'state' => 'awaiting',
                    'amount' => null,
                    'answered_at' => null,
                    'seat_full' => false,
                    'aid' => null,
                ],
            ],
        ];
        self::assertSame($expected, $read($this->admin->userId)['requests']);
        // The partner holds the answer action but no money view: the requests come without a figure — no
        // `amount` on an item and no `aid` context on the aid one ().
        $blind = $expected;
        $blind['items'][1]['amount'] = null;
        unset($blind['items'][1]['aid']);
        self::assertSame($blind, $read($partner)['requests']);
        self::assertTrue($read($partner)['can']['answer_request']);
        $without = $read($viewer);
        self::assertArrayNotHasKey('requests', $without);
        self::assertFalse($without['can']['answer_request']);

        // An answer: a refused offer with a full pool warns; an aid award shows its amount; the aid "no" is 0.
        foreach ([['offer:church', 0, null], ['aid', 1, '9000.00000000']] as [$request, $approved, $amount]) {
            $wpdb->insert($wpdb->prefix . 'kalaka_answers', [
                'event_id' => $this->event,
                'code' => '00A0',
                'request' => $request,
                'approved' => $approved,
                'amount' => $amount,
                'currency' => $amount === null ? null : 'HUF',
                'answered_at' => '2026-12-04 09:00:00.000000',
            ]);
            // The source's answer field is the truth: the act writes it beside the record.
            $this->addValue(
                '00A0',
                $request === 'aid' ? 'aid_award' : 'support_answer',
                $request === 'aid' ? '9000' : 'refused'
            );
        }
        // A refused offer in a pool with no free place: the warning before a "yes".
        $full = new AnswerPool($this->event, 0);
        $items = $read($partner, $full)['requests']['items'];
        self::assertSame(['refused', 'approved'], array_column($items, 'state'));
        // The award is a money figure: the partner reads the states and the seat warning, never the amount.
        self::assertSame([null, null], array_column($items, 'amount'));
        self::assertSame(['2026-12-04T09:00:00Z', '2026-12-04T09:00:00Z'], array_column($items, 'answered_at'));
        self::assertSame([true, false], array_column($items, 'seat_full'));
        self::assertSame([false, false], array_column($read($partner)['requests']['items'], 'seat_full'));
        // A holder with the money view reads the award.
        $money = $this->plainUser();
        $this->grant($money, [], [['column' => 'code', 'write' => false]], [Requests::ANSWER, 'kalaka_view_money']);
        self::assertSame(
            [null, '9000.00000000'],
            array_column($read($money, $full)['requests']['items'], 'amount')
        );
    }

    public function testTheAidItemCarriesTheFullPriceWhatTheyCanPayAndTheAidAskedNothingOnAnOffer(): void
    {
        $this->addRegistration('00A0');
        $this->addValue('00A0', 'support', 'church');
        $this->addValue('00A0', 'aid_asked', '12000');
        $read = fn (int $price): RegistrationDetail => new RegistrationDetail(
            requests: new Requests([
                new TestRequestSource(fullPrice: $price, pool: new AnswerPool($this->event)),
            ]),
        );

        $items = fn (int $price): array => $read($price)
            ->of(new UserActor($this->admin->userId), $this->event, '00A0')['requests']['items'];

        // Nothing stated: the full price and no amount they can pay, so nothing asked is known.
        $aid = $items(30000)[1]['aid'];
        self::assertSame(
            ['full_price' => '30000.00000000', 'can_pay' => null, 'asked' => null, 'max_award' => '30000.00000000'],
            $aid
        );
        self::assertArrayNotHasKey('aid', $items(30000)[0], 'an offer carries no aid context');

        $this->addValue('00A0', 'aid_can_pay', '18000');
        self::assertSame(
            [
                'full_price' => '30000.00000000',
                'can_pay' => '18000.00000000',
                'asked' => '12000.00000000',
                'max_award' => '30000.00000000',
            ],
            $items(30000)[1]['aid']
        );
        // A holder of the answer action without the money fields reads the request with no aid context at all
        // with the money view they read the same figures as full access does.
        $partner = $this->plainUser();
        $this->grant($partner, [], [['column' => 'code', 'write' => false]], [Requests::ANSWER]);
        $asPartner = $read(30000)->of(new UserActor($partner), $this->event, '00A0');
        self::assertFalse($asPartner['can']['view_money']);
        self::assertArrayNotHasKey('aid', $asPartner['requests']['items'][1]);
        $seeing = $this->plainUser();
        $this->grant($seeing, [], [['column' => 'code', 'write' => false]], [Requests::ANSWER, 'kalaka_view_money']);
        $asSeeing = $read(30000)->of(new UserActor($seeing), $this->event, '00A0');
        self::assertTrue($asSeeing['can']['view_money']);
        self::assertSame('12000.00000000', $asSeeing['requests']['items'][1]['aid']['asked']);
        // They can pay more than the price: nothing asked, never below zero.
        self::assertSame(
            [
                'full_price' => '10000.00000000',
                'can_pay' => '18000.00000000',
                'asked' => '0.00000000',
                'max_award' => '10000.00000000',
            ],
            $items(10000)[1]['aid']
        );
    }
}
