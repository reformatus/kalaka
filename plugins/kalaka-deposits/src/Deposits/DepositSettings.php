<?php

declare(strict_types=1);

namespace Kalaka\Deposits;

/**
 * The per-event deposit amount: one option, `kalaka_deposits`, a map of event ID to a decimal text in
 * the event's currency. An event without an entry has no deposit. The amount stays text here; the
 * money side converts it with the event's currency.
 */
final class DepositSettings
{
    public const OPTION = 'kalaka_deposits';

    /** The stored amount of an event as decimal text, or null when it has no deposit. */
    public function of(int $eventId): ?string
    {
        return $this->all()[$eventId] ?? null;
    }

    /**
     * PHP turns the numeric string keys of the stored map into integers.
     *
     * @return array<int, string> event ID => decimal text, only the events that have a deposit
     */
    public function all(): array
    {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            return [];
        }

        $all = [];
        foreach ($stored as $eventId => $amount) {
            if (is_int($eventId) && is_string($amount)) {
                $all[$eventId] = $amount;
            }
        }

        return $all;
    }

    /**
     * @param string $amount a non-negative decimal text with at most two decimals, e.g. "10000" or "10000.50"
     * @throws \InvalidArgumentException when the amount is not that
     */
    public function set(int $eventId, string $amount): void
    {
        self::assertAmount($amount);

        $all = $this->all();
        $all[$eventId] = $amount;
        update_option(self::OPTION, $all, false);
    }

    /** Takes the event's deposit away; an event without one stays as it is. */
    public function off(int $eventId): void
    {
        $all = $this->all();
        if (!isset($all[$eventId])) {
            return;
        }

        unset($all[$eventId]);
        if ($all === []) {
            delete_option(self::OPTION);

            return;
        }
        update_option(self::OPTION, $all, false);
    }

    /** @throws \InvalidArgumentException */
    private static function assertAmount(string $amount): void
    {
        if (preg_match('/\A\d+(\.\d+)?\z/', $amount) !== 1) {
            throw new \InvalidArgumentException('The amount must be a non-negative number: ' . $amount);
        }
        if (preg_match('/\.\d{3,}\z/', $amount) === 1) {
            throw new \InvalidArgumentException('The amount has at most two decimals: ' . $amount);
        }
    }
}
