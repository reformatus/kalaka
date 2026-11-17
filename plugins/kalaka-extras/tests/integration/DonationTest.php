<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Extras;

use Kalaka\Extras\Donation\AmountFieldType;
use Kalaka\Forms\Api\FieldTypeInput;
use Kalaka\Forms\Api\MoneyRelevantFieldType;
use Kalaka\Forms\Api\Value;
use Kalaka\Forms\Api\ValueType;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\Ledger\Api\Payer;
use Kalaka\Pricing\Api\ProposedMoneySettings;

/** The donation: the amount field type, and its value charged as one participant line at the extras stage. */
final class DonationTest extends ExtrasTestCase
{
    public function testTheAmountIsChargedAsTheDonationLine(): void
    {
        $this->applySettings($this->donation->propose($this->event, 'adomany'));

        $anna = $this->registerWith('00A0', ['adomany' => '5000']);

        self::assertSame(['donation participant 5000 charge:donation:participant:1'], $this->charges($anna));
        self::assertSame('adomany', $this->donation->field($this->event));
    }

    public function testAnEmptyOrZeroAmountGivesNoLine(): void
    {
        $this->applySettings($this->donation->propose($this->event, 'adomany'));

        $anna = $this->registerWith('00A0', ['adomany' => '0']);
        $bence = $this->registerWith('00A2', []);

        self::assertSame([], $this->charges($anna));
        self::assertSame([], $this->charges($bence));
    }

    public function testWithoutTheSettingTheAmountIsNotChargedAndRemovingTheSettingReversesIt(): void
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
        self::assertSame('5000', self::whole($rows[0]->after[Payer::PARTICIPANT]));
        self::assertSame($lines, $this->lineCount());
        self::assertNull($this->donation->field($this->event));
        self::assertSame([], $this->charges($anna));
    }

    public function testOnlyAnActiveAmountFieldOfTheEventCanBeTheDonation(): void
    {
        foreach (['ebed', 'nincs_ilyen'] as $key) {
            try {
                $this->donation->propose($this->event, $key);
                self::fail('Refused.');
            } catch (InvalidInput) {
                self::assertNull($this->donation->field($this->event));
            }
        }
    }

    public function testTheTypeTakesAWholeAmountWithinItsLimits(): void
    {
        $type = new AmountFieldType();
        $limits = ['min' => 1000, 'step' => 500, 'max' => 100000];
        $stored = static fn (string $sent, array $attributes = []): ?string
            => $type->answer(new FieldTypeInput($sent, $attributes))->value;
        $error = static fn (string $sent, array $attributes = []): ?string
            => $type->answer(new FieldTypeInput($sent, $attributes))->error;

        self::assertSame('5000', $stored(' 5 000 ', $limits));
        self::assertSame('0', $stored('0', $limits), 'No amount is always fine, whatever the smallest.');
        self::assertSame('100000', $stored('100000', $limits));
        self::assertSame('Csak egész forintösszeget írhatsz ide.', $error('12,5'));
        self::assertSame('Csak egész forintösszeget írhatsz ide.', $error('-100'));
        self::assertSame('Csak egész forintösszeget írhatsz ide.', $error('1234567890123'));
        self::assertSame('Ha megadsz összeget, legalább 1 000 Ft legyen.', $error('500', $limits));
        self::assertSame('Az összeg 500 Ft többszöröse legyen.', $error('1200', $limits));
        self::assertSame('Legfeljebb 100 000 Ft-ot adhatsz meg.', $error('100500', $limits));

        $this->expectException(InvalidInput::class);
        $type->answer(new FieldTypeInput(5000, []));
    }

    public function testTheValueIsANumberWithItsTextAndTheTypeIsMoneyRelevant(): void
    {
        $type = Contributions::current()->fieldTypes()[AmountFieldType::BLOCK] ?? null;

        self::assertInstanceOf(AmountFieldType::class, $type);
        // It carries the marker; the lock after the first write is S60's to enforce and is not tested here.
        self::assertInstanceOf(MoneyRelevantFieldType::class, $type);
        self::assertSame(ValueType::Number, $type->valueType());
        self::assertEquals(Value::number('5000', '5 000 Ft'), $type->value(new FieldTypeInput('5000', [])));
        self::assertTrue($type->value(new FieldTypeInput('', []))->isEmpty());
        self::assertSame('5000', $type->prefill(new FieldTypeInput('5000', [])), 'A link may prefill it.');
    }
}
