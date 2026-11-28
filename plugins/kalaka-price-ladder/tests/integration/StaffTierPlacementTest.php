<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\PriceLadder;

use Kalaka\Admin\Api\Access;
use Kalaka\Admin\Api\RegistrationDetail;
use Kalaka\Admin\FieldEdit\EditFieldValue;
use Kalaka\Admin\FieldEdit\FieldEditInput;
use Kalaka\Assignments\Api\AssignmentSets;
use Kalaka\Foundation\Api\Actor\Actor;
use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\Error\VersionConflict;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Foundation\Decision\DecisionRules;
use Kalaka\Foundation\Evaluation\Evaluation;
use Kalaka\Pricing\Api\ConfirmationRule;
use Kalaka\Pricing\Api\Confirmations;
use Kalaka\Pricing\Api\FeeComponent;
use Kalaka\Pricing\Api\FeeRefused;
use Kalaka\Pricing\Api\Materializer;
use Kalaka\Pricing\Api\MoneySettings;
use Kalaka\Pricing\CoreFeeComponents;
use Kalaka\PriceLadder\PriceLadderComponent;
use Kalaka\PriceLadder\TierSeatPool;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\RegistrationStatus;
use Kalaka\Registrations\Api\Registrations;
use Kalaka\Registrations\Api\SeatAct;
use Kalaka\Registrations\Api\Seats;
use Kalaka\Tests\Integration\Foundation\TestClock;

/**
 * B5-2, staff placing into a tier (T6): the same act as any field edit, on `price_tier`. The seat moves with the
 * answer, a full tier is taken over its limit after the approved warning, the base is re-priced and a confirmed
 * registration stays confirmed.
 */
final class StaffTierPlacementTest extends PriceLadderTestCase
{
    private Seats $seats;

    private Actor $staff;

    private int $staffUser;

    private Runner $production;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names it
    public function set_up(): void
    {
        parent::set_up();
        $this->seats = new Seats();
        $this->staffUser = $this->fullAccessUser();
        $this->staff = new UserActor($this->staffUser);
        $this->production = Runner::current();
        Runner::publish($this->runner);
        Access::forget();
        $this->applyLadder([
            self::tier('alap', 'Alap ár', '49500'),
            self::tier('teljes', 'Teljes ár', '69500'),
            self::tier('tamogatott', 'Támogatott ár', '20000') + ['limit' => 1],
        ]);
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names it
    public function tear_down(): void
    {
        // A queued confirmed mail schedules the sender; leave no event behind for other tests.
        wp_unschedule_hook(\Kalaka\Mail\Api\MailQueue::SEND_HOOK);
        Runner::publish($this->production);
        wp_set_current_user(0);
        Access::forget();

        parent::tear_down();
    }

    /** The runner with core's confirmation decision too, so an edit is followed by money, then confirmation. */
    protected function useComponents(FeeComponent ...$more): void
    {
        $components = new CoreFeeComponents($more);
        $money = new Materializer($components);
        $rules = new DecisionRules();
        $rules->add(new ConfirmationRule($money->composition()), true);
        $this->runner = new Runner(
            new TestClock($this->utc($this->now)),
            true,
            new Evaluation($rules, money: $money)
        );
        $this->settings = new MoneySettings($this->runner, $components);
    }

    private function pool(): TierSeatPool
    {
        return new TierSeatPool($this->event, 'tamogatott');
    }

    /** A registered registration that chose the tier, holding its seat the way a submit does. */
    private function choosing(string $code, string $tier, bool $seat = false): RegistrationKey
    {
        $key = $this->register($code, RegistrationStatus::Waitlisted);
        $this->writeValue($key, PriceLadderComponent::FIELD, $tier);
        $this->setStatus($key, RegistrationStatus::Registered, '2026-11-30 08:00:00');
        if ($seat) {
            $this->inRun(function (array $input, Context $context) use ($key): void {
                $this->seats->lock($this->pool(), $context);
                $this->lockRegistrations($context, $key);
                $this->seats->take($this->pool(), $key, SeatAct::Submit, $context);
            });
        }

        return $key;
    }

    private static function line(string $amount): string
    {
        return 'price_ladder participant ' . $amount . ' charge:price_ladder:participant:1';
    }

    /**
     * @param array<string, mixed> $more
     * @return array<string, mixed>
     */
    private function edit(RegistrationKey $key, ?string $tier, array $more = []): array
    {
        $input = [
            'event_id' => $key->eventId,
            'code' => $key->code,
            'field' => PriceLadderComponent::FIELD,
            'value' => $tier,
            'reason' => 'Átsorolás',
        ] + $more;
        $edit = (new FieldEditInput())->prepare($input, $this->staff);
        $result = $this->runner->run(new EditFieldValue($edit), $input, $this->staff);
        self::assertIsArray($result);

        return $result;
    }

    /** @return array<string, mixed> the tier's `edit` of the detail read */
    private function tierEdit(RegistrationKey $key): array
    {
        $read = (new RegistrationDetail())->of($this->staff, $key->eventId, $key->code);
        foreach ($read['fields'] as $field) {
            if ($field['key'] === PriceLadderComponent::FIELD) {
                self::assertTrue($field['writable']);

                return $field['edit'];
            }
        }
        self::fail('No price_tier in the read.');
    }

    /** @return array<string, bool> each tier's `full` flag */
    private function fullFlags(RegistrationKey $key): array
    {
        $flags = [];
        foreach ($this->tierEdit($key)['options'] as $option) {
            $flags[$option['value']] = $option['full'];
        }

        return $flags;
    }

    public function testPlacingIntoAFullTierTakesTheSeatOverTheLimitAndRePricesTheBase(): void
    {
        $holder = $this->choosing('00A0', 'tamogatott', seat: true);
        $anna = $this->choosing('00A2', 'alap');
        self::assertSame([self::line('49500')], $this->charges($anna));

        $preview = $this->edit($anna, 'tamogatott', ['preview' => true]);
        self::assertSame(
            ['changed' => true, 'over_limit' => ['price_tier'], 'warnings' => [AssignmentSets::FULL_WARNING]],
            $preview
        );
        self::assertSame(1, $this->seats->held($this->pool()), 'A preview moves no seat.');

        $result = $this->edit($anna, 'tamogatott', ['expected' => 'alap']);

        self::assertSame(['changed' => true, 'over_limit' => ['price_tier']], $result);
        self::assertSame('tamogatott', (new Registrations())->values($anna)[PriceLadderComponent::FIELD]);
        self::assertTrue($this->seats->holds($this->pool(), $anna));
        self::assertTrue($this->seats->holds($this->pool(), $holder));
        self::assertSame(1, $this->seats->over($this->pool()));
        self::assertSame('20000', $this->balance($anna), 'The base is the new tier\'s price.');
    }

    public function testMovingOutOfTheTierFreesItsSeat(): void
    {
        $this->choosing('00A0', 'tamogatott', seat: true);
        $anna = $this->choosing('00A2', 'alap');
        $this->edit($anna, 'tamogatott');
        self::assertSame(1, $this->seats->over($this->pool()));

        $result = $this->edit($anna, 'teljes', ['expected' => 'tamogatott']);

        self::assertSame(['changed' => true, 'over_limit' => []], $result);
        self::assertFalse($this->seats->holds($this->pool(), $anna));
        self::assertSame(0, $this->seats->over($this->pool()));
        self::assertSame(1, $this->seats->held($this->pool()));
    }

    public function testPlacingIntoATierWithRoomTakesItsSeatWithNoWarning(): void
    {
        $anna = $this->choosing('00A2', 'alap');

        $preview = $this->edit($anna, 'tamogatott', ['preview' => true]);
        $result = $this->edit($anna, 'tamogatott');

        self::assertSame(['changed' => true, 'over_limit' => [], 'warnings' => []], $preview);
        self::assertSame(['changed' => true, 'over_limit' => []], $result);
        self::assertTrue($this->seats->holds($this->pool(), $anna));
        self::assertSame(0, $this->seats->left($this->pool()));
    }

    public function testTheDetailReadsEachTiersFullFlagForThisRegistrationOnlyWhenItIsRegistered(): void
    {
        $holder = $this->choosing('00A0', 'tamogatott', seat: true);
        $anna = $this->choosing('00A2', 'alap');

        self::assertSame(['alap' => false, 'teljes' => false, 'tamogatott' => true], $this->fullFlags($anna));
        // The holder's own seat is not counted against it, and the tier it holds is not "full" for it.
        self::assertSame(['alap' => false, 'teljes' => false, 'tamogatott' => false], $this->fullFlags($holder));

        $waiting = $this->register('00A5', RegistrationStatus::Waitlisted);
        $this->writeValue($waiting, PriceLadderComponent::FIELD, 'alap');
        self::assertSame(['alap' => false, 'teljes' => false, 'tamogatott' => false], $this->fullFlags($waiting));
        $this->edit($waiting, 'tamogatott');
        self::assertFalse($this->seats->holds($this->pool(), $waiting), 'A waitlisted registration takes no seat.');
    }

    public function testAConfirmedRegistrationStaysConfirmedAndOwesTheNewTiersPrice(): void
    {
        $anna = $this->choosing('00A2', 'alap');
        $this->pay($anna, '49500');
        $confirmations = new Confirmations();
        self::assertTrue($confirmations->isConfirmed($anna));

        $this->edit($anna, 'teljes', ['expected' => 'alap']);

        self::assertTrue($confirmations->isConfirmed($anna));
        self::assertSame('20000', $this->balance($anna));
    }

    public function testACheaperTierOnAnUnconfirmedRegistrationThatHasPaidEnoughConfirmsItAndQueuesTheMail(): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'kalaka_action_mails', [
            'event_id' => $this->event,
            'mail' => 'confirmed',
            'version' => 1,
            'enabled' => 1,
        ]);
        $anna = $this->choosing('00A2', 'teljes');
        $this->pay($anna, '49500');
        $confirmations = new Confirmations();
        self::assertFalse($confirmations->isConfirmed($anna));
        self::assertSame(0, $this->deliveryCount());

        $this->edit($anna, 'alap', ['expected' => 'teljes']);

        self::assertTrue($confirmations->isConfirmed($anna));
        self::assertSame('0', $this->balance($anna));
        self::assertSame(1, $this->deliveryCount(), 'The „Jelentkezés véglegesítve” mail is queued.');
    }

    private function deliveryCount(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM %i WHERE mail = 'confirmed'",
            $wpdb->prefix . 'kalaka_deliveries'
        ));
    }

    public function testClearingTheTierIsRefusedByTheLaddersStaffTextAndNothingIsWritten(): void
    {
        $anna = $this->choosing('00A2', 'alap');

        try {
            $this->edit($anna, null);
            self::fail('A registration without a tier cannot be priced.');
        } catch (FeeRefused) {
            self::assertSame('alap', (new Registrations())->values($anna)[PriceLadderComponent::FIELD]);
        }
        self::assertSame([self::line('49500')], $this->charges($anna));
    }

    public function testAStaleExpectedTierIsAConflictAndNoSeatMoves(): void
    {
        $anna = $this->choosing('00A2', 'alap');

        try {
            $this->edit($anna, 'tamogatott', ['expected' => 'teljes']);
            self::fail('The screen showed another tier.');
        } catch (VersionConflict) {
            self::assertSame(0, $this->seats->held($this->pool()));
        }
    }

    public function testTheAbilityPlacesIntoTheTierWithPreviewAndSave(): void
    {
        $this->choosing('00A0', 'tamogatott', seat: true);
        $anna = $this->choosing('00A2', 'alap');
        wp_set_current_user($this->staffUser);
        $ability = wp_get_ability('kalaka/edit-field-value');
        self::assertInstanceOf(\WP_Ability::class, $ability);
        $input = [
            'event_id' => $anna->eventId,
            'code' => $anna->code,
            'field' => PriceLadderComponent::FIELD,
            'value' => 'tamogatott',
            'expected' => $this->tierEdit($anna)['expected'],
            'reason' => 'Átsorolás',
        ];

        self::assertSame(
            ['changed' => true, 'over_limit' => ['price_tier'], 'warnings' => [AssignmentSets::FULL_WARNING]],
            $ability->execute($input + ['preview' => true])
        );
        self::assertSame(['changed' => true, 'over_limit' => ['price_tier']], $ability->execute($input));
        self::assertSame(1, $this->seats->over($this->pool()));
        self::assertTrue($this->seats->holds($this->pool(), $anna));
    }
}
