<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Pricing;

use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\PermissionDenied;
use Kalaka\Foundation\Api\Module\ActionCatalogue;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Foundation\Decision\DecisionRules;
use Kalaka\Foundation\Evaluation\Evaluation;
use Kalaka\Ledger\RecordPayment;
use Kalaka\Pricing\Api\ConfirmationRule;
use Kalaka\Pricing\Api\ManualConfirmations;
use Kalaka\Pricing\Api\Materializer;
use Kalaka\Pricing\CoreFeeComponents;
use Kalaka\Pricing\SetFixedPrice;
use Kalaka\Pricing\SetManualConfirmation;
use Kalaka\Pricing\Unconfirm;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Tests\Integration\Ledger\ManualEntryTestCase;

/**
 * The manual confirmation flag and staff's unconfirm (money.md, "Confirmation"; the owner's ruling 2026-11-19):
 * set and unconfirm with a required reason, audited, idempotent, carried by `kalaka_confirm_manually` inside
 * its grant's view or full access, and evaluated in the same run.
 */
final class ManualConfirmationTest extends ManualEntryTestCase
{
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WordPress names this hook
    public function set_up(): void
    {
        parent::set_up();

        ActionCatalogue::record(new ActionCatalogue([
            'kalaka_record_money',
            'kalaka_view_money',
            ManualConfirmations::CONFIRM_MANUALLY,
        ]));
        $this->exec(new SetFixedPrice(), ['event_id' => $this->event, 'amount' => '8000', 'currency' => 'HUF']);
    }

    protected function runnerAt(string $local): Runner
    {
        $money = new Materializer(new CoreFeeComponents([]));
        $rules = new DecisionRules();
        $rules->add(new ConfirmationRule($money->composition()), true, true);

        return new Runner($this->clockAt($local), true, new Evaluation($rules, money: $money));
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function input(array $overrides = []): array
    {
        return $overrides + ['event_id' => $this->event, 'code' => '00A0', 'reason' => 'Teszt indok'];
    }

    /** @return list<array<string, ?string>> */
    private function flagRows(string $table = 'kalaka_manual_confirmations'): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM %i WHERE event_id = %d',
            $wpdb->prefix . $table,
            $this->event
        ), ARRAY_A);
        self::assertIsArray($rows);

        /** @var list<array<string, ?string>> $rows */
        return $rows;
    }

    private function confirmationState(): ?string
    {
        global $wpdb;
        $state = $wpdb->get_var($wpdb->prepare(
            "SELECT state FROM %i WHERE event_id = %d AND code = '00A0' AND kind = 'confirmation' ORDER BY id DESC"
            . ' LIMIT 1',
            $wpdb->prefix . 'kalaka_decisions',
            $this->event
        ));

        return $state === null ? null : (string) $state;
    }

    /** @return array<string, ?string> the latest audit entry of the action */
    private function entryOf(string $action): array
    {
        $found = null;
        foreach ($this->entries() as $entry) {
            if ($entry['action'] === $action) {
                $found = $entry;
            }
        }
        self::assertNotNull($found);

        return $found;
    }

    public function testSettingConfirmsInTheSameRunAndIsAudited(): void
    {
        self::assertSame(['changed' => true], $this->exec(new SetManualConfirmation(), $this->input()));

        self::assertSame('confirmed', $this->confirmationState());
        $rows = $this->flagRows();
        self::assertCount(1, $rows);
        self::assertSame('2026-11-30 09:00:00.000000', $rows[0]['set_at']);
        self::assertSame((string) $this->admin->userId, $rows[0]['set_by']);
        self::assertSame('Teszt indok', $rows[0]['reason']);
        self::assertNull($rows[0]['revoked_at']);

        $entry = $this->entryOf('kalaka/set-manual-confirmation');
        self::assertSame('succeeded', $entry['outcome']);
        self::assertSame('Teszt indok', $entry['reason']);
        self::assertStringNotContainsString('Teszt indok', (string) $entry['changes']);
        self::assertContains('registration/' . $this->event . '/00A0', $this->subjectsOf($entry['id']));
    }

    public function testUnconfirmingBelowRevokesTheFlagRecordsWhoWhenWhyAndIsAudited(): void
    {
        $this->exec(new SetManualConfirmation(), $this->input());
        $this->runner = $this->runnerAt('2026-12-01 10:00');

        self::assertSame(
            ['changed' => true],
            $this->exec(new Unconfirm(), $this->input(['reason' => 'Teszt visszavonás']))
        );

        self::assertSame('unconfirmed', $this->confirmationState());
        self::assertSame('2026-12-01 09:00:00.000000', $this->flagRows()[0]['revoked_at']);
        $unconfirmed = $this->flagRows('kalaka_unconfirmations');
        self::assertCount(1, $unconfirmed);
        self::assertSame('2026-12-01 09:00:00.000000', $unconfirmed[0]['unconfirmed_at']);
        self::assertSame((string) $this->admin->userId, $unconfirmed[0]['unconfirmed_by']);
        self::assertSame('Teszt visszavonás', $unconfirmed[0]['reason']);
        $entry = $this->entryOf('kalaka/unconfirm');
        self::assertSame('Teszt visszavonás', $entry['reason']);
        self::assertStringNotContainsString('Teszt visszavonás', (string) $entry['changes']);
        self::assertNull((new ManualConfirmations())->of(new RegistrationKey($this->event, '00A0')));
    }

    public function testBothAreIdempotentAndASetFlagKeepsItsTimeAndReason(): void
    {
        self::assertSame(['changed' => false], $this->exec(new Unconfirm(), $this->input()), 'Not confirmed.');
        self::assertSame([], $this->flagRows());
        self::assertSame([], $this->flagRows('kalaka_unconfirmations'));

        $this->exec(new SetManualConfirmation(), $this->input());
        $this->runner = $this->runnerAt('2026-12-01 10:00');
        self::assertSame(
            ['changed' => false],
            $this->exec(new SetManualConfirmation(), $this->input(['reason' => 'Másik indok']))
        );

        $flag = (new ManualConfirmations())->of(new RegistrationKey($this->event, '00A0'));
        self::assertNotNull($flag);
        self::assertSame('Teszt indok', $flag->reason);
        self::assertSame('2026-11-30 09:00:00', $flag->setAt->format('Y-m-d H:i:s'));
        $entry = $this->entryOf('kalaka/set-manual-confirmation');
        self::assertSame('succeeded', $entry['outcome']);
        self::assertSame('[]', (string) $entry['changes']);
    }

    /** The owner's ruling 2026-11-21: with the payments at the threshold, each unconfirm is a new, audited act. */
    public function testAnUnconfirmThatConfirmsAgainAtOnceIsAuditedAndARetryWritesAgain(): void
    {
        $this->exec(new RecordPayment(), $this->paymentInput(['amount' => '8000']));
        self::assertSame('confirmed', $this->confirmationState());

        self::assertSame(['changed' => true], $this->exec(new Unconfirm(), $this->input(['reason' => 'Első'])));
        self::assertSame('confirmed', $this->confirmationState(), 'The payments confirm it again at once.');
        self::assertSame(['changed' => true], $this->exec(new Unconfirm(), $this->input(['reason' => 'Második'])));

        $entries = array_values(array_filter(
            $this->entries(),
            static fn (array $entry): bool => $entry['action'] === 'kalaka/unconfirm'
        ));
        self::assertCount(2, $entries);
        self::assertSame(['Első', 'Második'], array_column($entries, 'reason'));
        self::assertSame(['succeeded', 'succeeded'], array_column($entries, 'outcome'));
        self::assertSame('Második', $this->flagRows('kalaka_unconfirmations')[0]['reason']);
    }

    public function testASetAfterAnUnconfirmReplacesTheFlagAndConfirmsAgain(): void
    {
        $this->exec(new SetManualConfirmation(), $this->input());
        $this->runner = $this->runnerAt('2026-12-01 10:00');
        $this->exec(new Unconfirm(), $this->input());
        $this->runner = $this->runnerAt('2026-12-02 10:00');

        $this->exec(new SetManualConfirmation(), $this->input(['reason' => 'Új indok']));

        $rows = $this->flagRows();
        self::assertCount(1, $rows);
        self::assertSame('2026-12-02 09:00:00.000000', $rows[0]['set_at']);
        self::assertSame('Új indok', $rows[0]['reason']);
        self::assertNull($rows[0]['revoked_at']);
        self::assertSame('confirmed', $this->confirmationState());
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function badInput(): array
    {
        return [
            'no reason' => [['reason' => '']],
            'a blank reason' => [['reason' => '   ']],
            'a reason too long' => [['reason' => str_repeat('a', 501)]],
            'a malformed code' => [['code' => 'abc']],
        ];
    }

    /**
     * @dataProvider badInput
     * @param array<string, mixed> $override
     */
    public function testBadInputIsRefusedAndWritesNothing(array $override): void
    {
        try {
            $this->exec(new SetManualConfirmation(), $this->input($override));
            self::fail('It ran.');
        } catch (InvalidInput) {
            self::assertSame([], $this->flagRows());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function notRegistered(): iterable
    {
        yield 'waitlisted' => ['waitlisted'];
        yield 'withdrawn' => ['withdrawn'];
        yield 'removed' => ['removed'];
    }

    /**
     * The owner's topic 1 ruling, H3: confirmation applies only to a registered registration, so the flag
     * is set on that one only; any other status is refused and writes nothing.
     *
     * @dataProvider notRegistered
     */
    public function testTheFlagIsRefusedOnAnyStatusButRegistered(string $status): void
    {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'kalaka_registrations',
            ['status' => $status],
            ['event_id' => $this->event, 'code' => '00A0']
        );
        \Kalaka\Foundation\ObjectCache::clear();

        try {
            $this->exec(new SetManualConfirmation(), $this->input());
            self::fail('It ran.');
        } catch (InvalidInput) {
            self::assertSame([], $this->flagRows());
            self::assertNull($this->confirmationState());
        }
    }

    public function testTheFlagIsAcceptedOnARegisteredRegistration(): void
    {
        self::assertSame(['changed' => true], $this->exec(new SetManualConfirmation(), $this->input()));
        self::assertCount(1, $this->flagRows());
    }

    public function testAMissingReasonIsRefused(): void
    {
        $this->expectException(InvalidInput::class);
        $this->exec(new SetManualConfirmation(), ['event_id' => $this->event, 'code' => '00A0']);
    }

    public function testAnUnknownRegistrationIsNotFound(): void
    {
        $this->expectException(NotFound::class);
        $this->exec(new SetManualConfirmation(), $this->input(['code' => '00A2']));
    }

    public function testNoGrantAndAGrantOfAnotherActionAreRefused(): void
    {
        $user = $this->plainUser();
        try {
            $this->exec(new SetManualConfirmation(), $this->input(), new UserActor($user));
            self::fail('No grant, and it ran.');
        } catch (PermissionDenied) {
            self::assertSame([], $this->flagRows());
        }

        $this->grantOn($user, ['kalaka_record_money']);
        $this->expectException(PermissionDenied::class);
        $this->exec(new SetManualConfirmation(), $this->input(), new UserActor($user));
    }

    public function testAGrantWhoseViewExcludesTheRegistrationIsRefused(): void
    {
        $user = $this->plainUser();
        $this->grantOn($user, [ManualConfirmations::CONFIRM_MANUALLY], 'waitlisted');

        $this->expectException(PermissionDenied::class);
        $this->exec(new Unconfirm(), $this->input(), new UserActor($user));
    }

    public function testAGrantWithTheActionInsideItsViewSetsAndUnconfirms(): void
    {
        $user = $this->plainUser();
        $this->grantOn($user, [ManualConfirmations::CONFIRM_MANUALLY]);

        $this->exec(new SetManualConfirmation(), $this->input(), new UserActor($user));
        self::assertSame((string) $user, $this->flagRows()[0]['set_by']);
        $this->runner = $this->runnerAt('2026-12-01 10:00');
        $this->exec(new Unconfirm(), $this->input(), new UserActor($user));
        self::assertNotNull($this->flagRows()[0]['revoked_at']);
        self::assertSame((string) $user, $this->flagRows('kalaka_unconfirmations')[0]['unconfirmed_by']);
    }
}
