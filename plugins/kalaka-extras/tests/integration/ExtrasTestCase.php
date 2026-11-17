<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Extras;

use Kalaka\Extras\Api\Donation;
use Kalaka\Extras\Api\Lunch;
use Kalaka\Extras\Donation\AmountFieldType;
use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Pricing\Api\ApplyReport;
use Kalaka\Pricing\Api\ProposedComponentSettings;
use Kalaka\Pricing\Api\ProposedMoneySettings;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Tests\Integration\Pricing\PricingTestCase;

/**
 * The base of the extras tests: core's Pricing test case (an event in HUF, the money phase, the contributed
 * lunch and donation components) with two questions, whether the participant asks for lunch (`ebed`) and a
 * donation amount (`adomany`, an amount field), and helpers to save the settings through a money settings apply.
 */
abstract class ExtrasTestCase extends PricingTestCase
{
    protected Lunch $lunch;

    protected Donation $donation;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook
    public function set_up(): void
    {
        parent::set_up();
        $this->lunch = new Lunch();
        $this->donation = new Donation();
        $this->declareParticipantField($this->event, 'ebed', [
            ['value' => 'igen', 'label' => 'Igen'],
            ['value' => 'nem', 'label' => 'Nem'],
        ]);
        $this->declareParticipantField($this->event, 'vasarnap', [
            ['value' => 'igen', 'label' => 'Igen'],
            ['value' => 'nem', 'label' => 'Nem'],
        ], 1);
        $this->declareAmountField($this->event, 'adomany', 2);
    }

    /** An amount field of the event, written as the form sync's use case would. */
    protected function declareAmountField(int $event, string $key, int $position = 0): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'kalaka_participant_fields', [
            'event_id' => $event,
            'field_key' => $key,
            'version' => 1,
            'type' => AmountFieldType::BLOCK,
            'title' => $key,
            'options' => null,
            'source' => 'form',
            'created_at' => '2026-11-13 08:00:00.000000',
            'updated_at' => '2026-11-13 08:00:00.000000',
            'position' => $position,
            'form_writable' => 0,
        ]);
    }

    /**
     * A lunch item, as an organizer would set it.
     *
     * @param array<string, string> $equals field key → option value, all of which must hold; none: for everyone
     * @return array<string, mixed>
     */
    protected static function item(string $key, string $label, string $amount, array $equals = []): array
    {
        $item = ['key' => $key, 'label' => $label, 'amount' => $amount];
        if ($equals !== []) {
            $items = [];
            foreach ($equals as $field => $value) {
                $items[] = ['key' => $field, 'operator' => 'equals', 'value' => $value];
            }
            $item['condition'] = ['match' => 'all', 'items' => $items];
        }

        return $item;
    }

    protected function applySettings(ProposedComponentSettings ...$components): ApplyReport
    {
        $report = $this->settings->apply(
            $this->event,
            new ProposedMoneySettings(components: $components),
            new CliActor(null)
        );
        self::assertSame([], $report->failed());

        return $report;
    }

    /** @param list<array<string, mixed>> $items */
    protected function applyLunch(array $items): ApplyReport
    {
        return $this->applySettings($this->lunch->propose($this->event, $items));
    }

    /**
     * A registered registration with its answers written, then evaluated.
     *
     * @param array<string, string> $answers field key → the stored value
     */
    protected function registerWith(string $code, array $answers): RegistrationKey
    {
        $key = new RegistrationKey($this->event, $code);
        $this->insertRegistration($this->newRegistration($this->event, $code));
        foreach ($answers as $field => $value) {
            $this->writeValue($key, $field, $value);
        }
        $this->evaluate($key);

        return $key;
    }

    /** Re-evaluates the registration in a run of its own. */
    protected function evaluate(RegistrationKey $key): void
    {
        $this->inRun(static fn (array $input, Context $context) => $context->markTouched($key));
    }

    /** An amount as a plain decimal string without trailing zeros: `40000`. */
    protected static function whole(Money $money): string
    {
        $amount = $money->amount();

        return str_contains($amount, '.') ? rtrim(rtrim($amount, '0'), '.') : $amount;
    }
}
