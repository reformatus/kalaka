<?php

declare(strict_types=1);

namespace Kalaka\Mail\Pause;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Repository\EntityRepository;
use Kalaka\Foundation\Api\Time\Instant;
use Kalaka\Foundation\Api\UseCase\Context;

/**
 * The only writer of `kalaka_mail_states`, which holds one row. Only use cases read it through here;
 * the sender's fresh read is `MailStateQuery`.
 *
 * @extends EntityRepository<int, MailState>
 */
final class MailStateRepository extends EntityRepository
{
    /** The state row read `FOR UPDATE`, or a new default state to insert when there is none yet. */
    public function forUpdate(Context $context): MailState
    {
        return $this->findForUpdate(MailState::ID, $context) ?? new MailState();
    }

    public static function subjectOf(): Subject
    {
        return new Subject('mail_state', null, (string) MailState::ID);
    }

    protected function table(): string
    {
        return 'kalaka_mail_states';
    }

    protected function keyColumns(): array
    {
        return ['id'];
    }

    protected function autoIncrement(): bool
    {
        return false;
    }

    protected function keyToColumns(mixed $key): array
    {
        return ['id' => (int) $key];
    }

    protected function toRow(object $entity): array
    {
        return [
            'id' => MailState::ID,
            'version' => $entity->version,
            'paused_at' => $entity->pausedAt === null ? null : Instant::toStorage($entity->pausedAt),
            'cause' => $entity->cause?->value,
            'paused_by' => $entity->pausedBy,
            'delivery_id' => $entity->deliveryId,
            'delivery_event_id' => $entity->deliveryEventId,
            'error' => $entity->error,
            'errors_in_row' => $entity->errorsInRow,
            'hourly_budget' => $entity->hourlyBudget,
        ];
    }

    protected function fromRow(array $row): object
    {
        return MailState::fromRow($row);
    }

    protected function subject(object $record): Subject
    {
        return self::subjectOf();
    }
}
