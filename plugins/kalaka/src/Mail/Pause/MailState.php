<?php

declare(strict_types=1);

namespace Kalaka\Mail\Pause;

use Kalaka\Foundation\Api\Time\Instant;

/**
 * The sender's one state row: the pause, the errors in a row and the hourly budget. Without a stored row
 * the state is the default one: not paused, no errors, the default budget. mail.md, "Pausing", "Failures"
 * and "Sending".
 * // [UNAPPROVED S8-3] the pause lives in a Mail-owned one-row table.
 */
final class MailState
{
    public const ID = 1;

    /** mail.md, "Sending": today's 20 per 5-minute run. */
    public const DEFAULT_HOURLY_BUDGET = 240;

    /**
     * @param ?\DateTimeImmutable $pausedAt null while sending runs
     * @param ?int $pausedBy the user who paused; null for the system or a WP-CLI run without a user
     * @param ?int $deliveryId after errors: the delivery whose second error paused sending
     * @param ?string $error after errors: that delivery's error, masked as on the delivery
     * @param int $errorsInRow attempts that ended in an error since the last successful one
     */
    public function __construct(
        public ?\DateTimeImmutable $pausedAt = null,
        public ?PauseCause $cause = null,
        public ?int $pausedBy = null,
        public ?int $deliveryId = null,
        public ?int $deliveryEventId = null,
        public ?string $error = null,
        public int $errorsInRow = 0,
        public int $hourlyBudget = self::DEFAULT_HOURLY_BUDGET,
        public int $version = 0,
    ) {
    }

    public function isPaused(): bool
    {
        return $this->pausedAt !== null;
    }

    public function pause(\DateTimeImmutable $at, PauseCause $cause, ?int $by): void
    {
        $this->pausedAt = $at;
        $this->cause = $cause;
        $this->pausedBy = $by;
        $this->deliveryId = null;
        $this->deliveryEventId = null;
        $this->error = null;
    }

    /** Clears the pause; the errors in a row stay (mail.md, "Failures"). */
    public function resume(): void
    {
        $this->pausedAt = null;
        $this->cause = null;
        $this->pausedBy = null;
        $this->deliveryId = null;
        $this->deliveryEventId = null;
        $this->error = null;
    }

    /** @param array<string, ?string> $row a `kalaka_mail_states` row */
    public static function fromRow(array $row): self
    {
        return new self(
            $row['paused_at'] === null ? null : Instant::fromStorage($row['paused_at']),
            $row['cause'] === null ? null : PauseCause::tryFrom($row['cause']),
            $row['paused_by'] === null ? null : (int) $row['paused_by'],
            $row['delivery_id'] === null ? null : (int) $row['delivery_id'],
            $row['delivery_event_id'] === null ? null : (int) $row['delivery_event_id'],
            $row['error'],
            (int) $row['errors_in_row'],
            max(1, (int) $row['hourly_budget']),
            (int) $row['version'],
        );
    }
}
