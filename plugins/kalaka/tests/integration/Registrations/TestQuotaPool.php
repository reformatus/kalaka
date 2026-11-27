<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Registrations;

use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\UseCase\LockLevel;
use Kalaka\Registrations\Api\SeatPool;
use Kalaka\Tests\Integration\Foundation\TestItemRepository;

/**
 * A pool at the quota lock level over a row of a test table, read `FOR UPDATE` through the repository, so the
 * runner's lock guard sees it as it sees a price tier's or an offer's row. The limit is the test's.
 */
final class TestQuotaPool implements SeatPool
{
    private readonly TestItemRepository $rows;

    public function __construct(
        private readonly int $event,
        private readonly string $key,
        private readonly string $table,
        private readonly int $id,
        private readonly ?int $limit,
    ) {
        $this->rows = new TestItemRepository($table, LockLevel::Quota);
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

    public function lockRow(): array
    {
        return [$this->table, [$this->id]];
    }

    public function limit(): ?int
    {
        return $this->limit;
    }

    public function lock(Context $context): ?int
    {
        $this->rows->findForUpdate($this->id, $context) ?? throw new NotFound();

        return $this->limit;
    }
}
