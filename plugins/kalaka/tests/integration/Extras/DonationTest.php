<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Extras;

use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Pricing\Api\ProposedMoneySettings;

/** The donation (topic 7): one `donation` line from the event's whole-number field. */
final class DonationTest extends ExtrasTestCase
{
    public function testTheEventsNumberIsChargedAsTheDonationLine(): void
    {
        $this->applySettings($this->donation->propose($this->event, 'adomany'));

        $anna = $this->registerWith('00A0', ['adomany' => '5000']);

        self::assertSame(['donation participant 5000 charge:donation:participant:1'], $this->charges($anna));
        self::assertSame('adomany', $this->donation->field($this->event));
    }

    public function testAnEmptyZeroNegativeOrFractionalAmountGivesNoLine(): void
    {
        $this->applySettings($this->donation->propose($this->event, 'adomany'));

        $anna = $this->registerWith('00A0', ['adomany' => '0']);
        $bence = $this->registerWith('00A2', []);
        $csilla = $this->registerWith('00A5', ['adomany' => '-5000']);
        $dora = $this->registerWith('00A8', ['adomany' => '5000.5']);

        foreach ([$anna, $bence, $csilla, $dora] as $key) {
            self::assertSame([], $this->charges($key));
        }
    }

    public function testWithoutTheSettingTheValueIsNotChargedAndRemovingItReversesTheLine(): void
    {
        $anna = $this->registerWith('00A0', ['adomany' => '5000']);
        self::assertSame([], $this->charges($anna));

        $this->applySettings($this->donation->propose($this->event, 'adomany'));
        self::assertSame('5000', $this->balance($anna));

        $this->applySettings($this->donation->propose($this->event, null));
        self::assertNull($this->donation->field($this->event));
        self::assertSame('0', $this->balance($anna));
    }

    public function testThePreviewComposesTheProposedDonationAndWritesNothing(): void
    {
        $anna = $this->registerWith('00A0', ['adomany' => '5000']);
        $lines = $this->lineCount();

        $rows = $this->settings->preview(
            $this->event,
            new ProposedMoneySettings(components: [$this->donation->propose($this->event, 'adomany')])
        );

        self::assertSame([], $rows[0]->before);
        self::assertSame('5000', self::whole($rows[0]->after['participant']));
        self::assertSame($lines, $this->lineCount());
        self::assertNull($this->donation->field($this->event));
        self::assertSame([], $this->charges($anna));
    }

    public function testOnlyAnActiveWholeNumberFieldOfTheEventCanBeTheDonation(): void
    {
        $this->declareField('tizedes', 'kalaka/number-field', ['decimals' => 2], 3);
        $this->declareField('szoveg', 'kalaka/text-field', null, 4);

        foreach (['ebed', 'tizedes', 'szoveg', 'nincs_ilyen'] as $key) {
            try {
                $this->donation->propose($this->event, $key);
                self::fail('Refused.');
            } catch (InvalidInput) {
                self::assertNull($this->donation->field($this->event));
            }
        }
    }
}
