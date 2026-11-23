<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Extras;

/** The donation (topic 14): the event's number field with the fixed key `adomany`, no setting at all. */
final class DonationTest extends ExtrasTestCase
{
    public function testTheFieldWithTheFixedKeyIsChargedAsTheDonationLine(): void
    {
        $anna = $this->registerWith('00A0', ['adomany' => '5000']);

        self::assertSame(['donation participant 5000 charge:donation:participant:1'], $this->charges($anna));
    }

    public function testADecimalValueIsChargedAsItIsAndAChangedValueFollows(): void
    {
        $anna = $this->registerWith('00A0', ['adomany' => '5000.5']);
        self::assertSame(['donation participant 5000.5 charge:donation:participant:1'], $this->charges($anna));

        $this->writeValue($anna, 'adomany', '1200.25');
        $this->evaluate($anna);
        self::assertSame('1200.25', $this->balance($anna));
    }

    public function testAnEmptyZeroOrNegativeAmountGivesNoLine(): void
    {
        $anna = $this->registerWith('00A0', ['adomany' => '0']);
        $bence = $this->registerWith('00A2', []);
        $csilla = $this->registerWith('00A5', ['adomany' => '-5000']);

        foreach ([$anna, $bence, $csilla] as $key) {
            self::assertSame([], $this->charges($key));
        }
    }

    public function testAnotherKeyIsNotTheDonation(): void
    {
        $anna = $this->registerWith('00A0', ['ejjel' => '5000']);

        self::assertSame([], $this->charges($anna));
    }

    public function testOnlyANumberFieldIsTheDonationNotAConsentOrATextFieldWithTheKey(): void
    {
        global $wpdb;
        $wpdb->delete(
            $wpdb->prefix . 'kalaka_participant_fields',
            ['event_id' => $this->event, 'field_key' => 'adomany']
        );
        $this->declareField('adomany', 'kalaka/consent-field', null, 1);
        $anna = $this->registerWith('00A0', ['adomany' => '1']);
        self::assertSame([], $this->charges($anna), 'A consent with the key is no donation.');

        $wpdb->delete(
            $wpdb->prefix . 'kalaka_participant_fields',
            ['event_id' => $this->event, 'field_key' => 'adomany']
        );
        $this->declareField('adomany', 'kalaka/text-field', null, 1);
        $bence = $this->registerWith('00A2', ['adomany' => '5000']);
        self::assertSame([], $this->charges($bence), 'A text field with the key is no donation.');
    }

    public function testAnEventWithoutTheFieldOwesNoDonation(): void
    {
        global $wpdb;
        $wpdb->delete(
            $wpdb->prefix . 'kalaka_participant_fields',
            ['event_id' => $this->event, 'field_key' => 'adomany']
        );

        $anna = $this->registerWith('00A0', []);

        self::assertSame([], $this->charges($anna));
    }
}
