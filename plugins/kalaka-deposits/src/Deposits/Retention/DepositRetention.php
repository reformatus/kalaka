<?php

declare(strict_types=1);

namespace Kalaka\Deposits\Retention;

use Kalaka\Deposits\DepositSettings;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Ledger\Api\Payer;
use Kalaka\Pricing\Api\RetentionInput;
use Kalaka\Pricing\Api\RetentionRule;

/**
 * The retention rule `deposit` (money.md, "Withdrawal, removal and substitution"): of what a leaving registration
 * paid, the event keeps up to its deposit, the amount `wp kalaka deposit` stores for the event. The cap is shared
 * by the payers: the participant payer is counted first, then the others in the order of the paid map, and
 * each payer keeps the smaller of its own payments and what is left of the deposit. A payer that keeps nothing,
 * or paid nothing positive, is left out.
 *
 * An event that has this rule but no deposit amount keeps nothing, so everything paid shows as an overpayment
 * (the same as `nothing`). That is the rule's default; it does not fall back to everything paid.
 */
final class DepositRetention implements RetentionRule
{
    public const ID = 'deposit';

    public function __construct(private readonly DepositSettings $settings = new DepositSettings())
    {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function kept(RetentionInput $input): array
    {
        $text = $this->settings->of($input->facts->registration->eventId);
        if ($text === null) {
            return [];
        }

        return self::cap($input->paid, Money::of($text, $input->currency));
    }

    /**
     * Shares the deposit between the payers' payments: the participant first, then the others in key order.
     *
     * @param array<string, Money> $paid payer → net payments; zero or less is skipped
     * @return array<string, Money> payer → the part of the deposit it keeps, only the payers that keep something
     */
    public static function cap(array $paid, Money $deposit): array
    {
        $order = array_keys($paid);
        if (isset($paid[Payer::PARTICIPANT])) {
            $order = [Payer::PARTICIPANT, ...array_diff($order, [Payer::PARTICIPANT])];
        }

        $left = $deposit;
        $kept = [];
        foreach ($order as $payer) {
            if (!$left->isPositive()) {
                break;
            }
            $payment = $paid[$payer];
            if (!$payment->isPositive()) {
                continue;
            }
            $part = $payment->isLessThan($left) ? $payment : $left;
            $kept[$payer] = $part;
            $left = $left->minus($part);
        }

        return $kept;
    }
}
