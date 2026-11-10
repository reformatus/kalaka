<?php

declare(strict_types=1);

namespace Kalaka\Mail\Pause;

/** Why sending is paused: mail.md, "Pausing". */
enum PauseCause: string
{
    /** A person paused it. */
    case Manual = 'manual';

    /** The sender paused it after two errors in a row. */
    case Errors = 'errors';
}
