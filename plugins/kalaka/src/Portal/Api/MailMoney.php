<?php

declare(strict_types=1);

namespace Kalaka\Portal\Api;

use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Ledger\Api\Ledger;
use Kalaka\Ledger\Api\PaymentMethod;
use Kalaka\Portal\MailMarkup;
use Kalaka\Portal\MoneyText;
use Kalaka\Portal\MoneyWords;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * What a mail reads of the participant's money (mail.md, "Content"): the money table and the transfer block as
 * mail markup, and the words of one money movement, all through the same read as the page (`StatusRead`,
 * `MoneyRead`) at send time, so the mail and the page agree. Plain reads: nothing here writes, and no access
 * rule applies (the mail goes to the registration's own contact).
 */
final class MailMoney
{
    public function __construct(
        private readonly StatusRead $status = new StatusRead(),
        private readonly Ledger $ledger = new Ledger(),
    ) {
    }

    /** The money table in mail markup; empty when the registration or its event does not exist. */
    public function table(RegistrationKey $key): string
    {
        $view = $this->status->of($key);

        return $view === null ? '' : MailMarkup::money($view->money);
    }

    /** The transfer block in mail markup; empty exactly where the page's transfer block renders nothing (SB-4). */
    public function transfer(RegistrationKey $key): string
    {
        $transfer = $this->status->of($key)?->transfer;

        return $transfer === null ? '' : MailMarkup::transfer($transfer);
    }

    /** The movement a paid-side ledger line of the registration is, as the table lists it; null for any other line. */
    public function movement(RegistrationKey $key, int $lineId): ?MovementRow
    {
        foreach ($this->ledger->entriesOf($key) as $entry) {
            if ($entry->id === $lineId) {
                return $entry->kind->isPaymentFact() ? MoneyRead::movement($entry) : null;
            }
        }

        return null;
    }

    /**
     * The stable ID of a movement's method for a mail's condition: the manual method's own ID, or `online` for an
     * online payment or refund; null for a movement with no method.
     */
    public static function methodId(MovementRow $movement): ?string
    {
        if ($movement->methodLabel === null) {
            return null;
        }
        foreach (PaymentMethod::cases() as $method) {
            if ($method->label() === $movement->methodLabel) {
                return $method->value;
            }
        }

        return 'online';
    }

    /** An amount as the page shows it: `48 000 Ft`. */
    public static function amountText(Money $amount): string
    {
        return MoneyText::of($amount);
    }

    /** A movement's type word as the table shows it: „Befizetés”, „Visszafizetés” or „Korrekció”. */
    public static function typeWord(MovementType $type): string
    {
        return MoneyWords::typeOf($type);
    }
}
