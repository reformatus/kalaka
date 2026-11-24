<?php

declare(strict_types=1);

namespace Kalaka\Payments\Api;

use Kalaka\Foundation\Api\Actor\SecretLinkActor;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Mail\Api\MailRecipients;
use Kalaka\Payments\StartCardPayment;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * Starts a card payment for a registration the caller has the access to (the active registration): runs
 * `kalaka-portal/start-card-payment`, then asks the provider for the pay URL after the run has committed.
 * Providers are an outside point, so they never run inside the transaction (modules-and-extensions.md, "The
 * extension surface").
 */
final class CardPayments
{
    public function __construct(
        private readonly PaymentProviders $providers = new PaymentProviders(),
        private readonly MailRecipients $recipients = new MailRecipients(),
    ) {
    }

    /**
     * @param ?string $returnUrl where to come back to; used only when it is on this site
     * @return string the address to send the registrant to
     * @throws CardPaymentUnavailable when nothing is payable now
     * @throws NoPaymentProvider when no provider accepts the event's currency
     * @throws SystemFailure when the provider fails
     */
    public function start(RegistrationKey $key, ?string $returnUrl = null): string
    {
        $provider = $this->providers->active();
        $started = Runner::current()->run(
            new StartCardPayment(
                $key,
                $provider,
                $this->recipients->of($key),
                (string) (wp_get_referer() ?: home_url('/'))
            ),
            $returnUrl === null ? [] : ['return_url' => $returnUrl],
            new SecretLinkActor($key)
        );
        if (!$started instanceof StartedCardPayment || $provider === null) {
            $error = new SystemFailure(null, new \LogicException('The start use case returned no request.'));
            throw $error;
        }

        try {
            return $provider->payUrl($started->request, $started->returnUrl);
        } catch (\Throwable $e) {
            $error = new SystemFailure(null, $e);
            throw $error;
        }
    }
}
