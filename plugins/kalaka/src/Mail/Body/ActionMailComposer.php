<?php

declare(strict_types=1);

namespace Kalaka\Mail\Body;

use Kalaka\Mail\Api\ActionMails;
use Kalaka\Mail\Delivery;
use Kalaka\Mail\MailComposer;
use Kalaka\Mail\MailMessage;

/**
 * The composer bound into the sender: the recipient is the registration's contact email, the mail is
 * rendered by `MailRenderer` (mail.md, "Sending": rendered at send time from current data). A secret link
 * in the mail is only ever in the message; the sender stores the version, never the content.
 */
final class ActionMailComposer implements MailComposer
{
    public function __construct(
        private readonly MailRenderer $renderer = new MailRenderer(),
        private readonly MailValues $values = new MailValues(),
    ) {
    }

    public function recipient(Delivery $delivery): ?string
    {
        return $this->values->recipient($delivery->registration);
    }

    public function compose(Delivery $delivery, string $to, \DateTimeImmutable $now): MailMessage
    {
        // A "payment recorded" mail is caused by a ledger line: its key is the line's ID.
        $line = $delivery->causeType === ActionMails::LINE_CAUSE && ctype_digit($delivery->causeKey)
            ? (int) $delivery->causeKey
            : null;
        $mail = $this->renderer->render(
            $delivery->registration->eventId,
            $delivery->mail,
            $delivery->registration,
            $now,
            $line
        );

        return new MailMessage($mail->subject, $mail->html, ['Content-Type: text/html; charset=UTF-8'], $mail->version);
    }
}
