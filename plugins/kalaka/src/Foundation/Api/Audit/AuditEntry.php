<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Audit;

use Kalaka\Foundation\Api\Actor\Actor;
use Kalaka\Foundation\Api\Actor\Side;
use Kalaka\Foundation\Api\Error\SystemFailure;

/**
 * The entry one run collects in memory; the runner writes it before the commit (decision D11).
 *
 * @internal
 */
final class AuditEntry
{
    /** @var array<string, Subject> */
    private array $subjects = [];

    /** @var array<string, array{subject: Subject, field: string, before: string, after: string, value: array{mixed, mixed}, secret: bool}> */
    private array $changes = [];

    /** @var list<AuditEntry> */
    private array $children = [];

    /**
     * @param ?string $error the JSON of a failed entry; null for a succeeded one
     * @param list<Subject> $declared
     */
    public function __construct(
        public readonly string $action,
        public readonly Side $side,
        public readonly Actor $actor,
        public readonly \DateTimeImmutable $occurredAt,
        public readonly ?string $reason,
        public readonly ?string $error,
        array $declared,
    ) {
        foreach ($declared as $subject) {
            $this->touch($subject);
        }
    }

    public function failed(): bool
    {
        return $this->error !== null;
    }

    public function touch(Subject $subject): void
    {
        $this->subjects[self::identity($subject)] ??= $subject;
    }

    /**
     * Records a changed field of a subject. One item per subject and field keeps the first `before`
     * and the last `after`.
     *
     * @throws SystemFailure when a value is not JSON-encodable
     */
    public function change(Subject $subject, string $field, mixed $before, mixed $after, bool $secret): void
    {
        $this->touch($subject);
        $beforeJson = self::encode($before);
        $afterJson = self::encode($after);

        $key = self::identity($subject) . "\0" . $field;
        if (isset($this->changes[$key])) {
            $this->changes[$key]['after'] = $afterJson;
            $this->changes[$key]['value'][1] = $after;
            $this->changes[$key]['secret'] = $this->changes[$key]['secret'] || $secret;

            return;
        }
        $this->changes[$key] = [
            'subject' => $subject,
            'field' => $field,
            'before' => $beforeJson,
            'after' => $afterJson,
            'value' => [$before, $after],
            'secret' => $secret,
        ];
    }

    public function addChild(self $child): void
    {
        $this->children[] = $child;
    }

    /** @return list<AuditEntry> */
    public function children(): array
    {
        return $this->children;
    }

    /** @return list<Subject> the declared ones first, then the recorded ones, each once */
    public function subjects(): array
    {
        return array_values($this->subjects);
    }

    /**
     * The changes as they are stored (decision D5); null on a failed entry.
     *
     * @return ?list<array<string, mixed>>
     */
    public function changeItems(): ?array
    {
        if ($this->failed()) {
            return null;
        }

        $items = [];
        foreach ($this->changes as $change) {
            if ($change['before'] === $change['after']) {
                continue;
            }
            $item = [
                'type' => $change['subject']->type,
                'event' => $change['subject']->eventId,
                'key' => $change['subject']->key,
                'field' => $change['field'],
            ];
            if ($change['secret']) {
                $item['masked'] = true;
            } else {
                $item['before'] = $change['value'][0];
                $item['after'] = $change['value'][1];
            }
            $items[] = $item;
        }

        return $items;
    }

    private static function identity(Subject $subject): string
    {
        return $subject->type . "\0" . ($subject->eventId ?? '') . "\0" . $subject->key;
    }

    private static function encode(mixed $value): string
    {
        $json = wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $cause = new \InvalidArgumentException('A changed value is not JSON-encodable.');
            $error = new SystemFailure(null, $cause);
            throw $error;
        }

        return $json;
    }
}
