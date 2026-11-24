<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\PriceLadder;

use Kalaka\Events\PublishEvent;
use Kalaka\Forms\Api\ValueType;
use Kalaka\Forms\DefinitionCheck;
use Kalaka\Forms\Api\FormPlacement;
use Kalaka\Forms\EventFormHost;
use Kalaka\Forms\Module as FormsModule;
use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Actor\GuestActor;
use Kalaka\PriceLadder\SetPriceLadder;
use Kalaka\Portal\ActiveRegistrationState;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\Registrations;
use Kalaka\Registrations\Api\RegistrationSubmit;
use Kalaka\Registrations\Api\SeatNotClaimable;
use Kalaka\Registrations\Api\SubmitRequest;
use Kalaka\Registrations\Api\SubmitToken;
use Kalaka\Registrations\Api\ValueLocked;
use Kalaka\Tests\Integration\Forms\FormsTestCase;

/**
 * CF4, with the real price ladder: a tier's places left are the form variable `seats_left.price_tier.<tier key>`
 * (served from `TierClaims::pools()` with no code in Forms), and an additional form takes no new seat and cannot
 * change `price_tier` once written. Tier keys are `[a-z][a-z0-9_]*`, so a hyphenated tier key does not exist.
 */
final class TierFormVariablesTest extends FormsTestCase
{
    private int $event = 0;

    private int $slot = 0;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names it
    public function set_up(): void
    {
        parent::set_up();
        (new FormsModule())->registerMeta();
        $this->event = $this->create();
        $this->update($this->event, ['capacity' => 10]);
        $this->transition(new PublishEvent(), $this->event);
        $this->saveLadder([
            ['key' => 'alap', 'label' => 'Alap ár', 'amount' => '49500'],
            ['key' => 'tamogatott', 'label' => 'Támogatott ár', 'amount' => '20000', 'limit' => 2],
        ]);
        $this->slot = $this->insertForm(
            $this->slotFields() . $this->tierField(),
            ['kalaka_event_id' => $this->event]
        );
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names it
    public function tear_down(): void
    {
        ActiveRegistrationState::set(null, false);
        parent::tear_down();
    }

    /** @param list<array<string, mixed>> $tiers */
    private function saveLadder(array $tiers): void
    {
        $this->runner->run(
            new SetPriceLadder(),
            ['event_id' => $this->event, 'currency' => 'HUF', 'tiers' => $tiers],
            new CliActor(null)
        );
    }

    private function tierField(): string
    {
        return $this->field('choice', 'price_tier', [
            'required' => true,
            'options' => [
                ['value' => 'alap', 'label' => 'Alap ár'],
                ['value' => 'tamogatott', 'label' => 'Támogatott ár'],
            ],
        ]);
    }

    private function submitter(): RegistrationSubmit
    {
        return new RegistrationSubmit($this->runner);
    }

    private function register(string $tier, string $name = 'Teszt Elek'): RegistrationKey
    {
        return $this->submitter()->submit(
            new SubmitRequest(
                $this->slot,
                $this->readForm($this->slot)->version,
                ['name' => $name, 'email' => 'teszt@example.org', 'price_tier' => $tier],
                SubmitToken::generate()
            ),
            new GuestActor()
        )->registration;
    }

    private function facts(int $form): \Kalaka\Forms\Api\ValueContext
    {
        $facts = (new EventFormHost())->facts($this->readForm($form));
        self::assertNotNull($facts);

        return $facts;
    }

    /** @param array<string, mixed> $answers */
    private function send(int $form, array $answers): array
    {
        return (new EventFormHost(registrationSubmit: $this->submitter()))->submit(
            $form,
            $this->readForm($form)->version,
            $answers,
            SubmitToken::generate(),
            [],
            false,
            new GuestActor()
        );
    }

    private function additional(string $content): int
    {
        return $this->insertForm(
            $content,
            ['kalaka_event_id' => $this->event, 'kalaka_placement' => 'additional']
        );
    }

    private function branch(string $key, string $inner): string
    {
        $item = ['key' => $key, 'operator' => 'greaterThan', 'value' => '0'];

        return '<!-- wp:kalaka/if ' . wp_json_encode(['condition' => ['match' => 'all', 'items' => [$item]]])
            . ' -->' . $inner . '<!-- /wp:kalaka/if -->';
    }

    public function testALimitedTiersPlacesAreDeclaredAndCountDownAsRegistrationsTakeIt(): void
    {
        $form = $this->additional($this->field('text', 'note'));
        $types = (new EventFormHost())->factTypes($this->readForm($form));
        self::assertSame(ValueType::Number, $types['seats_left.price_tier.tamogatott']);
        self::assertSame(ValueType::Number, $types['seats_left.price_tier.alap']);

        self::assertSame('2', $this->facts($form)->get('seats_left.price_tier.tamogatott')->data);
        $this->register('tamogatott', 'Teszt Anna');
        self::assertSame('1', $this->facts($form)->get('seats_left.price_tier.tamogatott')->data);
        $this->register('tamogatott', 'Teszt Béla');
        self::assertSame('0', $this->facts($form)->get('seats_left.price_tier.tamogatott')->data);
        // The registration form reads the same variable.
        self::assertSame('0', $this->facts($this->slot)->get('seats_left.price_tier.tamogatott')->data);
    }

    public function testAnUnlimitedTierReadsNone(): void
    {
        $form = $this->additional($this->field('text', 'note'));
        $this->register('alap');

        $value = $this->facts($form)->get('seats_left.price_tier.alap');
        self::assertTrue($value->isEmpty());
        self::assertSame(ValueType::Number, $value->type);
    }

    public function testARemovedTierReadsZeroInAStoredRevisionAndASaveRefusesIt(): void
    {
        $content = $this->slotFields() . $this->tierField()
            . $this->branch('seats_left.price_tier.tamogatott', $this->field('text', 'extra'));
        $form = $this->insertForm($content, ['kalaka_event_id' => $this->event]);
        self::assertSame('2', $this->facts($form)->get('seats_left.price_tier.tamogatott')->data);
        self::assertNull(DefinitionCheck::problem($content, $form, FormPlacement::Registration, $this->event));

        $this->saveLadder([['key' => 'alap', 'label' => 'Alap ár', 'amount' => '49500']]);

        self::assertSame('0', $this->facts($form)->get('seats_left.price_tier.tamogatott')->data);
        self::assertSame(
            ['condition_key_not_earlier', 'seats_left.price_tier.tamogatott'],
            DefinitionCheck::problem($content, $form, FormPlacement::Registration, $this->event)
        );
    }

    public function testAnAdditionalFormCannotNewlyChooseATier(): void
    {
        $key = $this->register('alap');
        ActiveRegistrationState::set($key, false);
        $form = $this->additional($this->tierField() . $this->field('text', 'note'));
        // A registration with no tier yet (the first write is free of the lock); the form would claim one.
        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'kalaka_field_values', [
            'event_id' => $key->eventId,
            'code' => $key->code,
            'field_key' => 'price_tier',
        ]);
        \Kalaka\Foundation\ObjectCache::clear();

        try {
            $this->send($form, ['price_tier' => 'tamogatott', 'note' => 'x']);
            self::fail('An additional form took a seat in the limited tier.');
        } catch (SeatNotClaimable $violation) {
            self::assertSame('kalaka_seat_not_claimable', $violation->errorCode());
            self::assertArrayNotHasKey('price_tier', (new Registrations())->values($key));
            self::assertArrayNotHasKey('note', (new Registrations())->values($key));
        }
        // The tier whose seat row the registration already holds is no new seat.
        $this->send($form, ['price_tier' => 'alap', 'note' => 'x']);
        self::assertSame('alap', (new Registrations())->values($key)['price_tier']);
    }

    public function testPriceTierIsLockedAfterItsFirstWriteOnAnAdditionalForm(): void
    {
        $key = $this->register('alap');
        ActiveRegistrationState::set($key, false);
        $form = $this->additional($this->tierField() . $this->field('text', 'note'));

        // The same tier again is no change; another tier is a change of a locked field.
        $this->send($form, ['price_tier' => 'alap', 'note' => 'egy']);
        try {
            $this->send($form, ['price_tier' => 'tamogatott', 'note' => 'ketto']);
            self::fail('price_tier was overwritten by an additional form.');
        } catch (ValueLocked $violation) {
            self::assertSame('kalaka_value_locked', $violation->errorCode());
            self::assertSame('alap', (new Registrations())->values($key)['price_tier']);
            self::assertSame('egy', (new Registrations())->values($key)['note']);
        }
    }
}
