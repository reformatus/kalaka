<?php

declare(strict_types=1);

namespace Kalaka\Assignments;

use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\UseCase\LockLevel;
use Kalaka\Registrations\Api\SeatPool;

/**
 * The places of one set option under the one seat rule: the pool row is the option's own row, its limit the
 * option's capacity (none: no limit). A confirmed assignment holds a seat; a proposed one holds none until
 * staff confirm it. Its key is `option.<set ID>.<option key>`.
 */
final class OptionSeatPool implements SeatPool
{
    public function __construct(
        private readonly int $eventId,
        private readonly int $setId,
        private readonly string $optionKey,
        private readonly OptionRepository $options = new OptionRepository(),
    ) {
    }

    public function eventId(): int
    {
        return $this->eventId;
    }

    public function key(): string
    {
        return 'option.' . $this->setId . '.' . $this->optionKey;
    }

    public function lockLevel(): LockLevel
    {
        return LockLevel::Other;
    }

    public function lockRow(): array
    {
        return ['kalaka_assignment_options', [$this->setId, $this->optionKey]];
    }

    public function label(): string
    {
        return ($this->options->find($this->row()) ?? throw new NotFound())->label;
    }

    public function limit(): ?int
    {
        return ($this->options->find($this->row()) ?? throw new NotFound())->limit;
    }

    public function lock(Context $context): ?int
    {
        return ($this->options->findForUpdate($this->row(), $context) ?? throw new NotFound())->limit;
    }

    /** @return array{setId: int, key: string} */
    private function row(): array
    {
        return ['setId' => $this->setId, 'key' => $this->optionKey];
    }
}
