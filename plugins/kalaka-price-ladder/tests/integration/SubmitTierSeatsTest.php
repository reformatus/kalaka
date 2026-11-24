<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\PriceLadder;

use Kalaka\Events\PublishEvent;
use Kalaka\Forms\Api\InvalidAnswers;
use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Actor\GuestActor;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\PriceLadder\SetPriceLadder;
use Kalaka\PriceLadder\TierSeatPool;
use Kalaka\Registrations\Api\Registrations;
use Kalaka\Registrations\Api\RegistrationStatus;
use Kalaka\Registrations\Api\RegistrationSubmit;
use Kalaka\Registrations\Api\Seats;
use Kalaka\Registrations\Api\SubmitRequest;
use Kalaka\Registrations\Api\SubmitResult;
use Kalaka\Registrations\Api\SubmitToken;
use Kalaka\Tests\Integration\Foundation\AdminProbe;
use Kalaka\Tests\Integration\Forms\FormsTestCase;

/**
 * A submit that chooses a limited price tier takes its seat when it becomes `registered` and is refused, with
 * nothing saved and the message on `price_tier`, when the tier is full (topic 15, Q2). An unlimited tier is
 * never refused; a waitlisted submit holds no seat and is not refused (A3). It runs through the submit entry
 * point, so the contributed claim source is what feeds it.
 */
final class SubmitTierSeatsTest extends FormsTestCase
{
    private int $event = 0;

    private int $form = 0;

    private Seats $seats;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names it
    public function set_up(): void
    {
        parent::set_up();

        $this->seats = new Seats();
        $this->event = $this->create();
        $this->update($this->event, ['capacity' => 2]);
        $this->transition(new PublishEvent(), $this->event);
        $this->runner->run(new SetPriceLadder(), [
            'event_id' => $this->event,
            'currency' => 'HUF',
            'tiers' => [
                ['key' => 'alap', 'label' => 'Alap ár', 'amount' => '49500'],
                ['key' => 'tamogatott', 'label' => 'Támogatott ár', 'amount' => '20000', 'limit' => 1],
            ],
        ], new CliActor(null));
        $this->form = $this->insertForm(
            $this->slotFields() . $this->field('choice', 'price_tier', [
                'required' => true,
                'options' => [
                    ['value' => 'alap', 'label' => 'Alap ár'],
                    ['value' => 'tamogatott', 'label' => 'Támogatott ár'],
                ],
            ]),
            ['kalaka_event_id' => $this->event]
        );
    }

    private function submit(string $tier): SubmitResult
    {
        $form = $this->readForm($this->form);

        return (new RegistrationSubmit($this->runner))->submit(
            new SubmitRequest(
                $form->id,
                $form->version,
                ['name' => 'Teszt Elek', 'email' => 'teszt@example.org', 'price_tier' => $tier],
                SubmitToken::generate()
            ),
            new GuestActor()
        );
    }

    private function pool(): TierSeatPool
    {
        return new TierSeatPool($this->event, 'tamogatott');
    }

    private function registrationCount(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}kalaka_registrations WHERE event_id = %d",
            $this->event
        ));
    }

    public function testALimitedTierTakesItsSeatAtTheSubmit(): void
    {
        $result = $this->submit('tamogatott');

        self::assertSame(RegistrationStatus::Registered, $result->status);
        self::assertTrue($this->seats->holds($this->pool(), $result->registration));
        self::assertSame(0, $this->seats->left($this->pool()));
    }

    public function testAFullTierRefusesTheSubmitOnPriceTierAndNothingIsSaved(): void
    {
        $this->submit('tamogatott');

        try {
            $this->submit('tamogatott');
            self::fail('The full tier should have refused the submit.');
        } catch (InvalidAnswers $invalid) {
            self::assertSame(['price_tier' => 'Ez a lehetőség időközben betelt. Válassz másikat.'], $invalid->fields);
        }

        self::assertSame(1, $this->registrationCount());
        self::assertSame(1, $this->seats->held($this->pool()));

        // Choosing another tier then works.
        $other = $this->submit('alap');
        self::assertSame(RegistrationStatus::Registered, $other->status);
        self::assertFalse($this->seats->holds($this->pool(), $other->registration));
    }

    public function testAnUnlimitedTierIsNeverRefusedAndHoldsNoSeat(): void
    {
        $this->submit('tamogatott');

        $result = $this->submit('alap');

        self::assertSame(RegistrationStatus::Registered, $result->status);
        self::assertSame(1, $this->seats->held($this->pool()));
    }

    public function testAWaitlistedSubmitWithAFullTierIsNotRefusedAndHoldsNoSeat(): void
    {
        $this->submit('alap');
        $this->submit('tamogatott');
        // The event's two places are taken: the next one is waitlisted.
        $waitlisted = $this->submit('tamogatott');

        self::assertSame(RegistrationStatus::Waitlisted, $waitlisted->status);
        self::assertFalse($this->seats->holds($this->pool(), $waitlisted->registration));
        self::assertSame(1, $this->seats->held($this->pool()));
    }

    public function testWithdrawalFreesTheSeatAndTheNextSubmitTakesItAsItsOwnAct(): void
    {
        $first = $this->submit('tamogatott');
        $this->runner->run(
            new AdminProbe(function (array $input, Context $context) use ($first): void {
                (new Registrations())->lock($first->registration, $context);
                $this->seats->releaseAll($first->registration, $context);
            }),
            [],
            new CliActor(null)
        );
        self::assertSame(1, $this->seats->left($this->pool()));

        $second = $this->submit('tamogatott');

        self::assertTrue($this->seats->holds($this->pool(), $second->registration));
        self::assertFalse($this->seats->holds($this->pool(), $first->registration));
    }
}
