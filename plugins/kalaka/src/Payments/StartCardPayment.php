<?php

declare(strict_types=1);

namespace Kalaka\Payments;

use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\UseCase\PortalUseCase;
use Kalaka\Ledger\Api\Payer;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Payments\Api\CardAmountLimits;
use Kalaka\Payments\Api\CardAmountRefused;
use Kalaka\Payments\Api\CardPaymentUnavailable;
use Kalaka\Payments\Api\CardPaymentInFlight;
use Kalaka\Payments\Api\PaymentRequest;
use Kalaka\Payments\Api\RequestLine;
use Kalaka\Payments\Api\StartedCardPayment;
use Kalaka\Portal\Api\StatusRead;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\Registrations;

/**
 * Starts a card payment for the active registration (money.md, "Payments"): under the registration's lock it
 * checks again that something is payable, by the one rule of the transfer block (`StatusRead::payableNow()`), and
 * builds the one-line request for the amount the registrant asked for: the participant's due, rounded up (`full`),
 * when the input names none, else the input's `amount`, between the limits of `CardAmountLimits`. It writes
 * nothing and calls no provider: a provider is an outside point, so `Api\CardPayments` asks it (whether it accepts
 * the currency, the state of the latest request, the pay URL) outside the transaction and hands the use case what
 * it read.
 */
final class StartCardPayment implements PortalUseCase
{
    /**
     * @param ?string $email the registration's contact email, read before the run
     * @param string $fallbackUrl where the registrant returns to when the input names no same-site address
     * @param bool $inFlight whether the provider reported the registration's latest request in flight
     *                       (processing, or paid and not yet recorded), read
     *                       before the run
     */
    public function __construct(
        private readonly ?string $email,
        private readonly string $fallbackUrl,
        private readonly bool $inFlight = false,
        private readonly Registrations $registrations = new Registrations(),
        private readonly Events $events = new Events(),
        private readonly StatusRead $status = new StatusRead(),
    ) {
    }

    public static function name(): string
    {
        return 'kalaka-portal/start-card-payment';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'event_id' => ['type' => 'integer', 'minimum' => 1],
                'code' => ['type' => 'string', 'pattern' => '^[0-9]{2}[AKBEGJTZFLRSXI][0-9]{1,13}\\z'],
                'return_url' => ['type' => 'string', 'maxLength' => 2000],
                // [UNAPPROVED N810] a non-negative decimal text in the event's currency; absent is the full amount
                'amount' => ['type' => 'string', 'pattern' => '^[0-9]{1,18}(\\.[0-9]{1,8})?\\z'],
            ],
            'required' => ['event_id', 'code'],
            'additionalProperties' => false,
        ];
    }

    public function registrations(array $input): array
    {
        return [self::keyOf($input)];
    }

    /** @param array<string, mixed> $input */
    private static function keyOf(array $input): RegistrationKey
    {
        return new RegistrationKey((int) $input['event_id'], (string) $input['code']);
    }

    /**
     * @param array<string, mixed> $input
     * @throws CardPaymentUnavailable when nothing is payable now
     * @throws CardPaymentInFlight when the latest request is in flight at the provider
     * @throws InvalidInput when the amount is no multiple of the payable unit
     * @throws CardAmountRefused when the amount is below the minimum or above what is payable now
     */
    public function execute(array $input, Context $context): StartedCardPayment
    {
        $key = self::keyOf($input);
        if (!$this->registrations->lock($key, $context)) {
            throw new CardPaymentUnavailable();
        }
        $amount = $this->status->payableNow($key);
        if ($amount === null) {
            throw new CardPaymentUnavailable();
        }
        $event = $this->events->find($key->eventId);
        if ($event === null) {
            throw new NotFound();
        }
        if ($this->inFlight) {
            throw new CardPaymentInFlight();
        }

        $requested = $this->requestedAmount($input, $amount);

        $returnUrl = isset($input['return_url']) ? wp_validate_redirect((string) $input['return_url'], '') : '';

        return new StartedCardPayment(
            new PaymentRequest(
                [
                    new RequestLine(
                        $key,
                        Payer::PARTICIPANT,
                        $requested,
                        // [UNAPPROVED T80] the order item's name; "{event name}: befizetés ({code})"
                        $event->name . ': befizetés (' . $key->code . ')'
                    ),
                ],
                $requested->currency(),
                $this->email
            ),
            $returnUrl === '' ? $this->fallbackUrl : $returnUrl
        );
    }

    /**
     * The amount asked for: `full` when the input names none. A text that is no whole multiple of the payable unit
     * is malformed; one outside the limits is refused with them.
     *
     * @param array<string, mixed> $input
     * @param Money $full what is payable now
     * @throws InvalidInput when the amount is no multiple of the payable unit
     * @throws CardAmountRefused when the amount is below the minimum or above `full`
     */
    private function requestedAmount(array $input, Money $full): Money
    {
        if (!isset($input['amount'])) {
            return $full;
        }
        $amount = Money::of((string) $input['amount'], $full->currency());
        if (!$amount->isEqualTo($amount->roundedUpToPayableUnit())) {
            throw new InvalidInput();
        }
        $limits = CardAmountLimits::of($full);
        if ($amount->isLessThan($limits->min) || $amount->isGreaterThan($limits->max)) {
            $refused = new CardAmountRefused($limits->min, $limits->max);
            throw $refused;
        }

        return $amount;
    }
}
