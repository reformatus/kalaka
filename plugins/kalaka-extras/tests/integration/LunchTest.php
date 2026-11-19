<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Extras;

use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Ledger\Api\Payer;
use Kalaka\Pricing\Api\PreviewRow;
use Kalaka\Pricing\Api\ProposedMoneySettings;

/** Lunch: items charged by condition, as one participant line at the extras stage, saved through preview and apply. */
final class LunchTest extends ExtrasTestCase
{
    public function testLunchIsChargedOnlyWhereItsConditionHolds(): void
    {
        $this->applyLunch([self::item('ebed', 'Ebéd', '3500', ['ebed' => 'igen'])]);

        $anna = $this->registerWith('00A0', ['ebed' => 'igen']);
        $bence = $this->registerWith('00A2', ['ebed' => 'nem']);
        $csilla = $this->registerWith('00A5', []);

        self::assertSame(['lunch participant 3500 charge:lunch:participant:1'], $this->charges($anna));
        self::assertSame([], $this->charges($bence));
        self::assertSame([], $this->charges($csilla));
    }

    public function testItemsThatHoldAreSummedIntoTheLunchLineAndAChangedAnswerReprices(): void
    {
        $this->applyLunch([
            self::item('szombat', 'Szombati ebéd', '3500', ['ebed' => 'igen']),
            self::item('vasarnap', 'Vasárnapi ebéd', '3000', ['vasarnap' => 'igen']),
        ]);
        $anna = $this->registerWith('00A0', ['ebed' => 'igen', 'vasarnap' => 'igen']);
        self::assertSame('6500', $this->balance($anna));
        self::assertSame(['lunch'], array_unique(array_map(
            static fn (string $line): string => explode(' ', $line)[0],
            $this->charges($anna)
        )), 'Every item is on the one lunch line source.');

        $this->writeValue($anna, 'vasarnap', 'nem');
        $this->evaluate($anna);

        self::assertSame('3500', $this->balance($anna));
        $charges = $this->charges($anna);
        self::assertSame('lunch participant -3000', implode(' ', array_slice(explode(' ', end($charges)), 0, 3)));
    }

    public function testThePreviewShowsTheLunchAndWritesNothingThenTheApplyConverges(): void
    {
        $this->setPrice('40000');
        $anna = $this->registerWith('00A0', ['ebed' => 'igen']);
        $lines = $this->lineCount();

        $rows = $this->settings->preview(
            $this->event,
            new ProposedMoneySettings(components: [
                $this->lunch->propose($this->event, [self::item('ebed', 'Ebéd', '3500', ['ebed' => 'igen'])]),
            ])
        );

        self::assertCount(1, $rows);
        self::assertInstanceOf(PreviewRow::class, $rows[0]);
        self::assertSame('40000', self::whole($rows[0]->before[Payer::PARTICIPANT]));
        self::assertSame('43500', self::whole($rows[0]->after[Payer::PARTICIPANT]));
        self::assertSame($lines, $this->lineCount());
        self::assertSame([], $this->lunch->items($this->event));

        $this->applyLunch([self::item('ebed', 'Ebéd', '3500', ['ebed' => 'igen'])]);
        self::assertSame('43500', $this->balance($anna));
        $this->applyLunch([self::item('ebed', 'Ebéd', '3500', ['ebed' => 'igen'])]);
        self::assertCount(2, $this->charges($anna), 'A second apply writes nothing.');

        $this->applyLunch([]);
        self::assertSame([], $this->lunch->items($this->event));
        self::assertSame('40000', $this->balance($anna));
    }

    public function testAnUndeclaredKeyOrARepeatedItemIsRefusedBeforeAnythingIsSaved(): void
    {
        foreach (
            [
                [self::item('ebed', 'Ebéd', '3500', ['nincs_ilyen' => 'igen'])],
                [self::item('ebed', 'Ebéd', '3500'), self::item('ebed', 'Ebéd', '3000')],
            ] as $items
        ) {
            try {
                $this->lunch->propose($this->event, $items);
                self::fail('Refused.');
            } catch (InvalidInput) {
                self::assertSame([], $this->lunch->items($this->event));
            }
        }
    }
}
