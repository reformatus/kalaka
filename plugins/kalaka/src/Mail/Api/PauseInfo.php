<?php

declare(strict_types=1);

namespace Kalaka\Mail\Api;

/** A pause, as the dashboard shows it: why, who and since when. No delivery, no error text. */
final readonly class PauseInfo
{
    /**
     * @param string $cause `manual` or `errors`
     * @param ?int $byUserId the user who paused by hand; null for the system or WP-CLI without a user
     */
    public function __construct(
        public string $cause,
        public ?int $byUserId,
        public \DateTimeImmutable $since,
    ) {
    }
}
