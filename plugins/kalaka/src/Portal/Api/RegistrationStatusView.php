<?php

declare(strict_types=1);

namespace Kalaka\Portal\Api;

use Kalaka\Events\Api\Phase;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\RegistrationStatus;

/**
 * What the participant blocks show for one registration (Backend seam SB-1..SB-13). A read: building it
 * writes nothing and evaluates nothing. It never carries the secret or the cookie's value.
 *
 * `confirmation` and `payment` are set only while the status is `registered`; `transfer` only when the
 * transfer block may show (SB-4: registered, the event open, not held, a participant balance above zero
 * after rounding, and the event's bank details set).
 */
final readonly class RegistrationStatusView
{
    public function __construct(
        public RegistrationKey $key,
        public Availability $availability,
        public string $eventName,
        public RegistrationStatus $status,
        public Phase $phase,
        public ?ConfirmationView $confirmation,
        public ?PaymentView $payment,
        public ?TransferDetails $transfer,
    ) {
    }
}
