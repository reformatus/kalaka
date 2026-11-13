<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

use Kalaka\Foundation\Api\Audit\Correlation;
use Kalaka\Foundation\Api\Error\KalakaException;

/**
 * A submit whose answers show a terminal page (MP-6): the person could not have reached the submit
 * button, so only a crafted or stale request gets here. Its own code, `kalaka_form_terminal`; the
 * text is `FormRefused`'s (#161 N3), so no new text. Not audited: nothing was attempted.
 */
final class FormTerminal extends KalakaException
{
    protected function defaultMessage(): string
    {
        return 'Ez az űrlap most nem küldhető be.';
    }

    public function errorCode(): string
    {
        return 'kalaka_form_terminal';
    }

    public function status(): int
    {
        return 403;
    }

    public function isAudited(): bool
    {
        return false;
    }

    public function toWpError(): \WP_Error
    {
        return new \WP_Error($this->errorCode(), $this->getMessage(), [
            'status' => $this->status(),
            'correlation' => Correlation::id(),
        ]);
    }
}
