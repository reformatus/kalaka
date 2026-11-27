<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Pricing;

use Kalaka\Foundation\Api\Actor\SystemActor;
use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Ledger\Api\Payer;
use Kalaka\Ledger\RecordCharge;
use Kalaka\Ledger\RecordDiscount;
use Kalaka\Pricing\Api\FeeStage;
use Kalaka\Pricing\Api\LineName;
use Kalaka\Pricing\Api\LineNames;
use Kalaka\Pricing\Api\PreviewRow;
use Kalaka\Pricing\Api\ProposedMoneySettings;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\RegistrationStatus;

/**
 * The core `manual` fee component (money.md, "Recording payments by hand"): each manual charge or discount is
 * its own `charge` line, composed like any fee, reversed by a withdrawal and charged again by a restore.
 */
final class ManualFeeComponentTest extends PricingTestCase
{
    private function record(RegistrationKey $key, bool $discount, string $amount, string $title, string $id): void
    {
        $this->runner->run(
            $discount ? new RecordDiscount() : new RecordCharge(),
            [
                'event_id' => $key->eventId,
                'code' => $key->code,
                'amount' => $amount,
                'currency' => 'HUF',
                'title' => $title,
                'idempotency_key' => $id,
            ],
            new SystemActor()
        );
    }

    /**
     * The charge lines, with each manual fee's ID replaced by its position (the IDs are the database's).
     *
     * @return list<string>
     */
    private function normalised(RegistrationKey $key): array
    {
        $position = [];
        foreach ($this->ledger->manualFeesOf($key) as $index => $fee) {
            $position[$fee->id] = $index + 1;
        }

        return array_map(
            static fn (string $line): string => (string) preg_replace_callback(
                '/manual:(\d+)/',
                static fn (array $match): string => 'manual:' . ($position[(int) $match[1]] ?? $match[1]),
                $line
            ),
            $this->charges($key)
        );
    }

    public function testAnEntryIsItsOwnLineAndAddsToTheFixedPrice(): void
    {
        $this->setPrice('40000');
        $anna = $this->register('00A0');
        $this->record($anna, false, '3000', 'Teszt kirándulás', 'f1');
        $this->record($anna, true, '500', 'Teszt kedvezmény', 'f2');

        self::assertSame([
            'fixed_price participant 40000 charge:fixed_price:participant:1',
            'manual:1 participant 3000 charge:manual:1:participant:1',
            'manual:2 participant -500 charge:manual:2:participant:1',
        ], $this->normalised($anna));
        self::assertSame('42500', $this->balance($anna));
    }

    /** It converges: a second evaluation writes nothing. */
    public function testItConverges(): void
    {
        $anna = $this->register('00A0');
        $this->record($anna, false, '3000', 'Teszt', 'f1');
        $this->inRun(static fn (array $input, Context $context) => $context->markTouched($anna));

        self::assertCount(1, $this->normalised($anna));
    }

    /** The withdrawal reverses every manual line; the restore charges them again. */
    public function testAWithdrawalReversesTheManualLinesAndARestoreChargesThemAgain(): void
    {
        $this->setPrice('40000');
        $anna = $this->register('00A0');
        $this->record($anna, false, '3000', 'Teszt kirándulás', 'f1');
        $this->record($anna, true, '500', 'Teszt kedvezmény', 'f2');
        $this->pay($anna, '1000');

        $this->setStatus($anna, RegistrationStatus::Withdrawn);

        self::assertSame('-1000', $this->balance($anna));
        self::assertContains('manual:1 participant -3000 charge:manual:1:participant:2', $this->normalised($anna));
        self::assertContains('manual:2 participant 500 charge:manual:2:participant:2', $this->normalised($anna));

        $this->setStatus($anna, RegistrationStatus::Registered);

        self::assertSame('41500', $this->balance($anna));
        self::assertContains('manual:1 participant 3000 charge:manual:1:participant:3', $this->normalised($anna));
    }

    /** The money settings preview composes the manual lines too: they are part of "owes now / would owe". */
    public function testThePreviewIncludesTheManualLines(): void
    {
        $this->setPrice('40000');
        $anna = $this->register('00A0');
        $this->record($anna, false, '3000', 'Teszt', 'f1');

        $rows = $this->settings->preview($this->event, new ProposedMoneySettings($this->huf('45000')));

        self::assertCount(1, $rows);
        self::assertInstanceOf(PreviewRow::class, $rows[0]);
        self::assertSame('43000', $this->plainAmount($rows[0]->before[Payer::PARTICIPANT]->amount()));
        self::assertSame('48000', $this->plainAmount($rows[0]->after[Payer::PARTICIPANT]->amount()));
    }

    private function plainAmount(string $amount): string
    {
        return rtrim(rtrim($amount, '0'), '.');
    }

    public function testTheLinesAreNamedByTheFactNotByTheSign(): void
    {
        $anna = $this->register('00A0');
        $this->record($anna, false, '3000', 'Teszt kirándulás', 'f1');
        $this->record($anna, true, '500', 'Teszt kedvezmény', 'f2');
        $this->setStatus($anna, RegistrationStatus::Withdrawn);

        [$charge, $discount] = $this->ledger->manualFeesOf($anna);
        $names = (new LineNames())->of($anna, [
            new Subject('fee_component', null, 'manual:' . $charge->id),
            new Subject('fee_component', null, 'manual:' . $discount->id),
            new Subject('fee_component', null, 'fixed_price'),
        ]);
        $names = [
            'manual:1' => $names['manual:' . $charge->id],
            'manual:2' => $names['manual:' . $discount->id],
            'fixed_price' => $names['fixed_price'],
        ];

        self::assertSame(LineName::MANUAL_CHARGE, $names['manual:1']->type);
        self::assertSame('Kézi díj', $names['manual:1']->label);
        self::assertSame('Teszt kirándulás', $names['manual:1']->title);
        self::assertNull($names['manual:1']->recordedBy);
        self::assertSame(LineName::MANUAL_DISCOUNT, $names['manual:2']->type);
        self::assertSame('Kézi kedvezmény', $names['manual:2']->label);
        // Anything that names nothing reads as the fixed price's word.
        self::assertSame(LineName::FEE, $names['fixed_price']->type);
        self::assertSame('Díj', $names['fixed_price']->label);
        self::assertNull($names['fixed_price']->title);
    }

    /** The stage tells the base price (the tier) from any other line; only a registered component has one. */
    public function testTheNamesCarryTheirComponentsStage(): void
    {
        $anna = $this->register('00A0');
        $this->record($anna, false, '3000', 'Teszt kirándulás', 'f1');
        [$fee] = $this->ledger->manualFeesOf($anna);

        $names = (new LineNames())->of($anna, [
            new Subject('fee_component', null, 'fixed_price'),
            new Subject('fee_component', null, 'manual:' . $fee->id),
            new Subject('fee_component', null, 'retention'),
            new Subject('other', null, 'fixed_price_like'),
        ]);

        self::assertSame(FeeStage::Tier, $names['fixed_price']->stage);
        self::assertSame(FeeStage::Extras, $names['manual:' . $fee->id]->stage);
        self::assertNull($names['retention']->stage);
        self::assertNull($names['fixed_price_like']->stage);
    }

    public function testErasingTheNotesAlsoErasesTheTitlesAndKeepsTheFactAndItsLine(): void
    {
        $anna = $this->register('00A0');
        $this->record($anna, false, '3000', 'Teszt kirándulás', 'f1');

        $erased = $this->inRun(function (array $input, Context $context) use ($anna): int {
            $this->lockRegistrations($context, $anna);

            return $this->ledger->eraseNotesOf($anna, $context);
        });

        self::assertSame(1, $erased);
        $fees = $this->ledger->manualFeesOf($anna);
        self::assertCount(1, $fees);
        self::assertNull($fees[0]->title);
        self::assertCount(1, $this->normalised($anna));
        // A replay of the original entry still finds its fact after the erase.
        $this->record($anna, false, '3000', 'Teszt kirándulás', 'f1');
        self::assertCount(1, $this->ledger->manualFeesOf($anna));
    }
}
