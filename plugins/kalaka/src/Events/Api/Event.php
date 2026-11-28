<?php

declare(strict_types=1);

namespace Kalaka\Events\Api;

use Kalaka\Foundation\Api\LoadState;
use Kalaka\Foundation\Api\Money\Currency;

/**
 * A read of an event. The points are UTC instants. The payment settings are null until set: the
 * bank-transfer beneficiary and account number as entered (SB-1), the prefix of the transfer reference
 * (SB-2) and the payment-due N in days (money.md, "Core money scope"). The account mode (S15) is `Off` until
 * set.
 */
final readonly class Event
{
    /** @param list<EventPoint> $customPoints */
    public function __construct(
        public int $id,
        public int $version,
        public string $name,
        private EventState $storedState,
        public Currency $currency,
        public ?int $capacity,
        public bool $isFull,
        public ?\DateTimeImmutable $registrationOpens,
        public ?\DateTimeImmutable $registrationCloses,
        public ?\DateTimeImmutable $start,
        public ?\DateTimeImmutable $end,
        public array $customPoints,
        public ?string $transferBeneficiary = null,
        public ?string $transferAccount = null,
        public ?string $transferReferencePrefix = null,
        public ?int $paymentDueDays = null,
        public AccountMode $accountMode = AccountMode::Off,
    ) {
    }

    /** The state as seen: a published event reads as unpublished while the site is closed. */
    public function state(): EventState
    {
        return $this->storedState === EventState::Published && !LoadState::current()->isOpen()
            ? EventState::Unpublished
            : $this->storedState;
    }

    /** Whether the event is published and `$now` is from the opening instant (included) to the closing one (excluded). */
    public function registrationWindowContains(\DateTimeImmutable $now): bool
    {
        if (
            $this->state() !== EventState::Published
            || $this->registrationOpens === null
            || $this->registrationCloses === null
        ) {
            return false;
        }

        // the window ends exactly at the closing point, with no added day
        return $this->registrationOpens <= $now && $now < $this->registrationCloses;
    }

    /**
     * Whether registration has closed: `$now` is at or after the closing instant. False for an event with no closing
     * instant. The participant's own withdrawal ends here (N661).
     */
    public function registrationHasClosed(\DateTimeImmutable $now): bool
    {
        return $this->registrationCloses !== null && $now >= $this->registrationCloses;
    }

    /**
     * Payment due, the core date type (money.md, "Core money scope"; events-and-forms.md, "Date
     * references"): N days after the later of the moment the registration last became `registered` and its
     * last answer to a request; the day ends at midnight in the site time zone. The instant returned is that
     * midnight, in UTC; null without N, without the registration's instant, and while a request awaits an
     * answer (no due date is shown then). Not stored: computed on every read. [UNAPPROVED N83]
     *
     * `RegistrationFacts::paymentDue()` passes the inputs, so every caller gets the same date: the instant the
     * registration last became `registered` (its `registered_at`, set only by submit, admission and restore, so
     * an admission from the waitlist or a restore restarts the clock); the last answer
     * (`Requests::lastAnsweredAt()`), which restarts it too when it is later (an answer given while waitlisted
     * counts only from the admission [UNAPPROVED N87]); and whether a request awaits an answer now
     * (`Requests::awaits()`).
     */
    public function paymentDue(
        ?\DateTimeImmutable $lastRegistered,
        ?\DateTimeImmutable $lastAnswered = null,
        bool $awaitsAnswer = false,
    ): ?\DateTimeImmutable {
        if ($lastRegistered === null || $this->paymentDueDays === null || $awaitsAnswer) {
            return null;
        }
        $from = $lastAnswered !== null && $lastAnswered > $lastRegistered ? $lastAnswered : $lastRegistered;
        $zone = wp_timezone();
        $day = $from->setTimezone($zone)->format('Y-m-d');
        $end = (new \DateTimeImmutable($day . ' 00:00:00', $zone))->modify('+' . ($this->paymentDueDays + 1) . ' days');

        return $end->setTimezone(new \DateTimeZone('UTC'));
    }
}
