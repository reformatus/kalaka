<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Registrations;

use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\UseCase\LockLevel;
use Kalaka\Registrations\Api\SeatPool;

/**
 * The pool a test request source builds for its offer: a fixed limit over the `kalaka_seats` rows of its key,
 * its own lock a no-op (the test pool's row is not a table's). [UNAPPROVED N4]
 */
final class AnswerPool implements SeatPool
{
    public function __construct(
        private readonly int $event,
        public ?int $limit = null,
        private readonly string $key = TestRequestSource::POOL,
    ) {
    }

    public function eventId(): int
    {
        return $this->event;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function lockLevel(): LockLevel
    {
        return LockLevel::Quota;
    }

    /** @return array{0: string, 1: list<int|string>} */
    public function lockRow(): array
    {
        return ['kalaka_stand_in', [$this->key]];
    }

    public function limit(): ?int
    {
        return $this->limit;
    }

    public function lock(Context $context): ?int
    {
        return $this->limit;
    }
}
