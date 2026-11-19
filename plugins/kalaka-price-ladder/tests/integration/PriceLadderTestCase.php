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
 * contributed ladder component) with IVK's two questions, the support level and the price, and helpers to
 * save a ladder through a money settings apply.
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
     * IVK's tiers, configured as an organizer would: each condition reads the support level, and where a
     * level has two tiers, the price question too. The amounts are examples.
     *
     * @return list<array<string, mixed>>
     */
    protected static function ivkTiers(): array
    {
        return [
            self::tier('alap', 'Alap ár', '49500', ['tamogatas' => 'nincs', 'ar' => 'alap']),
            self::tier('teljes', 'Teljes ár', '69500', ['tamogatas' => 'nincs', 'ar' => 'emelt']),
            self::tier('gy_megosztott', 'Gyülekezeti megosztott ár', '44500', ['tamogatas' => 'megosztott']),
            self::tier('gy', 'Gyülekezeti ár', '34500', ['tamogatas' => 'teljes', 'ar' => 'alap']),
            self::tier('gy_alap', 'Alap ár', '49500', ['tamogatas' => 'teljes', 'ar' => 'emelt']),
        ];
    }

    /**
     * @param array<string, string> $equals field key → option value, all of which must hold; none: for everyone
     * @return array<string, mixed>
     */
    protected static function tier(
        string $key,
        string $label,
        string $amount,
        array $equals = [],
        ?string $until = null,
    ): array {
        $tier = ['key' => $key, 'label' => $label, 'amount' => $amount];
        if ($equals !== []) {
            $items = [];
            foreach ($equals as $field => $value) {
                $items[] = ['key' => $field, 'operator' => 'equals', 'value' => $value];
            }
            $tier['condition'] = ['match' => 'all', 'items' => $items];
        }
        if ($until !== null) {
            $tier['until'] = $until;
        }

        return $tier;
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
