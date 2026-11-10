<?php

declare(strict_types=1);

namespace Kalaka\Mail\Catalogue;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Repository\EntityRepository;

/**
 * The only writer of `kalaka_mail_layouts`: one row per event that has a layout.
 *
 * @extends EntityRepository<int, MailLayout>
 */
final class MailLayoutRepository extends EntityRepository
{
    public static function subjectOf(int $eventId): Subject
    {
        return new Subject('mail_layout', $eventId, (string) $eventId);
    }

    protected function table(): string
    {
        return 'kalaka_mail_layouts';
    }

    protected function keyColumns(): array
    {
        return ['event_id'];
    }

    protected function autoIncrement(): bool
    {
        return false;
    }

    protected function keyToColumns(mixed $key): array
    {
        return ['event_id' => (int) $key];
    }

    protected function toRow(object $entity): array
    {
        return [
            'event_id' => $entity->eventId,
            'version' => $entity->version,
            'layout_id' => $entity->layoutId,
        ];
    }

    protected function fromRow(array $row): object
    {
        return new MailLayout((int) $row['event_id'], (int) $row['layout_id'], (int) $row['version']);
    }

    protected function subject(object $record): Subject
    {
        return self::subjectOf($record->eventId);
    }
}
