<?php

declare(strict_types=1);

namespace Kalaka\Payments;

use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\UseCase\PortalUseCase;
use Kalaka\Ledger\Api\Payer;
use Kalaka\Payments\Api\CardPaymentUnavailable;
use Kalaka\Payments\Api\NoPaymentProvider;
use Kalaka\Payments\Api\PaymentProvider;
use Kalaka\Payments\Api\PaymentRequest;
use Kalaka\Payments\Api\RequestLine;
use Kalaka\Payments\Api\StartedCardPayment;
use Kalaka\Portal\Api\StatusRead;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\Registrations;

/**
 * Starts a card payment for the active registration (money.md, "Payments"): under the registration's lock it
 * checks again that something is payable, by the one rule of the transfer block (`StatusRead::payableNow()`), and
 * that a provider accepts the event's currency, and builds the one-line request for the participant's due,
 * rounded up. It writes nothing. The provider is called after the run, outside the transaction
 * (`Api\CardPayments`), never here.
 */
final class StartCardPayment implements PortalUseCase
{
    /**
     * @param ?string $email the registration's contact email, read before the run
     * @param string $fallbackUrl where the registrant returns to when the input names no same-site address
     */
    public function __construct(
        private readonly RegistrationKey $key,
        private readonly ?PaymentProvider $provider,
        private readonly ?string $email,
        private readonly string $fallbackUrl,
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
                'return_url' => ['type' => 'string', 'maxLength' => 2000],
            ],
            'additionalProperties' => false,
        ];
    }

    public function registrations(array $input): array
    {
        return [$this->key];
    }

    /**
     * @param array<string, mixed> $input
     * @throws CardPaymentUnavailable when nothing is payable now
     * @throws NoPaymentProvider when no provider accepts the event's currency
     */
    public function execute(array $input, Context $context): StartedCardPayment
    {
        if (!$this->registrations->lock($this->key, $context)) {
            throw new CardPaymentUnavailable();
        }
        $amount = $this->status->payableNow($this->key);
        if ($amount === null) {
            throw new CardPaymentUnavailable();
        }
        $event = $this->events->find($this->key->eventId);
        if ($event === null) {
            throw new NotFound();
        }
        if ($this->provider === null || !$this->provider->accepts($amount->currency())) {
            throw new NoPaymentProvider();
        }

        $returnUrl = isset($input['return_url']) ? wp_validate_redirect((string) $input['return_url'], '') : '';

        return new StartedCardPayment(
            new PaymentRequest(
                [
                    new RequestLine(
                        $this->key,
                        Payer::PARTICIPANT,
                        $amount,
                        // [UNAPPROVED T80] the order item's name; "{event name}: befizetés ({code})"
                        $event->name . ': befizetés (' . $this->key->code . ')'
                    ),
                ],
                $amount->currency(),
                $this->email
            ),
            $returnUrl === '' ? $this->fallbackUrl : $returnUrl
        );
    }
}
