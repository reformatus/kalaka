<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Registrations;

use Kalaka\Admin\Cli\RegistrationCommand;
use Kalaka\Assignments\Api\AssignmentSets;
use Kalaka\Events\Api\Event;
use Kalaka\Forms\Api\ValidatedAnswers;
use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\PermissionDenied;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Foundation\Api\Error\VersionConflict;
use Kalaka\Foundation\Api\Module\ActionCatalogue;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Foundation\Decision\DecisionRules;
use Kalaka\Foundation\Evaluation\Evaluation;
use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Ledger\Api\Ledger;
use Kalaka\Ledger\RecordPayment;
use Kalaka\Pricing\Api\MoneySettings;
use Kalaka\Pricing\Api\ProposedMoneySettings;
use Kalaka\Pricing\Api\Retention;
use Kalaka\Pricing\SetRetention;
use Kalaka\Pricing\Api\ConfirmationRule;
use Kalaka\Pricing\Api\FeeComponent;
use Kalaka\Pricing\Api\FeeComponentInput;
use Kalaka\Pricing\Api\FeeComposition;
use Kalaka\Pricing\Api\FeeStage;
use Kalaka\Pricing\Api\FeeTargets;
use Kalaka\Pricing\Api\Materializer;
use Kalaka\Pricing\CoreFeeComponents;
use Kalaka\Pricing\SetFixedPrice;
use Kalaka\Registrations\Api\RegisteredBy;
use Kalaka\Registrations\Api\RegistrationStatus;
use Kalaka\Registrations\Api\SeatClaim;
use Kalaka\Registrations\Api\SeatClaimSource;
use Kalaka\Registrations\Api\SeatPool;
use Kalaka\Registrations\Api\StatusAct;
use Kalaka\Registrations\Api\StatusActions;
use Kalaka\Registrations\Api\StatusActRefused;
use Kalaka\Registrations\Api\StatusActs;
use Kalaka\Registrations\Status\AdmitRegistration;
use Kalaka\Registrations\Status\RemoveRegistration;
use Kalaka\Registrations\Status\RestoreRegistration;
use Kalaka\Registrations\Status\StatusChange;
use Kalaka\Registrations\Status\WithdrawRegistration;
use Kalaka\Tests\Integration\Admin\FieldEdit\StaffEditTestCase;
use Kalaka\Tests\Integration\Admin\FieldEdit\TierClaimSource;

/**
 * The status acts (S23): admit from the waitlist, withdraw, remove and restore. Each writes only its facts; the
 * seats go through `Seats::retake()` (held or over, never refused) and `releaseAll()`; the evaluation follows.
 */
final class StatusActsTest extends StaffEditTestCase
{
    private ActionCatalogue $catalogue;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WordPress names this hook
    public function set_up(): void
    {
        parent::set_up();

        $this->catalogue = ActionCatalogue::current();
        ActionCatalogue::record(new ActionCatalogue([
            'kalaka_withdraw_registration',
            'kalaka_remove_registration',
            'kalaka_admit_registration',
            'kalaka_restore_registration',
            'kalaka_view_money',
            'kalaka_record_money',
        ]));
        $this->runner = $this->moneyRunner();
        $this->exec(new SetFixedPrice(), ['event_id' => $this->event, 'amount' => '8000', 'currency' => 'HUF']);
        $this->addRegistration($this->event, '00A0');
        $this->addRegistration($this->event, '00A2');
        $this->addRegistration($this->event, '00A5', 'waitlisted');
        $this->mailOn('registered');
        $this->mailOn('confirmed');
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WordPress names this hook
    public function tear_down(): void
    {
        ActionCatalogue::record($this->catalogue);

        parent::tear_down();
    }

    /** @param list<FeeComponent> $components */
    private function moneyRunner(array $components = []): Runner
    {
        $money = new Materializer(new CoreFeeComponents($components));
        $rules = new DecisionRules();
        $rules->add(new ConfirmationRule($money->composition()), true);

        return new Runner($this->clockAt('2026-12-01 10:00:00'), true, new Evaluation($rules, money: $money));
    }

    private function mailOn(string $mail): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'kalaka_action_mails', [
            'event_id' => $this->event,
            'mail' => $mail,
            'version' => 1,
            'enabled' => 1,
            'layout' => 1,
        ]);
    }

    /** @return list<string> the mails queued for the registration, oldest first */
    private function deliveries(string $code): array
    {
        global $wpdb;

        return array_map('strval', $wpdb->get_col($wpdb->prepare(
            'SELECT mail FROM %i WHERE event_id = %d AND code = %s ORDER BY id',
            $wpdb->prefix . 'kalaka_deliveries',
            $this->event,
            $code
        )));
    }

    /** @return list<string> the confirmation states of the registration, oldest first */
    private function confirmations(string $code): array
    {
        global $wpdb;

        return array_map('strval', $wpdb->get_col($wpdb->prepare(
            "SELECT state FROM %i WHERE event_id = %d AND code = %s AND kind = 'confirmation' ORDER BY id",
            $wpdb->prefix . 'kalaka_decisions',
            $this->event,
            $code
        )));
    }

    private function pay(string $code, string $amount = '8000'): void
    {
        $this->exec(new RecordPayment(), [
            'event_id' => $this->event,
            'code' => $code,
            'amount' => $amount,
            'currency' => 'HUF',
            'method' => 'bank_transfer',
            'value_date' => '2026-11-29',
            'idempotency_key' => 'k-' . strtolower($code) . '-' . $amount,
        ]);
    }

    private function capacity(?int $capacity): void
    {
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'kalaka_events', ['capacity' => $capacity], ['id' => $this->event]);
    }

    private function isFull(): bool
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT is_full FROM %i WHERE id = %d',
            $wpdb->prefix . 'kalaka_events',
            $this->event
        )) === 1;
    }

    /** @return array<string, ?string> */
    private function row(string $code): array
    {
        $row = $this->rowOf('kalaka_registrations', $this->event, $code);
        self::assertNotNull($row);

        return $row;
    }

    private function rowOf(string $table, int $event, string $code): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM %i WHERE event_id = %d AND code = %s',
            $wpdb->prefix . $table,
            $event,
            $code
        ), ARRAY_A);

        return is_array($row) ? $row : null;
    }

    /** @param list<SeatClaimSource>|null $sources */
    private function useCase(StatusAct $act, ?array $sources = null): StatusChange
    {
        $sources ??= [new TierClaimSource(['a' => $this->poolA, 'b' => $this->poolB])];

        return match ($act) {
            StatusAct::Admit => new AdmitRegistration($sources),
            StatusAct::Withdraw => new WithdrawRegistration($sources),
            StatusAct::Remove => new RemoveRegistration($sources),
            StatusAct::Restore => new RestoreRegistration($sources),
        };
    }

    /**
     * @param array<string, mixed> $more
     * @return array{changed: bool, status: string, over_capacity: bool, seats: list<array<string, mixed>>}
     */
    private function act(StatusAct $act, string $code, array $more = [], ?UserActor $actor = null): array
    {
        $result = $this->exec(
            $this->useCase($act),
            ['event_id' => $this->event, 'code' => $code] + $more,
            $actor
        );
        self::assertIsArray($result);

        /** @var array{changed: bool, status: string, over_capacity: bool, seats: list<array<string, mixed>>} $result */
        return $result;
    }

    /** @return array<string, string> pool → outcome */
    private function outcomes(array $result): array
    {
        $out = [];
        foreach ($result['seats'] as $seat) {
            $out[(string) $seat['pool']] = (string) $seat['outcome'];
        }

        return $out;
    }

    // --- leaving ---

    public function testWithdrawingFreesEverySeatAndLeavesTheStampsAndSendsNoMail(): void
    {
        $this->addValue($this->event, '00A0', 'tier', 'a');
        $this->seat('00A0', $this->poolA);
        $before = $this->row('00A0');

        $result = $this->act(StatusAct::Withdraw, '00A0', ['reason' => 'Telefonon lemondta']);

        self::assertTrue($result['changed']);
        self::assertSame('withdrawn', $result['status']);
        $after = $this->row('00A0');
        self::assertSame('withdrawn', $after['status']);
        self::assertSame((int) $before['version'] + 1, (int) $after['version']);
        self::assertSame($before['registered_at'], $after['registered_at']);
        self::assertSame($before['registered_by'], $after['registered_by']);
        self::assertSame(0, $this->seats->held($this->poolA));
        self::assertFalse($this->seats->holds($this->poolA, $this->key('00A0')));
        self::assertSame([], $this->deliveries('00A0'));
        self::assertSame('Telefonon lemondta', $this->lastEntry()['reason']);
        self::assertSame('kalaka/withdraw-registration', $this->lastEntry()['action']);
    }

    public function testALeavingNeverAdmitsAnyoneAndKeepsTheFullLatch(): void
    {
        $this->capacity(2);
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'kalaka_events', ['is_full' => 1], ['id' => $this->event]);

        $this->act(StatusAct::Withdraw, '00A0');

        self::assertSame('waitlisted', $this->row('00A5')['status']);
        self::assertTrue($this->isFull());
    }

    public function testRemovingNeedsAReasonAndWorksFromTheWaitlist(): void
    {
        try {
            $this->act(StatusAct::Remove, '00A5');
            self::fail('A removal needs a reason.');
        } catch (InvalidInput) {
            self::assertSame('waitlisted', $this->row('00A5')['status']);
        }
        try {
            $this->act(StatusAct::Remove, '00A5', ['reason' => '   ']);
            self::fail('A blank reason is no reason.');
        } catch (InvalidInput) {
            self::assertSame('waitlisted', $this->row('00A5')['status']);
        }

        $result = $this->act(StatusAct::Remove, '00A5', ['reason' => 'Dupla jelentkezés']);

        self::assertSame('removed', $result['status']);
        self::assertSame('removed', $this->row('00A5')['status']);
        self::assertSame('Dupla jelentkezés', $this->lastEntry()['reason']);
    }

    public function testARepeatChangesNothingAndAWrongStatusIsRefused(): void
    {
        $this->act(StatusAct::Withdraw, '00A0');
        $entries = count($this->entries());
        $version = $this->row('00A0')['version'];

        $repeat = $this->act(StatusAct::Withdraw, '00A0');

        self::assertFalse($repeat['changed']);
        self::assertSame('withdrawn', $repeat['status']);
        self::assertSame($version, $this->row('00A0')['version']);
        self::assertSame([], $this->entriesWithChanges($entries));

        $refused = [[StatusAct::Remove, '00A0'], [StatusAct::Admit, '00A0'], [StatusAct::Restore, '00A5']];
        foreach ($refused as [$act, $code]) {
            try {
                $this->act($act, $code, ['reason' => 'x']);
                self::fail('Refused: ' . $act->value . ' ' . $code);
            } catch (StatusActRefused $e) {
                self::assertInstanceOf(RuleViolation::class, $e);
                self::assertSame('kalaka_status_act_refused', $e->errorCode());
            }
        }
        self::assertSame(RegistrationStatus::Withdrawn->value, $this->row('00A0')['status']);
    }

    /** @return list<array<string, ?string>> */
    private function entriesWithChanges(int $since): array
    {
        return array_values(array_filter(
            array_slice($this->entries(), $since),
            static fn (array $entry): bool => $entry['changes'] !== null && $entry['changes'] !== '[]'
                && $entry['changes'] !== '{}'
        ));
    }

    public function testAnUnknownRegistrationIsNotFound(): void
    {
        $this->expectException(NotFound::class);

        $this->act(StatusAct::Withdraw, '00A8');
    }

    private function failingComponent(): FeeComponent
    {
        return new class () implements FeeComponent {
            public function id(): string
            {
                return 'boom';
            }

            public function stage(): FeeStage
            {
                return FeeStage::Extras;
            }

            public function targets(FeeComponentInput $input): FeeTargets
            {
                throw new \RuntimeException('A component failed.');
            }
        };
    }

    public function testLeavingSucceedsWhileAFeeComponentThrows(): void
    {
        $this->runner = $this->moneyRunner([$this->failingComponent()]);

        $result = $this->act(StatusAct::Withdraw, '00A0');

        self::assertSame('withdrawn', $result['status']);
    }

    // --- coming back ---

    public function testAdmittingTakesTheSeatsStampsTheFactsAndQueuesTheRegisteredMail(): void
    {
        $this->addValue($this->event, '00A5', 'tier', 'a');

        $result = $this->act(StatusAct::Admit, '00A5', ['reason' => 'Lett hely']);

        self::assertTrue($result['changed']);
        self::assertFalse($result['over_capacity']);
        self::assertSame('held', $this->outcomes($result)['tier.a']);
        $row = $this->row('00A5');
        self::assertSame('registered', $row['status']);
        self::assertSame('admission', $row['registered_by']);
        self::assertSame('2026-12-01 09:00:00.000000', $row['registered_at']);
        self::assertTrue($this->seats->holds($this->poolA, $this->key('00A5')));
        self::assertSame(['registered'], $this->deliveries('00A5'));
        self::assertSame(
            RegisteredBy::Admission,
            (new \Kalaka\Registrations\Api\Registrations())->find($this->key('00A5'))?->registeredBy
        );
    }

    public function testAnAdmissionWhenEveryPoolIsFullGoesOverWithoutRefusingAndKeepsTheLatch(): void
    {
        $this->capacity(2);
        $this->addValue($this->event, '00A5', 'tier', 'b');
        $this->seat('00A2', $this->poolB);

        $result = $this->act(StatusAct::Admit, '00A5');

        $outcomes = $this->outcomes($result);
        self::assertSame('over', $outcomes['tier.b']);
        self::assertSame('over', $outcomes['event']);
        self::assertTrue($result['over_capacity']);
        self::assertSame('registered', $this->row('00A5')['status']);
        self::assertSame(1, $this->seats->over($this->poolB));
        self::assertFalse($this->isFull());
    }

    public function testBothOutcomesAreReportedExactlyAsTheSeatRuleGivesThem(): void
    {
        // A free tier gives held, a full one over: the act reports each, whatever the limits are.
        $this->addValue($this->event, '00A5', 'tier', 'a');
        $this->seat('00A0', $this->poolA);
        $this->seat('00A2', $this->poolA);
        $over = $this->outcomes($this->act(StatusAct::Admit, '00A5'));
        self::assertSame('over', $over['tier.a']);
        self::assertSame('held', $over['event']);

        $this->act(StatusAct::Withdraw, '00A5');
        $this->act(StatusAct::Withdraw, '00A0');
        $held = $this->outcomes($this->act(StatusAct::Restore, '00A5'));
        self::assertSame('held', $held['tier.a']);
    }

    public function testAClaimThatIsGoneIsNotTakenAndANewPoolRefusesTheActAsAConflict(): void
    {
        $scripted = static fn (array $calls): SeatClaimSource => new class ($calls) implements SeatClaimSource {
            private int $call = 0;

            /** @param list<list<SeatClaim>> $calls */
            public function __construct(private readonly array $calls)
            {
            }

            public function claims(Event $event, ValidatedAnswers $answers): array
            {
                return $this->calls[min($this->call++, count($this->calls) - 1)];
            }

            public function pools(Event $event): array
            {
                return [];
            }
        };
        $claim = static fn (SeatPool $pool): SeatClaim => new SeatClaim('tier', $pool);

        // Claimed at the plain read, gone under the lock: no seat.
        $this->exec(
            $this->useCase(StatusAct::Admit, [$scripted([[$claim($this->poolA)], []])]),
            ['event_id' => $this->event, 'code' => '00A5']
        );
        self::assertSame('registered', $this->row('00A5')['status']);
        self::assertSame(0, $this->seats->held($this->poolA));

        // Not claimed at the plain read, a quota pool under the lock: refused, nothing written.
        $this->addRegistration($this->event, '00K1', 'waitlisted');
        try {
            $this->exec(
                $this->useCase(StatusAct::Admit, [$scripted([[], [$claim($this->poolB)]])]),
                ['event_id' => $this->event, 'code' => '00K1']
            );
            self::fail('A pool that was not locked refuses the act.');
        } catch (VersionConflict) {
            self::assertSame('waitlisted', $this->row('00K1')['status']);
            self::assertSame(0, $this->seats->held($this->poolB));
        }
    }

    public function testARestoreIsRegisteredAgainWithItsOwnStampAndNoRegisteredMail(): void
    {
        $this->addValue($this->event, '00A0', 'tier', 'a');
        $this->seat('00A0', $this->poolA);
        $this->act(StatusAct::Remove, '00A0', ['reason' => 'Tévedés']);
        self::assertSame(0, $this->seats->held($this->poolA));

        $result = $this->act(StatusAct::Restore, '00A0');

        self::assertSame('registered', $result['status']);
        $row = $this->row('00A0');
        self::assertSame('registered', $row['status']);
        self::assertSame('restore', $row['registered_by']);
        self::assertSame('2026-12-01 09:00:00.000000', $row['registered_at']);
        self::assertSame(1, $this->seats->held($this->poolA));
        self::assertNotContains('registered', $this->deliveries('00A0'));
    }

    public function testAWaitlistedRegistrationThatLeftIsRestoredToRegistered(): void
    {
        $this->act(StatusAct::Withdraw, '00A5');

        $this->act(StatusAct::Restore, '00A5');

        self::assertSame('registered', $this->row('00A5')['status']);
        self::assertSame('restore', $this->row('00A5')['registered_by']);
    }

    public function testRestoringAPaidRegistrationWritesANewConfirmationAndItsMail(): void
    {
        $this->pay('00A0');
        self::assertSame(['confirmed'], $this->confirmations('00A0'));

        $this->act(StatusAct::Withdraw, '00A0');
        self::assertSame(['confirmed', 'unconfirmed'], $this->confirmations('00A0'));

        $this->act(StatusAct::Restore, '00A0');

        self::assertSame(['confirmed', 'unconfirmed', 'confirmed'], $this->confirmations('00A0'));
        self::assertSame(['confirmed', 'confirmed'], $this->deliveries('00A0'));
    }

    public function testARestoreWithoutEnoughPaidIsNotConfirmed(): void
    {
        $this->pay('00A0', '3000');
        $this->act(StatusAct::Withdraw, '00A0');

        $this->act(StatusAct::Restore, '00A0');

        self::assertNotContains('confirmed', $this->confirmations('00A0'));
        self::assertSame([], $this->deliveries('00A0'));
    }

    public function testAdmittingAPaidWaitlistedRegistrationConfirmsItAtOnceWithBothMails(): void
    {
        $this->exec(new SetFixedPrice(), ['event_id' => $this->event, 'amount' => '0', 'currency' => 'HUF']);

        $this->act(StatusAct::Admit, '00A5');

        self::assertSame(['registered', 'confirmed'], $this->deliveries('00A5'));
    }

    // --- preview ---

    public function testThePreviewWritesNothingAndAgreesWithTheAct(): void
    {
        $this->addValue($this->event, '00A5', 'tier', 'b');
        $this->seat('00A2', $this->poolB);
        $this->capacity(2);
        $entries = count($this->entries());

        $preview = $this->act(StatusAct::Admit, '00A5', ['preview' => true]);

        self::assertSame('waitlisted', $this->row('00A5')['status']);
        self::assertSame(1, $this->seats->held($this->poolB));
        self::assertSame([], $this->deliveries('00A5'));
        self::assertSame([], $this->entriesWithChanges($entries));
        $result = $this->act(StatusAct::Admit, '00A5');
        $columns = static fn (array $seats): array => array_map(
            static fn (array $seat): array => [$seat['pool'], $seat['label'], $seat['outcome']],
            $seats
        );
        self::assertSame($columns($preview['seats']), $columns($result['seats']));
        self::assertSame($preview['over_capacity'], $result['over_capacity']);
        self::assertTrue($result['over_capacity']);
    }

    public function testThePreviewRefusesWhatTheActRefuses(): void
    {
        $this->expectException(StatusActRefused::class);

        $this->act(StatusAct::Restore, '00A5', ['preview' => true]);
    }

    public function testThePreviewGivesEachSeatItsLabelItsFiguresAndTheWarning(): void
    {
        $this->capacity(2);
        $this->addValue($this->event, '00A5', 'tier', 'b');
        $this->seat('00A2', $this->poolB);

        $preview = $this->act(StatusAct::Admit, '00A5', ['preview' => true]);

        $bySeat = [];
        foreach ($preview['seats'] as $seat) {
            $bySeat[(string) $seat['pool']] = $seat;
        }
        // The tier is full (1 of 1 taken by another): over, with its own label and figures.
        self::assertSame(
            ['pool' => 'tier.b', 'label' => 'Teszt tier.b', 'field' => 'tier', 'outcome' => 'over', 'taken' => 1,
                'taken_after' => 2, 'limit' => 1],
            $bySeat['tier.b']
        );
        // The event's places: the event's own name, the registered registrations, the capacity.
        self::assertSame(
            ['pool' => 'event', 'label' => 'Tábor', 'field' => null, 'outcome' => 'over', 'taken' => 2,
                'taken_after' => 3, 'limit' => 2],
            $bySeat['event']
        );
        self::assertSame([AssignmentSets::FULL_WARNING], $preview['warnings']);
        $real = $this->act(StatusAct::Admit, '00A5');
        self::assertSame([AssignmentSets::FULL_WARNING], $real['warnings']);
    }

    public function testThePreviewHasNoWarningAndAnUnlimitedPoolHasNoLimit(): void
    {
        $this->addValue($this->event, '00A5', 'tier', 'a');

        $preview = $this->act(StatusAct::Admit, '00A5', ['preview' => true]);

        self::assertSame([], $preview['warnings']);
        $event = array_values(array_filter(
            $preview['seats'],
            static fn (array $seat): bool => $seat['pool'] === 'event'
        ));
        self::assertNull($event[0]['limit']);
        self::assertArrayNotHasKey('money', $preview);
    }

    public function testThePreviewShowsEachPayersMoneyAsTheActWillLeaveIt(): void
    {
        $this->pay('00A0', '3000');

        $withdraw = $this->act(StatusAct::Withdraw, '00A0', ['preview' => true]);

        // Retention keeps what was paid: balance 0 after, as it is now.
        self::assertSame(
            [['payer' => 'participant', 'paid' => '3000.00000000', 'balance_now' => '5000.00000000',
                'balance_after' => '0.00000000']],
            $withdraw['money']
        );
        self::assertSame('registered', $this->row('00A0')['status']);
        $this->act(StatusAct::Withdraw, '00A0');
        self::assertSame('0.00000000', $this->money()[2]);

        $restore = $this->act(StatusAct::Restore, '00A0', ['preview' => true]);

        self::assertSame(
            [['payer' => 'participant', 'paid' => '3000.00000000', 'balance_now' => '0.00000000',
                'balance_after' => '5000.00000000']],
            $restore['money']
        );
        $this->act(StatusAct::Restore, '00A0');
        self::assertSame('5000.00000000', $this->money()[2]);
    }

    public function testThePreviewOfKeepNothingShowsTheOverpaymentAndASecondPayer(): void
    {
        $this->exec(new SetRetention(), ['event_id' => $this->event, 'retention' => 'nothing']);
        $this->pay('00A0');

        $remove = $this->act(StatusAct::Remove, '00A0', ['preview' => true]);

        self::assertSame('-8000.00000000', $remove['money'][0]['balance_after']);
        self::assertSame('nothing', $remove['retention']);
        self::assertFalse($remove['money_hidden']);
    }

    public function testTheMoneyOfThePreviewIsOnlyForAHolderOfMoneyView(): void
    {
        $this->pay('00A0');
        $plain = $this->plainUser();
        $this->saveGrant($this->event, $plain, ['actions' => ['kalaka_withdraw_registration']]);
        $moneyUser = $this->plainUser();
        $this->saveGrant(
            $this->event,
            $moneyUser,
            ['actions' => ['kalaka_withdraw_registration', 'kalaka_view_money']]
        );

        $without = $this->act(StatusAct::Withdraw, '00A0', ['preview' => true], new UserActor($plain));
        $with = $this->act(StatusAct::Withdraw, '00A0', ['preview' => true], new UserActor($moneyUser));

        self::assertNull($without['money']);
        self::assertTrue($without['money_hidden']);
        self::assertNull($without['retention']);
        self::assertSame('0.00000000', $with['money'][0]['balance_after']);
        self::assertFalse($with['money_hidden']);
        self::assertSame('everything_paid', $with['retention']);
    }

    public function testAMoneyViewerWhoseFeeCannotBePricedGetsNullMoneyThatIsNotHidden(): void
    {
        $this->runner = $this->moneyRunner([$this->failingComponent()]);
        // Leaving never prices the fee; coming back does, and the component cannot say.
        $this->act(StatusAct::Withdraw, '00A0');
        $restore = new RestoreRegistration(
            [],
            composition: new FeeComposition(new CoreFeeComponents([$this->failingComponent()]))
        );

        $preview = $this->exec($restore, ['event_id' => $this->event, 'code' => '00A0', 'preview' => true]);
        self::assertIsArray($preview);

        self::assertNull($preview['money']);
        self::assertFalse($preview['money_hidden']);
    }

    public function testAPreviewNeedsNoReasonAndIgnoresOneAndOnlyARealRemoveRequiresIt(): void
    {
        $this->act(StatusAct::Withdraw, '00A0');
        $cases = [
            [StatusAct::Admit, '00A5'],
            [StatusAct::Withdraw, '00A2'],
            [StatusAct::Remove, '00A2'],
            [StatusAct::Restore, '00A0'],
        ];
        foreach ($cases as [$act, $code]) {
            $without = $this->act($act, $code, ['preview' => true]);
            $with = $this->act($act, $code, ['preview' => true, 'reason' => 'Megírt indok']);
            self::assertTrue($without['changed'], $act->value);
            self::assertSame($without, $with, $act->value);
        }
        // The previews changed nothing; a real remove still needs a reason.
        self::assertSame('registered', $this->row('00A2')['status']);
        $this->expectException(InvalidInput::class);
        $this->act(StatusAct::Remove, '00A2');
    }

    public function testALeavingPreviewListsTheSeatsItFreesWithTheCountAfter(): void
    {
        $this->capacity(5);
        foreach (['00A0', '00A2'] as $code) {
            $this->addValue($this->event, $code, 'tier', 'a');
            $this->seat($code, $this->poolA);
        }

        $preview = $this->act(StatusAct::Withdraw, '00A0', ['preview' => true]);

        $bySeat = [];
        foreach ($preview['seats'] as $seat) {
            $bySeat[(string) $seat['pool']] = $seat;
        }
        // The others hold the same seats after the act as before it, less this registration's own.
        self::assertSame('released', $bySeat['tier.a']['outcome']);
        self::assertSame(1, $bySeat['tier.a']['taken']);
        self::assertSame(1, $bySeat['tier.a']['taken_after']);
        self::assertSame(1, $bySeat['event']['taken']);
        self::assertSame(1, $bySeat['event']['taken_after']);
        self::assertSame([], $preview['warnings']);
        // The real act then leaves exactly that count.
        $this->act(StatusAct::Withdraw, '00A0');
        self::assertSame(1, $this->seats->held($this->poolA));
        // A waitlisted registration holds nothing to free.
        self::assertSame([], $this->act(StatusAct::Remove, '00A5', ['preview' => true])['seats']);
    }

    public function testAPreviewWritesNoAuditEntryAndARealActAndARealRepeatDo(): void
    {
        $entries = count($this->entries());

        $this->act(StatusAct::Withdraw, '00A0', ['preview' => true]);
        $this->act(StatusAct::Admit, '00A5', ['preview' => true]);
        self::assertCount($entries, $this->entries());

        $this->act(StatusAct::Withdraw, '00A0');
        self::assertCount($entries + 1, $this->entries());
        // An unchanged real save stays audited.
        self::assertFalse($this->act(StatusAct::Withdraw, '00A0')['changed']);
        self::assertCount($entries + 2, $this->entries());
        // A preview that is refused is audited like any refusal.
        try {
            $this->act(StatusAct::Restore, '00A5', ['preview' => true]);
            self::fail('Refused.');
        } catch (StatusActRefused) {
            self::assertCount($entries + 3, $this->entries());
        }
    }

    public function testThePreviewGivesTheCountAfterTheActAndTheEventsRetentionRule(): void
    {
        $this->capacity(5);
        $this->addValue($this->event, '00A5', 'tier', 'b');
        $this->seat('00A2', $this->poolB);

        $preview = $this->act(StatusAct::Admit, '00A5', ['preview' => true]);

        $bySeat = [];
        foreach ($preview['seats'] as $seat) {
            $bySeat[(string) $seat['pool']] = $seat;
        }
        self::assertSame(1, $bySeat['tier.b']['taken']);
        self::assertSame(2, $bySeat['tier.b']['taken_after']);
        self::assertSame(2, $bySeat['event']['taken']);
        self::assertSame(3, $bySeat['event']['taken_after']);
        self::assertArrayNotHasKey('retention', $preview);
        self::assertArrayNotHasKey('money_hidden', $preview);
        // Only an act that leaves carries the retention rule.
        $this->act(StatusAct::Withdraw, '00A0');
        self::assertArrayNotHasKey('retention', $this->act(StatusAct::Restore, '00A0', ['preview' => true]));
    }

    public function testTheAllowedActsFollowTheStatus(): void
    {
        $acts = new StatusActs();
        self::assertSame([StatusAct::Withdraw, StatusAct::Remove], $acts->allowedFor($this->key('00A0')));
        self::assertSame(
            [StatusAct::Admit, StatusAct::Withdraw, StatusAct::Remove],
            $acts->allowedFor($this->key('00A5'))
        );
        $this->act(StatusAct::Withdraw, '00A0');
        self::assertSame([StatusAct::Restore], $acts->allowedFor($this->key('00A0')));
        self::assertSame([], $acts->allowedFor($this->key('00A8')));
    }

    // --- permission ---

    public function testAGrantNeedsTheActsActionAndTheTargetInItsViewNow(): void
    {
        $user = $this->plainUser();
        $actor = new UserActor($user);
        $this->saveGrant($this->event, $user, ['actions' => ['kalaka_withdraw_registration']]);

        try {
            $this->act(StatusAct::Remove, '00A0', ['reason' => 'x'], $actor);
            self::fail('The grant does not carry remove.');
        } catch (PermissionDenied) {
            self::assertSame('registered', $this->row('00A0')['status']);
        }
        $this->act(StatusAct::Withdraw, '00A0', [], $actor);
        self::assertSame('withdrawn', $this->row('00A0')['status']);

        // A restore through a view that holds only `registered` registrations: the target is outside it now.
        $view = $this->viewWithColumns(
            [['column' => 'code', 'write' => false]],
            [['column' => 'status', 'in' => ['registered']]]
        );
        $other = $this->plainUser();
        $this->saveGrant($this->event, $other, ['view_id' => $view, 'actions' => ['kalaka_restore_registration']]);
        try {
            $this->act(StatusAct::Restore, '00A0', [], new UserActor($other));
            self::fail('The withdrawn target is outside the view.');
        } catch (PermissionDenied) {
            self::assertSame('withdrawn', $this->row('00A0')['status']);
        }
        $third = $this->plainUser();
        $this->saveGrant($this->event, $third, ['actions' => ['kalaka_restore_registration']]);
        $this->act(StatusAct::Restore, '00A0', [], new UserActor($third));
        self::assertSame('registered', $this->row('00A0')['status']);
    }

    // --- the command line ---

    public function testTheCommandRunsTheUseCaseAndPrintsTheOutcomeWithoutHoldingARule(): void
    {
        require_once __DIR__ . '/../Foundation/Seed/WpCliDouble.php';
        \WP_CLI::reset();
        \WP_CLI::$answersYes = true;
        $command = new RegistrationCommand($this->runner, $this->corrections(), new StatusActions($this->runner));
        $this->capacity(1);

        $command->admit([(string) $this->event, '00A5'], ['reason' => 'Lett hely']);

        self::assertSame('registered', $this->row('00A5')['status']);
        $kinds = array_map(static fn (array $call): string => $call[0], \WP_CLI::$calls);
        // The preview's warning, the question, then the act's own warning, its outcome and its seats.
        self::assertSame(['warning', 'confirm', 'warning', 'success', 'line'], array_slice($kinds, 0, 5));
        self::assertStringContainsString('registered', (string) \WP_CLI::$calls[3][1]);

        \WP_CLI::reset();
        try {
            // The use case refuses, not the command: a registration that is registered cannot be restored.
            $command->restore([(string) $this->event, '00A0'], []);
        } catch (\Throwable) {
            self::assertSame('error', \WP_CLI::$calls[array_key_last(\WP_CLI::$calls)][0]);
        }
        self::assertSame('registered', $this->row('00A0')['status']);
        \WP_CLI::reset();
        try {
            $command->remove([(string) $this->event, '00A2'], []);
            self::fail('A removal needs its reason.');
        } catch (\Throwable) {
            self::assertSame('registered', $this->row('00A2')['status']);
        }
        $command->withdraw([(string) $this->event, '00A2'], ['reason' => 'Telefon']);
        self::assertSame('withdrawn', $this->row('00A2')['status']);
    }

    public function testTheCommandPreviewsAsksFirstAndADeclineChangesNothingAndYesSkipsTheQuestion(): void
    {
        require_once __DIR__ . '/../Foundation/Seed/WpCliDouble.php';
        \WP_CLI::reset();
        $command = new RegistrationCommand($this->runner, $this->corrections(), new StatusActions($this->runner));
        $this->pay('00A0', '3000');
        $entries = count($this->entries());

        \WP_CLI::$decline = true;
        try {
            $command->withdraw([(string) $this->event, '00A0'], ['reason' => 'Telefon']);
            self::fail('A declined question stops the command.');
        } catch (\RuntimeException) {
            self::assertSame('registered', $this->row('00A0')['status']);
        }
        self::assertCount($entries, $this->entries(), 'The preview writes nothing and the act never ran.');
        $kinds = array_map(static fn (array $call): string => $call[0], \WP_CLI::$calls);
        self::assertSame(['line', 'confirm', 'error'], $kinds);
        self::assertStringContainsString('3000', (string) \WP_CLI::$calls[0][1]);

        // --yes skips the question although the operator would have declined.
        $command->withdraw([(string) $this->event, '00A0'], ['reason' => 'Telefon', 'yes' => true]);

        self::assertSame('withdrawn', $this->row('00A0')['status']);
        // A repeat is only reported, with no question.
        \WP_CLI::reset();
        $command->withdraw([(string) $this->event, '00A0'], []);
        self::assertSame(['success'], array_map(static fn (array $call): string => $call[0], \WP_CLI::$calls));
    }

    // --- retention ---

    /** @return array{string, string, string} the payer's charged, paid and balance, as the money table reads them */
    private function money(string $code = '00A0', string $payer = 'participant'): array
    {
        $summary = (new Ledger())->summaryOf($this->key($code))->of($payer, Currency::HUF);

        return [$summary->charged->amount(), $summary->paid->amount(), $summary->balance->amount()];
    }

    private function retentionLines(string $code = '00A0'): float
    {
        global $wpdb;

        return (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM %i WHERE event_id = %d AND code = %s AND source_key = 'retention'",
            $wpdb->prefix . 'kalaka_ledger_lines',
            $this->event,
            $code
        ));
    }

    public function testALeavingKeepsEverythingPaidByDefaultAndOwesNothingMore(): void
    {
        $this->pay('00A0');
        self::assertSame(['8000.00000000', '8000.00000000', '0.00000000'], $this->money());

        $this->act(StatusAct::Withdraw, '00A0');

        // The fee is reversed and the retention line keeps what was paid: nobody owes more, nothing is overpaid.
        self::assertSame(['8000.00000000', '8000.00000000', '0.00000000'], $this->money());
        self::assertSame(8000.0, $this->retentionLines());
    }

    public function testARemovedRegistrationKeepsWhatItPaidToo(): void
    {
        $this->pay('00A0', '3000');

        $this->act(StatusAct::Remove, '00A0', ['reason' => 'Tévedés']);

        self::assertSame(['3000.00000000', '3000.00000000', '0.00000000'], $this->money());
    }

    public function testAnUnpaidLeavingOwesNothingAndHasNoRetentionLine(): void
    {
        $this->act(StatusAct::Withdraw, '00A0');

        self::assertSame(['0.00000000', '0.00000000', '0.00000000'], $this->money());
        self::assertSame(0.0, $this->retentionLines());
    }

    public function testKeepNothingShowsEverythingPaidAsAnOverpaymentAndRefundsNothing(): void
    {
        $this->exec(new SetRetention(), ['event_id' => $this->event, 'retention' => 'nothing']);
        $this->pay('00A0');

        $this->act(StatusAct::Withdraw, '00A0');

        self::assertSame(['0.00000000', '8000.00000000', '-8000.00000000'], $this->money());
        self::assertSame(0.0, $this->retentionLines());
    }

    public function testEachPayerKeepsUpToItsOwnPayments(): void
    {
        $this->pay('00A0', '3000');
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'kalaka_ledger_lines', [
            'event_id' => $this->event,
            'code' => '00A0',
            'kind' => 'payment',
            'payer' => 'congregation',
            'amount' => '-2000',
            'currency' => 'HUF',
            'source_type' => 'system',
            'source_event_id' => null,
            'source_key' => 'system',
            'idempotency_key' => 'gyulekezet-1',
            'value_date' => '2026-11-29 23:00:00.000000',
            'paid_at' => null,
            'recorded_at' => '2026-11-30 08:00:00.000000',
            'method' => 'bank_transfer',
            'correction' => 0,
        ]);

        $this->act(StatusAct::Withdraw, '00A0');

        self::assertSame(['3000.00000000', '3000.00000000', '0.00000000'], $this->money());
        self::assertSame(['2000.00000000', '2000.00000000', '0.00000000'], $this->money('00A0', 'congregation'));
    }

    public function testARestoreTakesTheRetentionLineBackAndTheFullFeeReturns(): void
    {
        $this->pay('00A0', '3000');
        $this->act(StatusAct::Withdraw, '00A0');
        self::assertSame(3000.0, $this->retentionLines());

        $this->act(StatusAct::Restore, '00A0');

        self::assertSame(0.0, $this->retentionLines());
        self::assertSame(['8000.00000000', '3000.00000000', '5000.00000000'], $this->money());
    }

    public function testChangingTheRuleReEvaluatesTheRegistrationsThatLeft(): void
    {
        $this->pay('00A0');
        $this->act(StatusAct::Withdraw, '00A0');
        self::assertSame(['8000.00000000', '8000.00000000', '0.00000000'], $this->money());

        $settings = new MoneySettings($this->runner);
        self::assertSame(Retention::EverythingPaid, $settings->retention($this->event));
        $report = $settings->apply(
            $this->event,
            new ProposedMoneySettings(retention: Retention::Nothing),
            new CliActor(null)
        );

        self::assertSame(Retention::Nothing, $settings->retention($this->event));
        self::assertSame(['0.00000000', '8000.00000000', '-8000.00000000'], $this->money());
        self::assertNotSame([], $report->items);
    }

    public function testThePreviewOfANewRetentionRuleShowsTheLeaversAndItsCountIsWhatTheApplyTouches(): void
    {
        $this->pay('00A0');
        $this->act(StatusAct::Withdraw, '00A0');
        $settings = new MoneySettings($this->runner);
        $proposed = new ProposedMoneySettings(retention: Retention::Nothing);

        $rows = $settings->preview($this->event, $proposed);

        $byCode = [];
        foreach ($rows as $row) {
            $byCode[$row->registration->code] = $row;
        }
        // The leaver is in the preview, and its retention line goes: it owed 8000 now and would owe nothing.
        self::assertArrayHasKey('00A0', $byCode);
        self::assertSame('8000.00000000', $byCode['00A0']->before['participant']->amount());
        self::assertSame('0.00000000', ($byCode['00A0']->after['participant'] ?? Money::zero(Currency::HUF))->amount());
        self::assertTrue($byCode['00A0']->changes());
        // Without a proposed rule the leavers are left out, as the apply does not touch them.
        $codes = array_map(
            static fn ($row): string => $row->registration->code,
            $settings->preview($this->event, new ProposedMoneySettings())
        );
        self::assertNotContains('00A0', $codes);
        $report = $settings->apply($this->event, $proposed, new CliActor(null));
        self::assertCount(count($rows), $report->items);
    }

    public function testNoMoneyIsEverRefundedByALeaving(): void
    {
        $this->pay('00A0');

        $this->act(StatusAct::Withdraw, '00A0');

        global $wpdb;
        $kinds = array_map('strval', $wpdb->get_col($wpdb->prepare(
            'SELECT DISTINCT kind FROM %i WHERE event_id = %d AND code = %s',
            $wpdb->prefix . 'kalaka_ledger_lines',
            $this->event,
            '00A0'
        )));
        self::assertNotContains('refund', $kinds);
    }
}
