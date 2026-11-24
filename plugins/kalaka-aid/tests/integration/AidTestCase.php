<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Aid;

use Kalaka\Aid\Api\Aid;
use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\Module\ActionCatalogue;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Foundation\Decision\DecisionRules;
use Kalaka\Foundation\Evaluation\Evaluation;
use Kalaka\Ledger\RecordPayment;
use Kalaka\Pricing\Api\ApplyReport;
use Kalaka\Pricing\Api\ConfirmationRule;
use Kalaka\Pricing\Api\FeeComponent;
use Kalaka\Pricing\Api\Materializer;
use Kalaka\Pricing\Api\MoneySettings;
use Kalaka\Pricing\Api\ProposedMoneySettings;
use Kalaka\Pricing\CoreFeeComponents;
use Kalaka\Registrations\Answer\AnswerRequest;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\Registrations;
use Kalaka\Registrations\Api\Requests;
use Kalaka\Tests\Integration\Foundation\TestClock;
use Kalaka\Tests\Integration\Pricing\PricingTestCase;

/**
 * The base of the aid tests: core's Pricing test case (an event in HUF, a fixed price of 30 000 Ft, the money
 * phase with the contributed `aid` component and request source) and helpers to turn the aid on through a money
 * settings apply, state what a registrant can pay, answer the request and read the aid line.
 */
abstract class AidTestCase extends PricingTestCase
{
    protected Aid $aid;

    private ActionCatalogue $catalogue;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names it
    public function set_up(): void
    {
        parent::set_up();
        $this->catalogue = ActionCatalogue::current();
        ActionCatalogue::record(new ActionCatalogue([Requests::ANSWER, 'kalaka_record_money', 'kalaka_view_money']));
        $this->aid = new Aid();
        $this->setPrice('30000');
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names it
    public function tear_down(): void
    {
        ActionCatalogue::record($this->catalogue);

        parent::tear_down();
    }

    /** The runner with core's confirmation decision too, so an answer is followed by money, then confirmation. */
    protected function useComponents(FeeComponent ...$more): void
    {
        $components = new CoreFeeComponents($more);
        $money = new Materializer($components);
        $rules = new DecisionRules();
        $rules->add(new ConfirmationRule($money->composition()), true);
        $this->runner = new Runner(
            new TestClock($this->utc($this->now)),
            true,
            new Evaluation($rules, money: $money)
        );
        $this->settings = new MoneySettings($this->runner, $components);
    }

    /** Turns the event's aid on (or off, with null) through a money settings apply. */
    protected function applyAid(?string $label = 'Teszt ösztöndíj'): ApplyReport
    {
        $proposed = new ProposedMoneySettings(components: [$this->aid->propose($this->event, $label)]);

        return $this->settings->apply($this->event, $proposed, new CliActor(null));
    }

    /** A registered registration that has stated what it can pay, evaluated. */
    protected function registerAsking(string $code, string $canPay): RegistrationKey
    {
        $key = $this->register($code);
        $this->writeValue($key, 'aid_can_pay', $canPay);

        return $key;
    }

    /** @return array<string, mixed> */
    protected function answer(RegistrationKey $key, string $answer, ?string $amount = null): array
    {
        $result = $this->runner->run(new AnswerRequest(), [
            'event_id' => $key->eventId,
            'code' => $key->code,
            'request' => 'aid',
            'answer' => $answer,
            'amount' => $amount,
        ], new UserActor($this->fullAccessUser()));
        self::assertIsArray($result);

        return $result;
    }

    protected function payIn(RegistrationKey $key, string $amount): void
    {
        $this->runner->run(new RecordPayment(), [
            'event_id' => $key->eventId,
            'code' => $key->code,
            'amount' => $amount,
            'currency' => 'HUF',
            'method' => 'bank_transfer',
            'value_date' => '2026-11-29',
            'idempotency_key' => 'k_' . strtolower($key->code) . '_' . $amount,
        ], new UserActor($this->fullAccessUser()));
    }

    protected function confirmationState(RegistrationKey $key): ?string
    {
        global $wpdb;
        $state = $wpdb->get_var($wpdb->prepare(
            "SELECT state FROM %i WHERE event_id = %d AND code = %s AND kind = 'confirmation' ORDER BY id DESC LIMIT 1",
            $wpdb->prefix . 'kalaka_decisions',
            $key->eventId,
            $key->code
        ));

        return $state === null ? null : (string) $state;
    }

    protected function awaits(RegistrationKey $key): bool
    {
        return (new Requests())->awaits($key, (new Registrations())->find($key)->status ?? throw new \LogicException());
    }

    /**
     * The net of the aid's charge lines as "source payer amount", nets of zero left out.
     *
     * @return list<string>
     */
    protected function aidLines(RegistrationKey $key): array
    {
        $net = [];
        foreach ($this->charges($key) as $line) {
            [$source, $payer, $amount] = explode(' ', $line);
            if (str_starts_with($source, 'aid:')) {
                $net[$source . ' ' . $payer] = ($net[$source . ' ' . $payer] ?? 0) + (int) $amount;
            }
        }
        $lines = [];
        foreach ($net as $item => $amount) {
            if ($amount !== 0) {
                $lines[] = $item . ' ' . $amount;
            }
        }

        return $lines;
    }

    /** A plain number field of the event, as a form would have it: a donation's `adomany` is the example. */
    protected function declareNumberField(string $key): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'kalaka_participant_fields', [
            'event_id' => $this->event,
            'field_key' => $key,
            'version' => 1,
            'type' => 'kalaka/number-field',
            'title' => $key,
            'options' => null,
            'settings' => wp_json_encode(['decimals' => 0]),
            'source' => 'form',
            'created_at' => '2026-11-13 08:00:00.000000',
            'updated_at' => '2026-11-13 08:00:00.000000',
            'position' => 9,
            'form_writable' => 0,
        ]);
    }
}
