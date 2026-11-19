<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\PriceLadder;

use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\PriceLadder\Api\PriceLadder;
use Kalaka\Pricing\Api\ApplyReport;
use Kalaka\Pricing\Api\ProposedMoneySettings;
use Kalaka\Tests\Integration\Pricing\PricingTestCase;

/**
 * The base of the price-ladder tests: core's Pricing test case (an event in HUF, the money phase, the
 * contributed ladder component) with IVK's two questions, the support level and the price (ordinary answers,
 * which no tier reads), and helpers to save a ladder through a money settings apply.
 */
abstract class PriceLadderTestCase extends PricingTestCase
{
    protected PriceLadder $ladder;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names it
    public function set_up(): void
    {
        parent::set_up();
        $this->ladder = new PriceLadder();
        $this->declareParticipantField($this->event, 'tamogatas', [
            ['value' => 'nincs', 'label' => 'Nincs'],
            ['value' => 'megosztott', 'label' => 'Megosztott'],
            ['value' => 'teljes', 'label' => 'Teljes'],
        ]);
        $this->declareParticipantField($this->event, 'ar', [
            ['value' => 'alap', 'label' => 'Alap'],
            ['value' => 'emelt', 'label' => 'Emelt'],
        ], 1);
    }

    /**
     * IVK's five tiers as configured (the amounts are examples): a tier is a key, a label and an amount.
     *
     * @return list<array<string, mixed>>
     */
    protected static function ivkTiers(): array
    {
        return [
            self::tier('alap', 'Alap ár', '49500'),
            self::tier('teljes', 'Teljes ár', '69500'),
            self::tier('gy_megosztott', 'Gyülekezeti megosztott ár', '44500'),
            self::tier('gy', 'Gyülekezeti ár', '34500'),
            self::tier('gy_alap', 'Alap ár', '49500'),
        ];
    }

    /** @return array<string, mixed> */
    protected static function tier(string $key, string $label, string $amount): array
    {
        return ['key' => $key, 'label' => $label, 'amount' => $amount];
    }

    /** @param list<array<string, mixed>> $tiers */
    protected function applyLadder(array $tiers): ApplyReport
    {
        $proposed = new ProposedMoneySettings(components: [$this->ladder->propose($this->event, $tiers)]);
        $report = $this->settings->apply($this->event, $proposed, new CliActor(null));
        self::assertSame([], $report->failed());

        return $report;
    }
}
