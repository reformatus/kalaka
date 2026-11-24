<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Support\Offers;

use Kalaka\Events\Api\Events;
use Kalaka\Events\PublishEvent;
use Kalaka\Forms\Api\InvalidAnswers;
use Kalaka\Forms\Api\ValidatedAnswers;
use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Actor\GuestActor;
use Kalaka\ParticipantFields\Api\ParticipantFields;
use Kalaka\Registrations\Api\RegistrationStatus;
use Kalaka\Registrations\Api\Seats;
use Kalaka\Registrations\Api\SubmitResult;
use Kalaka\Registrations\Api\SubmitToken;
use Kalaka\Registrations\SubmitRegistration;
use Kalaka\Support\Directory\SaveDirectoryNode;
use Kalaka\Support\Offers\OfferClaims;
use Kalaka\Support\Offers\OfferSeatPool;
use Kalaka\Support\Offers\SetSupportOffers;
use Kalaka\Tests\Integration\Forms\FormsTestCase;

/**
 * A submit that ticks a limited support offer takes its seat when it becomes `registered` and is refused, with
 * nothing saved and the message on the offer's own field, when the offer is full (topic 15, Q2). An unlimited or
 * an unticked offer claims nothing.
 */
final class SubmitOffersTest extends FormsTestCase
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
        $this->update($this->event, ['capacity' => 10]);
        $this->transition(new PublishEvent(), $this->event);
        $this->runner->run(new SaveDirectoryNode(), [
            'external_id' => 'TESZT-1',
            'parent_external_id' => null,
            'type' => 'Gyülekezet',
            'name' => 'Teszt Gyülekezet',
            'aliases' => [],
            'active' => true,
        ], new CliActor(null));
        $this->runner->run(new SetSupportOffers(), [
            'event_id' => $this->event,
            'currency' => 'HUF',
            'offers' => [
                ['key' => 'keret', 'label' => 'Teszt keret', 'node' => 'TESZT-1', 'amount' => '5000',
                    'request' => false, 'limit' => 1],
                ['key' => 'szabad', 'label' => 'Teszt szabad', 'node' => 'TESZT-1', 'amount' => '2000',
                    'request' => false, 'limit' => null],
            ],
        ], new CliActor(null));
        $this->form = $this->insertForm(
            $this->slotFields() . $this->field('consent', 'keret') . $this->field('consent', 'szabad'),
            ['kalaka_event_id' => $this->event]
        );
    }

    /** @param array<string, string> $ticked */
    private function submit(array $ticked): SubmitResult
    {
        $answers = ['name' => 'Teszt Elek', 'email' => 'teszt@example.org'] + $ticked;
        $form = $this->readForm($this->form);
        $validated = new ValidatedAnswers($answers, $answers);
        $event = (new Events())->find($this->event) ?? throw new \LogicException();
        $result = $this->runner->run(
            new SubmitRegistration(
                $form,
                $form->version,
                $validated,
                $this->versionOf($this->event),
                [],
                false,
                [],
                (new ParticipantFields())->of($this->event),
                seatClaims: (new OfferClaims())->claims($event, $validated),
            ),
            ['form' => $form->id, 'token' => SubmitToken::generate()],
            new GuestActor()
        );
        self::assertInstanceOf(SubmitResult::class, $result);

        return $result;
    }

    private function pool(): OfferSeatPool
    {
        return new OfferSeatPool($this->event, 'keret');
    }

    private function registrationCount(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}kalaka_registrations WHERE event_id = %d",
            $this->event
        ));
    }

    public function testATickedLimitedOfferTakesItsSeatAtTheSubmit(): void
    {
        $result = $this->submit(['keret' => '1']);

        self::assertSame(RegistrationStatus::Registered, $result->status);
        self::assertTrue($this->seats->holds($this->pool(), $result->registration));
        self::assertSame(0, $this->seats->left($this->pool()));
    }

    public function testAFullOfferRefusesTheSubmitOnItsOwnFieldAndNothingIsSaved(): void
    {
        $this->submit(['keret' => '1']);

        try {
            $this->submit(['keret' => '1']);
            self::fail('The full offer should have refused the submit.');
        } catch (InvalidAnswers $invalid) {
            self::assertSame(['keret' => 'Ez a lehetőség időközben betelt. Válassz másikat.'], $invalid->fields);
        }

        self::assertSame(1, $this->registrationCount());
        self::assertSame(1, $this->seats->held($this->pool()));
    }

    public function testAnUntickedOrAnUnlimitedOfferClaimsNothingAndIsNeverRefused(): void
    {
        $this->submit(['keret' => '1']);

        $unticked = $this->submit(['keret' => '0', 'szabad' => '1']);
        $absent = $this->submit([]);

        self::assertSame(RegistrationStatus::Registered, $unticked->status);
        self::assertFalse($this->seats->holds($this->pool(), $unticked->registration));
        self::assertFalse($this->seats->holds($this->pool(), $absent->registration));
        self::assertSame(3, $this->registrationCount());
    }
}
