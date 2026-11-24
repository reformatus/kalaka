<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Support\Offers;

use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\Module\ActionCatalogue;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Foundation\Decision\DecisionRules;
use Kalaka\Foundation\Evaluation\Evaluation;
use Kalaka\Ledger\RecordPayment;
use Kalaka\Pricing\Api\ConfirmationRule;
use Kalaka\Pricing\Api\FeeComponent;
use Kalaka\Pricing\Api\Materializer;
use Kalaka\Pricing\Api\MoneySettings;
use Kalaka\Pricing\CoreFeeComponents;
use Kalaka\Tests\Integration\Foundation\TestClock;
use Kalaka\Pricing\Api\ApplyReport;
use Kalaka\Pricing\Api\ProposedMoneySettings;
use Kalaka\Registrations\Answer\AnswerRequest;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\Registrations;
use Kalaka\Registrations\Api\Requests;
use Kalaka\Registrations\Api\SeatAct;
use Kalaka\Registrations\Api\Seats;
use Kalaka\Support\Api\Offers;
use Kalaka\Support\Directory\SaveDirectoryNode;
use Kalaka\Support\Offers\OfferSeatPool;
use Kalaka\Tests\Integration\Pricing\PricingTestCase;

/**
 * The base of the support-offer tests: core's Pricing test case (an event in HUF, a fixed price of 30 000 Ft, the
 * money phase with the contributed `support` component and request source), a synthetic directory node `TESZT-1`,
 * and helpers to save offers through a money settings apply, select them, answer them and hold seats.
 */
abstract class OffersTestCase extends PricingTestCase
{
    protected Offers $offers;

    protected Seats $seats;

    private ActionCatalogue $catalogue;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names it
    public function set_up(): void
    {
        parent::set_up();
        $this->catalogue = ActionCatalogue::current();
        ActionCatalogue::record(new ActionCatalogue([Requests::ANSWER, 'kalaka_record_money', 'kalaka_view_money']));
        $this->offers = new Offers();
        $this->seats = new Seats();
        $this->runner->run(new SaveDirectoryNode(), [
            'external_id' => 'TESZT-1',
            'parent_external_id' => null,
            'type' => 'Gyülekezet',
            'name' => 'Teszt Gyülekezet',
            'aliases' => [],
            'active' => true,
        ], new CliActor(null));
        $this->setPrice('30000');
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

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names it
    public function tear_down(): void
    {
        ActionCatalogue::record($this->catalogue);

        parent::tear_down();
    }

    /** @return array<string, mixed> one offer as the settings document carries it */
    protected static function offer(
        string $key,
        string $amount,
        bool $request = false,
        ?int $limit = null,
        string $node = 'TESZT-1',
    ): array {
        return [
            'key' => $key,
            'label' => 'Teszt támogatás ' . $key,
            'node' => $node,
            'amount' => $amount,
            'request' => $request,
            'limit' => $limit,
        ];
    }

    /** @param list<array<string, mixed>> $offers */
    protected function applyOffers(array $offers): ApplyReport
    {
        $proposed = new ProposedMoneySettings(components: [$this->offers->propose($this->event, $offers)]);

        return $this->settings->apply($this->event, $proposed, new CliActor(null));
    }

    /** A registered registration that has ticked the offers, evaluated. */
    protected function registerSelecting(string $code, string ...$keys): RegistrationKey
    {
        $key = $this->register($code);
        foreach ($keys as $offer) {
            $this->writeValue($key, $offer, '1');
        }

        return $key;
    }

    /** @return array<string, mixed> */
    protected function answer(RegistrationKey $key, string $offer, string $answer): array
    {
        $result = $this->runner->run(new AnswerRequest(), [
            'event_id' => $key->eventId,
            'code' => $key->code,
            'request' => 'offer:' . $offer,
            'answer' => $answer,
            // A "yes" to an offer is a partner's approval and carries its reason.
            'note' => $answer === 'yes' ? 'Teszt indok' : '',
        ], new UserActor($this->fullAccessUser()));
        self::assertIsArray($result);

        return $result;
    }

    /** Gives the registration its seat in the limited offer, as its submit would have. */
    protected function holdSeat(RegistrationKey $key, string $offer, bool $over = false): void
    {
        $pool = new OfferSeatPool($key->eventId, $offer);
        $this->inRun(function (array $input, Context $context) use ($key, $pool, $over): void {
            $this->seats->lock($pool, $context);
            $this->lockRegistrations($context, $key);
            if ($over) {
                $this->seats->retake($pool, $key, SeatAct::Submit, $context);

                return;
            }
            $this->seats->take($pool, $key, SeatAct::Submit, $context);
        });
    }

    protected function awaits(RegistrationKey $key): bool
    {
        return (new Requests())->awaits($key, (new Registrations())->find($key)->status ?? throw new \LogicException());
    }

    protected function holds(RegistrationKey $key, string $offer): bool
    {
        return $this->seats->holds(new OfferSeatPool($key->eventId, $offer), $key);
    }
}
