<?php

declare(strict_types=1);

namespace Kalaka\Payments\Api;

use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Actor\SecretLinkActor;
use Kalaka\Foundation\Api\Database\NamedLock;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Mail\Api\MailRecipients;
use Kalaka\Payments\StartCardPayment;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * Starts a card payment for a registration the caller has the access to (the active registration): runs
 * `kalaka-portal/start-card-payment`, then asks the provider for the pay URL after the run has committed.
 * Providers are an outside point, so every call to one (`latestOf()`, `accepts()`, `payUrl()`) is made here,
 * outside the transaction, never inside the use case (modules-and-extensions.md, "The extension surface").
 *
 * One start per registration at a time: a named lock per registration is held from the provider's read of the
 * latest request until the pay URL is made. [UNAPPROVED N339] It is taken without waiting: two clicks at once
 * cannot make two orders, because the second start cannot get the lock and is refused as in flight at once; a
 * later click that gets the lock replaces the never-paid request, which the provider cancels.
 */
final class CardPayments
{
    public function __construct(
        private readonly PaymentProviders $providers = new PaymentProviders(),
        private readonly MailRecipients $recipients = new MailRecipients(),
        private readonly PaymentsInFlight $inFlight = new PaymentsInFlight(),
        private readonly Events $events = new Events(),
    ) {
    }

    /**
     * @param ?string $returnUrl where to come back to; used only when it is on this site
     * @param ?string $amount a decimal text, between the limits of `CardAmountLimits`; null asks for the whole
     *                        payable amount
     * @return string the address to send the registrant to
     * @throws CardPaymentUnavailable when nothing is payable now
     * @throws CardPaymentInFlight when the latest request is in flight or another start of it is running
     * @throws InvalidInput when the amount is malformed or no multiple of the payable unit
     * @throws CardAmountRefused when the amount is below the minimum or above what is payable now
     * @throws NoPaymentProvider when no provider accepts the event's currency
     * @throws SystemFailure when the provider fails
     */
    public function start(RegistrationKey $key, ?string $returnUrl = null, ?string $amount = null): string
    {
        $lock = new NamedLock('card_' . $key->eventId . '_' . strtolower($key->code));
        if (!$lock->acquire()) {
            throw new CardPaymentInFlight();
        }
        try {
            return $this->startLocked($key, $returnUrl, $amount);
        } finally {
            $lock->release();
        }
    }

    private function startLocked(RegistrationKey $key, ?string $returnUrl, ?string $amount): string
    {
        $provider = $this->providers->active();
        $inFlight = false;
        $event = $provider === null ? null : $this->events->find($key->eventId);
        if ($provider !== null && $event !== null) {
            try {
                $latest = $provider->latestOf(PaymentProviderInput::forLookup($key, $event->currency));
                $inFlight = $this->inFlight->is($provider, $key, $latest);
            } catch (\Throwable $e) {
                $error = new SystemFailure(null, $e);
                throw $error;
            }
        }
        $started = Runner::current()->run(
            new StartCardPayment(
                $this->recipients->of($key),
                (string) (wp_get_referer() ?: home_url('/')),
                $inFlight
            ),
            [
                'event_id' => $key->eventId,
                'code' => $key->code,
                ...($returnUrl === null ? [] : ['return_url' => $returnUrl]),
                ...($amount === null ? [] : ['amount' => $amount]),
            ],
            new SecretLinkActor($key)
        );
        if (!$started instanceof StartedCardPayment) {
            $error = new SystemFailure(null, new \LogicException('The start use case returned no request.'));
            throw $error;
        }
        $input = PaymentProviderInput::forStart($started->request, $started->returnUrl);
        try {
            if ($provider === null || !$provider->accepts($input)) {
                throw new NoPaymentProvider();
            }

            return $provider->payUrl($input);
        } catch (NoPaymentProvider $e) {
            throw $e;
        } catch (\Throwable $e) {
            $error = new SystemFailure(null, $e);
            throw $error;
        }
    }
}
