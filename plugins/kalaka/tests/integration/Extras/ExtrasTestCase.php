<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Extras;

use Kalaka\Extras\Api\Extras;
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
 * extras and donation components) with a consent question (`ebed`), the donation's number field
 * (`adomany`, decimals as its form field sets them) and a whole-number quantity field (`ejjel`), and helpers
 * to save settings through a money settings apply.
 */
abstract class ExtrasTestCase extends PricingTestCase
{
    protected Extras $extras;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook
    public function set_up(): void
    {
        parent::set_up();
        $this->extras = new Extras();
        $this->declareField('ebed', 'kalaka/consent-field', null, 0);
        $this->declareField('adomany', 'kalaka/number-field', ['decimals' => 2, 'suffix' => 'Ft'], 1);
        $this->declareField('ejjel', 'kalaka/number-field', ['decimals' => 0], 2);
    }

    /** A participant field of the event, written as the form sync's use case would. */
    protected function declareField(string $key, string $type, ?array $settings = null, int $position = 0): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'kalaka_participant_fields', [
            'event_id' => $this->event,
            'field_key' => $key,
            'version' => 1,
            'type' => $type,
            'title' => $key,
            'options' => null,
            'settings' => $settings === null ? null : wp_json_encode($settings),
            'source' => 'form',
            'created_at' => '2026-11-13 08:00:00.000000',
            'updated_at' => '2026-11-13 08:00:00.000000',
            'position' => $position,
            'form_writable' => 0,
        ]);
    }

    /**
     * An extra charge, as an organizer would set it: the participant field with the same key calls it.
     *
     * @return array<string, mixed>
     */
    protected static function item(string $key, string $label, string $amount): array
    {
        return ['key' => $key, 'label' => $label, 'amount' => $amount];
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

    /** @param list<array<string, mixed>> $charges */
    protected function applyCharges(array $charges): ApplyReport
    {
        return $this->applySettings($this->extras->propose($this->event, $charges));
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
