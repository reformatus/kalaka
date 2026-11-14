<?php

declare(strict_types=1);

namespace Kalaka\Pricing\Api;

use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Actor\Actor;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Pricing\ApplyMoneySettings;
use Kalaka\Pricing\CoreFeeComponents;
use Kalaka\Pricing\FeeComposer;
use Kalaka\Pricing\SetFixedPrice;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\Registrations;

/**
 * How a change of an event's money settings reaches its registrations: preview → confirm → apply, with a
 * report (foundation.md, "Bulk changes"; there is no tariff snapshot, so money always follows the current
 * settings). The preview writes nothing. The apply saves the settings, then re-evaluates each `registered`
 * and `waitlisted` registration in its own transaction; withdrawn and removed ones are left out (default;
 * re-asked in S23). No mail is sent about a changed amount (S34), and the screen is S72's.
 *
 * Owner rule (2026-11-14): once confirmed, a registration never becomes unconfirmed because amounts change
 * later, an apply included. The preview may show the new balance; the apply never unconfirms.
 * TODO(S20): confirmation is not on main yet. When it lands, the apply must keep every confirmed
 * registration confirmed, and a test must prove it after an apply that raises the price.
 */
final class MoneySettings
{
    public function __construct(
        private readonly ?Runner $runner = null,
        private readonly CoreFeeComponents $components = new CoreFeeComponents(),
        private readonly FeeComposer $composer = new FeeComposer(),
        private readonly Events $events = new Events(),
        private readonly Registrations $registrations = new Registrations(),
    ) {
    }

    /**
     * Composes every open registration of the event under the current and the proposed settings, without
     * writing. Plain reads, outside any use case.
     *
     * @return list<PreviewRow>
     * @throws NotFound when there is no such event
     */
    public function preview(int $eventId, ProposedMoneySettings $proposed): array
    {
        $event = $this->events->find($eventId) ?? throw new NotFound();
        $now = $this->runner()->clock()->now();
        $current = $this->components->list();
        $next = $this->components->list($proposed);

        $rows = [];
        foreach ($this->registrations->openOf($eventId) as $key) {
            $facts = $this->registrations->factsOf($key, $event);
            if ($facts === null) {
                continue;
            }
            try {
                $before = $this->composer->compose($facts, $event->currency, $now, $current);
                $after = $this->composer->compose($facts, $event->currency, $now, $next);
                $before = self::byPayer($before, $event->currency);
                $after = self::byPayer($after, $event->currency);
                $rows[] = new PreviewRow($key, $facts->status, $before, $after);
            } catch (FeeComponentFailed $failed) {
                $rows[] = new PreviewRow($key, $facts->status, [], [], $failed);
            }
        }

        return $rows;
    }

    /**
     * Saves the proposed settings in one use case, then re-evaluates each open registration in a use case
     * of its own. Registrations submitted since the preview are included: the list is read after the save.
     * One failing registration never stops the others.
     *
     * @throws \Kalaka\Foundation\Api\Error\KalakaException when the settings cannot be saved; nothing is
     *                                                       saved and no registration is touched
     */
    public function apply(int $eventId, ProposedMoneySettings $proposed, Actor $actor): ApplyReport
    {
        if ($proposed->fixedPrice !== null) {
            $this->runner()->run(
                new SetFixedPrice(),
                [
                    'event_id' => $eventId,
                    'amount' => $proposed->fixedPrice->amount(),
                    'currency' => $proposed->fixedPrice->currency()->value,
                ],
                $actor
            );
        }

        $items = [];
        foreach ($this->registrations->openOf($eventId) as $key) {
            $items[] = $this->retry($key, $actor);
        }

        return new ApplyReport($items);
    }

    /** Re-evaluates one registration in its own use case: a failed item's retry. */
    public function retry(RegistrationKey $key, Actor $actor): ApplyItem
    {
        try {
            $this->runner()->run(new ApplyMoneySettings(), ['event_id' => $key->eventId, 'code' => $key->code], $actor);
        } catch (\Throwable $failure) {
            return new ApplyItem($key, $failure);
        }

        return new ApplyItem($key);
    }

    private function runner(): Runner
    {
        return $this->runner ?? Runner::current();
    }

    /**
     * @param array<string, array<string, Money>> $composed
     * @return array<string, Money>
     */
    private static function byPayer(array $composed, Currency $currency): array
    {
        $byPayer = [];
        foreach ($composed as $owed) {
            foreach ($owed as $payer => $amount) {
                $byPayer[$payer] = ($byPayer[$payer] ?? Money::zero($currency))->plus($amount);
            }
        }
        ksort($byPayer);

        return $byPayer;
    }
}
