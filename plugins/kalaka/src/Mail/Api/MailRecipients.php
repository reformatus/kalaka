<?php

declare(strict_types=1);

namespace Kalaka\Mail\Api;

use Kalaka\Mail\Body\MailValues;
use Kalaka\Mail\RecipientUnavailable;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * Who a registration's mail goes to: its contact email, read as the sender reads it. It reads WordPress posts,
 * so it runs outside a use case.
 */
final class MailRecipients
{
    public function __construct(private readonly MailValues $values = new MailValues())
    {
    }

    /** The registration's contact email; null when it has none or cannot be read now. */
    public function of(RegistrationKey $key): ?string
    {
        try {
            return $this->values->recipient($key);
        } catch (RecipientUnavailable) {
            return null;
        }
    }
}
