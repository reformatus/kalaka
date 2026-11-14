<?php

declare(strict_types=1);

namespace Kalaka\Mail;

use Kalaka\Foundation\Api\Error\SystemFailure;

/**
 * Thrown by `MailComposer::compose()` when the mail's body cannot render for any registration, for its own
 * reason: no body assigned, a body gone, unpublished or moved to another event (possible once its mail was
 * switched off after the delivery was queued), or a key the mail does not declare, an invalid condition or a
 * misplaced branch (e.g. a participant field renamed after the body was saved). The sender renders before
 * the claim, so such a delivery is marked failed with this problem and no attempt, and it does not count
 * toward pausing: retrying cannot fix it (mail.md, "Sending").
 * // [UNAPPROVED N23] an authoring failure of a body is caught before the claim and not counted.
 */
final class BodyUnusable extends \RuntimeException
{
    /** @param string $problem `<code> <subject>`: a machine code and a key, never a value */
    public function __construct(public readonly string $problem)
    {
        parent::__construct($problem);
    }

    /** The problem of a check's failure, whose context is `<code> <subject>`. */
    public static function of(SystemFailure $failure): self
    {
        $default = (new SystemFailure())->getMessage();
        $message = $failure->getMessage();

        return new self(trim(str_starts_with($message, $default) ? substr($message, strlen($default)) : $message));
    }
}
