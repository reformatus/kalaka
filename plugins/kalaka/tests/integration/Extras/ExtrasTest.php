<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Extras;

use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Pricing\Api\PreviewRow;
use Kalaka\Pricing\Api\ProposedMoneySettings;

/** Extra charges (topic 7): the amount times the linked answer's whole value, all on one `extras` line. */
final class ExtrasTest extends ExtrasTestCase
{
    public function testTheConsentChargesOnceAndAnAbsentOrZeroAnswerChargesNothing(): void
    {
        $this->applyCharges([self::item('lunch', 'Ebéd', '3500', 'ebed')]);

        $anna = $this->registerWith('00A0', ['ebed' => '1']);
        $bence = $this->registerWith('00A2', ['ebed' => '0']);
        $csilla = $this->registerWith('00A5', []);

        self::assertSame(['extras participant 3500 charge:extras:participant:1'], $this->charges($anna));
        self::assertSame([], $this->charges($bence));
        self::assertSame([], $this->charges($csilla));
    }

    public function testAnAmountIsMultipliedByTheWholeAnswerAndEveryChargeSharesOneLine(): void
    {
        $this->applyCharges([
            self::item('lunch', 'Ebéd', '3500', 'ebed'),
            self::item('night', 'Éjszaka', '1000', 'ejjel'),
        ]);

        $anna = $this->registerWith('00A0', ['ebed' => '1', 'ejjel' => '2']);
        self::assertSame('5500', $this->balance($anna));
        self::assertSame(['extras'], array_unique(array_map(
            static fn (string $line): string => explode(' ', $line)[0],
            $this->charges($anna)
        )), 'Every charge is on the one extras line source.');

        $this->writeValue($anna, 'ejjel', '1');
        $this->evaluate($anna);

        self::assertSame('4500', $this->balance($anna));
        $charges = $this->charges($anna);
        self::assertSame('extras participant -1000', implode(' ', array_slice(explode(' ', end($charges)), 0, 3)));
    }

    public function testAnythingButAPositiveWholeQuantityChargesNothing(): void
    {
        $this->applyCharges([self::item('night', 'Éjszaka', '1000', 'ejjel')]);

        foreach (['00A0' => '0', '00A2' => '-2', '00A5' => '2.5', '00A8' => ''] as $code => $answer) {
            $key = $this->registerWith($code, ['ejjel' => $answer]);
            self::assertSame([], $this->charges($key));
        }
    }

    public function testThePreviewWritesNothingThenTheApplyConverges(): void
    {
        $this->setPrice('40000');
        $anna = $this->registerWith('00A0', ['ebed' => '1']);
        $lines = $this->lineCount();

        $rows = $this->settings->preview(
            $this->event,
            new ProposedMoneySettings(components: [
                $this->extras->propose($this->event, [self::item('lunch', 'Ebéd', '3500', 'ebed')]),
            ])
        );

        self::assertCount(1, $rows);
        self::assertInstanceOf(PreviewRow::class, $rows[0]);
        self::assertSame('40000', self::whole($rows[0]->before['participant']));
        self::assertSame('43500', self::whole($rows[0]->after['participant']));
        self::assertSame($lines, $this->lineCount());
        self::assertSame([], $this->extras->charges($this->event));

        $this->applyCharges([self::item('lunch', 'Ebéd', '3500', 'ebed')]);
        self::assertSame('43500', $this->balance($anna));
        $this->applyCharges([self::item('lunch', 'Ebéd', '3500', 'ebed')]);
        self::assertCount(2, $this->charges($anna), 'A second apply writes nothing.');

        $this->applyCharges([]);
        self::assertSame([], $this->extras->charges($this->event));
        self::assertSame('40000', $this->balance($anna));
    }

    public function testAnInvalidChargeIsRefusedBeforeAnythingIsSaved(): void
    {
        $this->declareField('tizedes', 'kalaka/number-field', ['decimals' => 2], 3);
        $this->declareField('szoveg', 'kalaka/text-field', null, 4);

        $bad = [
            [self::item('lunch', 'Ebéd', '3500', 'ebed'), self::item('lunch', 'Ebéd', '3000', 'ebed')],
            [self::item('lunch', 'Ebéd', '3500', 'nincs_ilyen')],
            [self::item('lunch', 'Ebéd', '3500', 'tizedes')],
            [self::item('lunch', 'Ebéd', '3500', 'szoveg')],
            [['key' => 'lunch', 'label' => 'Ebéd', 'amount' => '3500', 'field' => 'ebed', 'condition' => []]],
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
