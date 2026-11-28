<?php

declare(strict_types=1);

namespace Kalaka\Mail\Api;

/**
 * A pause, as the dashboard shows it: why, who and since when, and after errors the delivery whose second error
 * paused sending, with its error as stored (full access only reads it, #240 N3).
 */
final readonly class PauseInfo
{
    /**
     * @param string $cause `manual` or `errors`
     * @param ?int $byUserId the user who paused by hand; null for the system or WP-CLI without a user
     * @param ?int $deliveryId after errors: the delivery whose error paused sending
     * @param ?int $deliveryEventId that delivery's event
     * @param ?string $error that delivery's error, as stored
     */
    public function __construct(
        public string $cause,
        public ?int $byUserId,
        public \DateTimeImmutable $since,
        public ?int $deliveryId = null,
        public ?int $deliveryEventId = null,
        public ?string $error = null,
    ) {
    }
}
