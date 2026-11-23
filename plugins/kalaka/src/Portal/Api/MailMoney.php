<?php

declare(strict_types=1);

namespace Kalaka\Portal\Api;

use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Foundation\Api\Money\MoneyText;
use Kalaka\Ledger\Api\Ledger;
use Kalaka\Ledger\Api\Payer;
use Kalaka\Portal\MailMarkup;
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

    /**
     * The money table in mail markup; empty when the registration or its event does not exist, and wherever the
     * page shows no money table (`RegistrationStatusView::showsMoney()`, the page's own rule: a waitlisted
     * registration has none).
     */
    public function table(RegistrationKey $key): string
    {
        $view = $this->status->of($key);

        return $view === null || !$view->showsMoney() ? '' : MailMarkup::money($view->money);
    }

    /** The transfer block in mail markup; empty exactly where the page's transfer block renders nothing (SB-4). */
    public function transfer(RegistrationKey $key): string
    {
        $transfer = $this->status->of($key)?->transfer;

        return $transfer === null ? '' : MailMarkup::transfer($transfer);
    }

    /**
     * The movement a payment or a refund line of the participant is, as the table lists it, with its method's ID;
     * null for any other line, a correction or another payer's line included (a correction sends no "payment
     * recorded" mail, so it reads as none).
     */
    public function movement(RegistrationKey $key, int $lineId): ?MailMovement
    {
        foreach ($this->ledger->entriesOf($key) as $entry) {
            if ($entry->id !== $lineId) {
                continue;
            }
            if (!$entry->kind->isPaymentFact() || $entry->correction || $entry->payer !== Payer::PARTICIPANT) {
                return null;
            }
            $row = MoneyRead::movement($entry);

            return new MailMovement($row, $entry->kind->carriesMethod() ? $entry->method?->value : 'online');
        }

        return null;
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
