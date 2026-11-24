<?php

declare(strict_types=1);

namespace Kalaka\Mail\Body;

use Kalaka\Mail\Api\MailKind;
use Kalaka\Mail\Delivery;
use Kalaka\Mail\MailComposer;
use Kalaka\Mail\MailMessage;

/**
 * The composer bound into the sender: it composes a manual delivery from its send batch
 * (`ManualMailComposer`) and every other delivery as an action mail (`ActionMailComposer`). The recipient is
 * the same for both, the registration's contact email.
 */
final class KindComposer implements MailComposer
{
    public function __construct(
        private readonly ActionMailComposer $action = new ActionMailComposer(),
        private readonly ManualMailComposer $manual = new ManualMailComposer(),
    ) {
    }

    public function recipient(Delivery $delivery): ?string
    {
        return $this->action->recipient($delivery);
    }

    public function compose(Delivery $delivery, string $to): MailMessage
    {
        return $delivery->kind === MailKind::Manual
            ? $this->manual->compose($delivery)
            : $this->action->compose($delivery, $to);
    }
}
