<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Support\Offers;

use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\Module\ActionCatalogue;
use Kalaka\Foundation\Api\UseCase\Context;
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
        ActionCatalogue::record(new ActionCatalogue([Requests::ANSWER]));
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
                $this->seats->takeOver($pool, $key, SeatAct::Submit, $context);

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
