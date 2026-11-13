<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Mail;

use Kalaka\Mail\Delivery;
use Kalaka\Mail\MailComposer;
use Kalaka\Mail\MailMessage;

/** A composer of the test's choosing: every delivery goes to one address unless the test maps it elsewhere. */
final class FakeComposer implements MailComposer
{
    /** @var array<int, ?string> addresses by delivery id */
    public array $addresses = [];

    /** @var list<int> the deliveries composed, in order */
    public array $composed = [];

    /** @var ?\Closure(Delivery): void runs when the address is asked for */
    public ?\Closure $onRecipient = null;

    /** @var ?\Throwable thrown by compose() */
    public ?\Throwable $failure = null;

    public function recipient(Delivery $delivery): ?string
    {
        if ($this->onRecipient !== null) {
            ($this->onRecipient)($delivery);
        }

        return array_key_exists($delivery->id, $this->addresses)
            ? $this->addresses[$delivery->id]
            : 'teszt@example.test';
    }

    public function compose(Delivery $delivery, string $to): MailMessage
    {
        $this->composed[] = $delivery->id;
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return new MailMessage('Teszt tárgy', '<p>Teszt</p>', [], 'v1');
    }
}
