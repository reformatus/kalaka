<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Extras;

use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Pricing\Api\PreviewRow;
use Kalaka\Pricing\Api\ProposedMoneySettings;
use Kalaka\Registrations\Api\RegistrationStatus;

/**
 * Extra charges (topic 14): a charge is linked by key to the participant field with the same key, the amount is
 * multiplied by its whole answer, and each charge is its own `extras.<key>` line.
 */
final class ExtrasTest extends ExtrasTestCase
{
    public function testTheConsentWithTheChargesKeyChargesOnceAndAnAbsentOrZeroAnswerChargesNothing(): void
    {
        $this->applyCharges([self::item('ebed', 'Ebéd', '3500')]);

        $anna = $this->registerWith('00A0', ['ebed' => '1']);
        $bence = $this->registerWith('00A2', ['ebed' => '0']);
        $csilla = $this->registerWith('00A5', []);

        self::assertSame(['extras.ebed participant 3500 charge:extras.ebed:participant:1'], $this->charges($anna));
        self::assertSame([], $this->charges($bence));
        self::assertSame([], $this->charges($csilla));
    }

    public function testTheNumberFieldWithTheChargesKeyMultipliesTheAmount(): void
    {
        $this->applyCharges([self::item('ejjel', 'Éjszaka', '1000')]);

        $anna = $this->registerWith('00A0', ['ejjel' => '3']);

        self::assertSame(['extras.ejjel participant 3000 charge:extras.ejjel:participant:1'], $this->charges($anna));
    }

    public function testAChargeKeyNoFieldUsesChargesNobodyAndRaisesNothing(): void
    {
        $this->applyCharges([self::item('nincs_ilyen', 'Semmi', '1000'), self::item('ebed', 'Ebéd', '3500')]);

        $anna = $this->registerWith('00A0', ['ebed' => '1']);

        self::assertSame(['extras.ebed participant 3500 charge:extras.ebed:participant:1'], $this->charges($anna));
        self::assertSame('3500', $this->balance($anna));
    }

    public function testEveryChargeIsItsOwnLineAndRemovingOneReversesOnlyItsLine(): void
    {
        $this->applyCharges([
            self::item('ebed', 'Ebéd', '3500'),
            self::item('ejjel', 'Éjszaka', '1000'),
        ]);

        $anna = $this->registerWith('00A0', ['ebed' => '1', 'ejjel' => '2']);
        self::assertSame('5500', $this->balance($anna));
        self::assertSame([
            'extras.ebed participant 3500 charge:extras.ebed:participant:1',
            'extras.ejjel participant 2000 charge:extras.ejjel:participant:1',
        ], $this->charges($anna), 'One line for each charge.');

        $this->writeValue($anna, 'ejjel', '1');
        $this->evaluate($anna);
        self::assertSame('4500', $this->balance($anna));

        $this->applyCharges([self::item('ebed', 'Ebéd', '3500')]);
        self::assertSame('3500', $this->balance($anna));
        self::assertSame([
            'extras.ebed participant 3500 charge:extras.ebed:participant:1',
            'extras.ejjel participant 2000 charge:extras.ejjel:participant:1',
            'extras.ejjel participant -1000 charge:extras.ejjel:participant:2',
            'extras.ejjel participant -1000 charge:extras.ejjel:participant:3',
        ], $this->charges($anna), 'The removed charge reverses its own line; the other is untouched.');
    }

    public function testAWithdrawnRegistrationReversesEveryChargeLine(): void
    {
        $this->applyCharges([self::item('ebed', 'Ebéd', '3500'), self::item('ejjel', 'Éjszaka', '1000')]);
        $anna = $this->registerWith('00A0', ['ebed' => '1', 'ejjel' => '2']);
        self::assertSame('5500', $this->balance($anna));

        $this->setStatus($anna, RegistrationStatus::Withdrawn);
        $this->evaluate($anna);

        self::assertSame('0', $this->balance($anna));
        self::assertSame([
            'extras.ebed participant 3500 charge:extras.ebed:participant:1',
            'extras.ejjel participant 2000 charge:extras.ejjel:participant:1',
            'extras.ebed participant -3500 charge:extras.ebed:participant:2',
            'extras.ejjel participant -2000 charge:extras.ejjel:participant:2',
        ], $this->charges($anna), 'Each charge line is reversed.');

        $this->setStatus($anna, RegistrationStatus::Registered);
        $this->evaluate($anna);

        self::assertSame('5500', $this->balance($anna), 'Restored, the charges are owed again.');
    }

    public function testAnythingButAPositiveWholeQuantityChargesNothing(): void
    {
        $this->applyCharges([self::item('ejjel', 'Éjszaka', '1000')]);

        foreach (['00A0' => '0', '00A2' => '-2', '00A5' => '2.5', '00A8' => ''] as $code => $answer) {
            $key = $this->registerWith($code, ['ejjel' => $answer]);
            self::assertSame([], $this->charges($key));
        }
    }

    public function testAnAmountTimesAnAnswerMoneyCannotHoldChargesNobodyAndNothingThrows(): void
    {
        $this->applyCharges([
            self::item('ejjel', 'Éjszaka', '100000000000000'),
            self::item('ebed', 'Ebéd', '3500'),
        ]);

        $anna = $this->registerWith('00A0', ['ejjel' => '999999999999', 'ebed' => '1']);

        self::assertSame(['extras.ebed participant 3500 charge:extras.ebed:participant:1'], $this->charges($anna));
    }

    public function testThePreviewWritesNothingThenTheApplyConverges(): void
    {
        $this->setPrice('40000');
        $anna = $this->registerWith('00A0', ['ebed' => '1']);
        $lines = $this->lineCount();

        $rows = $this->settings->preview(
            $this->event,
            new ProposedMoneySettings(components: [
                $this->extras->propose($this->event, [self::item('ebed', 'Ebéd', '3500')]),
            ])
        );

        self::assertCount(1, $rows);
        self::assertInstanceOf(PreviewRow::class, $rows[0]);
        self::assertSame('40000', self::whole($rows[0]->before['participant']));
        self::assertSame('43500', self::whole($rows[0]->after['participant']));
        self::assertSame($lines, $this->lineCount());
        self::assertSame([], $this->extras->charges($this->event));

        $this->applyCharges([self::item('ebed', 'Ebéd', '3500')]);
        self::assertSame('43500', $this->balance($anna));
        $this->applyCharges([self::item('ebed', 'Ebéd', '3500')]);
        self::assertCount(2, $this->charges($anna), 'A second apply writes nothing.');

        $this->applyCharges([]);
        self::assertSame([], $this->extras->charges($this->event));
        self::assertSame('40000', $this->balance($anna));
    }

    public function testTheLongestChargeKeyFitsTheLedgersSourceKey(): void
    {
        $key = 'a' . str_repeat('b', 56);
        $this->declareField($key, 'kalaka/consent-field', null, 3);
        $this->applyCharges([self::item($key, 'Hosszú', '1000')]);

        $anna = $this->registerWith('00A0', [$key => '1']);

        self::assertSame('1000', $this->balance($anna));
        self::assertSame(64, strlen('extras.' . $key));
    }

    public function testOnlyAConsentAndAWholeNumberFieldChargeAndEveryOtherTypeChargesNobody(): void
    {
        $this->declareField('szoveg', 'kalaka/text-field', null, 3);
        $this->declareField('skala', 'kalaka/scale-field', null, 4);
        $this->declareField('valasz', 'kalaka/choice-field', null, 5);
        $this->applyCharges([
            self::item('szoveg', 'Szöveg', '1000'),
            self::item('skala', 'Skála', '1000'),
            self::item('valasz', 'Választás', '1000'),
        ]);

        $anna = $this->registerWith('00A0', ['szoveg' => '3', 'skala' => '4', 'valasz' => '["1"]']);

        self::assertSame([], $this->charges($anna), 'A text, a scale and a choice field charge nobody.');
    }

    public function testASavedChargeIsNotChargedByANumberFieldDeclaredWithDecimalsAfterwards(): void
    {
        $this->applyCharges([self::item('uj', 'Új', '1000')]);
        $this->declareField('uj', 'kalaka/number-field', ['decimals' => 2], 3);

        $anna = $this->registerWith('00A0', ['uj' => '3']);

        self::assertSame([], $this->charges($anna), 'The component is the guard: a decimals field is no multiplier.');
    }

    public function testTheDonationsKeyIsRefusedAsAChargeKeyWhateverTheFieldIs(): void
    {
        global $wpdb;
        // A whole-number field with the key (decimals 0): no other check refuses it, so only the key does.
        $wpdb->delete(
            $wpdb->prefix . 'kalaka_participant_fields',
            ['event_id' => $this->event, 'field_key' => 'adomany']
        );
        $this->declareField('adomany', 'kalaka/number-field', ['decimals' => 0], 1);

        $this->expectException(InvalidInput::class);
        $this->extras->propose($this->event, [self::item('adomany', 'Adomány', '3500')]);
    }

    public function testAnInvalidChargeIsRefusedBeforeAnythingIsSaved(): void
    {
        $this->declareField('tizedes', 'kalaka/number-field', ['decimals' => 2], 3);

        $bad = [
            [self::item('ebed', 'Ebéd', '3500'), self::item('ebed', 'Ebéd', '3000')],
            [self::item('tizedes', 'Tizedes', '3500')],
            [self::item('adomany', 'Adomány', '3500')],
            [self::item('a' . str_repeat('b', 57), 'Hosszú', '3500')],
            [['key' => 'ebed', 'label' => 'Ebéd', 'amount' => '3500', 'field' => 'ebed']],
            [['key' => 'ebed', 'label' => 'Ebéd', 'amount' => '3500', 'condition' => []]],
        ];
        foreach ($bad as $charges) {
            try {
                $this->extras->propose($this->event, $charges);
                self::fail('Refused.');
            } catch (InvalidInput) {
                self::assertSame([], $this->extras->charges($this->event));
            }
        }
    }
}
