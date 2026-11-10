<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Entry;

/**
 * A WP-CLI command's own refusal or stop: a bad argument, an unreadable file, a run that did not
 * start. The English message is printed after "Error: " as it is, and nothing is logged.
 */
final class CommandFailure extends \RuntimeException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
