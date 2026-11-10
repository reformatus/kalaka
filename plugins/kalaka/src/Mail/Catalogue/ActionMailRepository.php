<?php

declare(strict_types=1);

namespace Kalaka\Mail\Catalogue;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Repository\EntityRepository;
use Kalaka\Mail\Api\ActionMail;

/**
 * The only writer of `kalaka_action_mails`.
 *
 * @extends EntityRepository<array{eventId: int, action: ActionMail}, ActionMailEntry>
 */
final class ActionMailRepository extends EntityRepository
{
    /** @return array<string, ActionMailEntry> one event's stored settings, by action key, in one statement */
    public function ofEvent(int $eventId): array
    {
        $entries = [];
        foreach ($this->findWhere('event_id = %d', [$eventId]) as $entry) {
            $entries[$entry->action->value] = $entry;
        }

        return $entries;
    }

    public static function subjectOf(int $eventId, ActionMail $action): Subject
    {
        return new Subject('action_mail', $eventId, $action->value);
    }

    protected function table(): string
    {
        return 'kalaka_action_mails';
    }

    protected function keyColumns(): array
    {
        return ['event_id', 'mail'];
    }

    protected function autoIncrement(): bool
    {
        return false;
    }

    protected function keyToColumns(mixed $key): array
    {
        return ['event_id' => $key['eventId'], 'mail' => $key['action']->value];
    }

    protected function toRow(object $entity): array
    {
        return [
            'event_id' => $entity->eventId,
            'mail' => $entity->action->value,
            'version' => $entity->version,
            'enabled' => $entity->enabled ? 1 : 0,
            'body_id' => $entity->bodyId,
            'layout' => $entity->layout ? 1 : 0,
        ];
    }

    protected function fromRow(array $row): object
    {
        return new ActionMailEntry(
            (int) $row['event_id'],
            ActionMail::from((string) $row['mail']),
            (int) $row['enabled'] === 1,
            $row['body_id'] === null ? null : (int) $row['body_id'],
            (int) $row['layout'] === 1,
            (int) $row['version'],
        );
    }

    protected function subject(object $record): Subject
    {
        return self::subjectOf($record->eventId, $record->action);
    }
}
