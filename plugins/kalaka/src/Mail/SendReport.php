<?php

declare(strict_types=1);

namespace Kalaka\Mail;

/**
 * What one sender run did: the mails sent, the attempts that ended in an error (at most one: the run
 * stops there), the deliveries marked failed for a missing or invalid address, whether it paused, and the
 * deliveries marked failed for a body that cannot render (`BodyUnusable`).
 */
final readonly class SendReport
{
    public function __construct(
        public int $sent = 0,
        public int $errors = 0,
        public int $invalidAddresses = 0,
        public bool $paused = false,
        public int $unusableBodies = 0,
    ) {
    }
}
