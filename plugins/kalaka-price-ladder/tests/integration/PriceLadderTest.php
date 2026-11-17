<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\PriceLadder;

use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Ledger\Api\Payer;
use Kalaka\PriceLadder\Api\Tier;
use Kalaka\Pricing\Api\PreviewRow;
use Kalaka\Pricing\Api\ProposedMoneySettings;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\RegistrationStatus;

/** S25: the tier a registration's answers reach, its deadline at `registered_at`, and the ladder's apply. */
final class PriceLadderTest extends PriceLadderTestCase
{
    public function testEachRegistrationPaysTheFirstTierWhoseConditionHoldsOverItsAnswers(): void
    {
        $this->applyLadder(self::ivkTiers());

        $expected = [
            '00A0' => ['nincs', 'alap', '49500'],
            '00A2' => ['nincs', 'emelt', '69500'],
            '00A5' => ['megosztott', null, '44500'],
            '00A8' => ['teljes', 'alap', '34500'],
            '01A1' => ['teljes', 'emelt', '49500'],
        ];
        foreach ($expected as $code => [$level, $price, $amount]) {
            $key = $this->registerAnswering($code, $level, $price);
            self::assertSame([self::line($amount)], $this->charges($key));
        }
    }

    public function testAChangedAnswerMovesTheRegistrationToAnotherTierAtItsNextEvaluation(): void
    {
        $this->applyLadder(self::ivkTiers());
        $anna = $this->registerAnswering('00A0', 'nincs', 'alap');

        $this->writeValue($anna, 'ar', 'emelt');

        self::assertSame([self::line('49500'), self::line('20000', 2)], $this->charges($anna));
        self::assertSame('69500', $this->balance($anna));
    }

    public function testNoApplyingTierOwesNothingUnlessTheEventHasAFixedPrice(): void
    {
        $this->applyLadder(self::ivkTiers());
        $bela = $this->registerAnswering('00A0', null, null);
        self::assertSame([], $this->charges($bela));

        $this->setPrice('40000');

        self::assertSame(['fixed_price participant 40000 charge:fixed_price:participant:1'], $this->charges($bela));
        $anna = $this->registerAnswering('00A2', 'nincs', 'alap');
        self::assertSame([self::line('49500')], $this->charges($anna));
    }

    public function testAnEarlyTierCountsFromRegisteredAtNeverFromNow(): void
    {
        $this->applyLadder([
            self::tier('korai', 'Korai alap ár', '44000', ['tamogatas' => 'nincs'], '2026-12-15'),
            self::tier('alap', 'Alap ár', '49500', ['tamogatas' => 'nincs']),
        ]);
        // 23:30 on 15 December in Budapest is still the early tier's last day; 00:30 on the 16th is not.
        $early = $this->registerAnswering('00A0', 'nincs', null, '2026-12-15 22:30:00');
        $late = $this->registerAnswering('00A2', 'nincs', null, '2026-12-15 23:30:00');
        self::assertSame([self::line('44000')], $this->charges($early));
        self::assertSame([self::line('49500')], $this->charges($late));

        $this->now = '2027-01-10 08:00:00';
        $this->useComponents();
        $this->inRun(static fn (array $input, Context $context) => $context->markTouched($early));

        self::assertSame([self::line('44000')], $this->charges($early));
    }

    public function testAWaitlistAdmissionAfterTheDeadlinePaysTodaysTier(): void
    {
        $this->applyLadder([
            self::tier('korai', 'Korai alap ár', '44000', [], '2026-12-15'),
            self::tier('alap', 'Alap ár', '49500'),
        ]);
        $csaba = $this->register('00A0', RegistrationStatus::Waitlisted, '2026-12-01 10:00:00');
        self::assertSame([], $this->charges($csaba));

        $this->setStatus($csaba, RegistrationStatus::Registered, '2026-12-20 10:00:00');

        self::assertSame([self::line('49500')], $this->charges($csaba));
    }

    public function testThePreviewShowsOldAndNewAmountsAndWritesNothingThenTheApplySavesAndConverges(): void
    {
        $this->applyLadder(self::ivkTiers());
        $anna = $this->registerAnswering('00A0', 'nincs', 'alap');
        $dora = $this->registerAnswering('00A2', 'teljes', 'alap');
        $lines = $this->lineCount();
        $raised = self::ivkTiers();
        $raised[0]['amount'] = '52000';
        $proposed = new ProposedMoneySettings(components: [$this->ladder->propose($this->event, $raised)]);

        $rows = $this->settings->preview($this->event, $proposed);

        self::assertSame(
            ['00A0' => true, '00A2' => false],
            array_combine(
                array_map(static fn (PreviewRow $row): string => $row->registration->code, $rows),
                array_map(static fn (PreviewRow $row): bool => $row->changes(), $rows)
            )
        );
        self::assertSame('49500.00000000', $rows[0]->before[Payer::PARTICIPANT]->amount());
        self::assertSame('52000.00000000', $rows[0]->after[Payer::PARTICIPANT]->amount());
        self::assertSame($lines, $this->lineCount());
        self::assertSame('49500.00000000', $this->ladder->tiers($this->event)[0]->amount->amount());

        $report = $this->settings->apply($this->event, $proposed, new CliActor(null));

        self::assertCount(2, $report->done());
        self::assertSame('52000', $this->balance($anna));
        self::assertSame('34500', $this->balance($dora));
        self::assertSame('52000.00000000', $this->ladder->tiers($this->event)[0]->amount->amount());
        $lines = $this->lineCount();
        $this->settings->apply($this->event, $proposed, new CliActor(null));
        self::assertSame($lines, $this->lineCount());
    }

    public function testTheLadderIsSavedInOrderAndAnEmptyLadderRemovesIt(): void
    {
        $this->applyLadder(self::ivkTiers());
        $this->applyLadder([self::ivkTiers()[2], self::ivkTiers()[0]]);

        self::assertSame(
            ['gy_megosztott', 'alap'],
            array_map(static fn (Tier $tier): string => $tier->key, $this->ladder->tiers($this->event))
        );

        $this->applyLadder([]);
        self::assertSame([], $this->ladder->tiers($this->event));
    }

    public function testAConditionOnAnUndeclaredKeyOrATierKeyGivenTwiceIsRefusedBeforeAnythingIsSaved(): void
    {
        foreach (
            [
                [self::tier('alap', 'Alap ár', '49500', ['nincs_ilyen' => 'x'])],
                [self::tier('alap', 'Alap ár', '49500'), self::tier('alap', 'Másik', '1')],
                [self::tier('alap', 'Alap ár', '49500', [], '2026-02-30')],
            ] as $tiers
        ) {
            try {
                $this->ladder->propose($this->event, $tiers);
                self::fail('The ladder was accepted.');
            } catch (InvalidInput) {
                self::assertSame([], $this->ladder->tiers($this->event));
            }
        }
    }

    private function registerAnswering(
        string $code,
        ?string $level,
        ?string $price,
        string $registeredAt = '2026-11-30 08:00:00',
    ): RegistrationKey {
        $key = $this->register($code, RegistrationStatus::Waitlisted, $registeredAt);
        if ($level !== null) {
            $this->writeValue($key, 'tamogatas', $level);
        }
        if ($price !== null) {
            $this->writeValue($key, 'ar', $price);
        }
        $this->setStatus($key, RegistrationStatus::Registered, $registeredAt);

        return $key;
    }

    /** A charge line of the ladder's component, as `charges()` lists it. */
    private static function line(string $amount, int $n = 1): string
    {
        return 'price_ladder participant ' . $amount . ' charge:price_ladder:participant:' . $n;
    }
}
