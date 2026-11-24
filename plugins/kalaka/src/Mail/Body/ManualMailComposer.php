<?php

declare(strict_types=1);

namespace Kalaka\Mail\Body;

use Kalaka\Mail\BodyUnusable;
use Kalaka\Mail\Delivery;
use Kalaka\Mail\MailMessage;
use Kalaka\Mail\Manual\MailBatch;
use Kalaka\Mail\Manual\MailBatchQuery;

/**
 * Composes a manual delivery (mail.md, "Five kinds of mail"): the cause of the delivery is its send batch, and
 * the batch names the body and whether the layout wraps it. The body is rendered at send time from current
 * data, by `MailRenderer::renderBody()`, as an action mail's is. A batch that is gone is the delivery's own
 * problem (`no_batch`), failed without an attempt and never counted toward pausing.
 */
final class ManualMailComposer
{
    public function __construct(
        private readonly MailRenderer $renderer = new MailRenderer(),
        private readonly MailBatchQuery $batches = new MailBatchQuery(),
    ) {
    }

    /**
     * @throws BodyUnusable no such batch (`no_batch <id>`), or the body's own problem (`MailRenderer`)
     */
    public function compose(Delivery $delivery): MailMessage
    {
        $registration = $delivery->registration;
        $id = $delivery->causeType === MailBatch::CAUSE ? (int) $delivery->causeKey : 0;
        $batch = $id > 0 ? $this->batches->find($registration->eventId, $id) : null;
        if ($batch === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a problem code and an ID, never output
            throw new BodyUnusable('no_batch ' . $delivery->causeKey);
        }
        $mail = $this->renderer->renderBody($registration->eventId, $batch->bodyId, $batch->layout, $registration);

        return new MailMessage($mail->subject, $mail->html, ['Content-Type: text/html; charset=UTF-8'], $mail->version);
    }
}
