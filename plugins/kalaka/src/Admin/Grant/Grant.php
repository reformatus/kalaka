<?php

declare(strict_types=1);

namespace Kalaka\Admin\Grant;

use Kalaka\Admin\View\ViewShape;
use Kalaka\Foundation\Api\Error\SystemFailure;

/**
 * The stored grant: a user's access to one event through one of its views (null: the event's master
 * view), with the value bound to the view's parameter and the actions the grant carries. Its event never changes.
 */
final class Grant
{
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /** @param list<string> $actions catalogue names, without duplicates, in byte order */
    public function __construct(
        public readonly int $eventId,
        public int $userId,
        public ?int $viewId,
        public ?string $boundValue,
        public array $actions,
        public readonly int $id = 0,
        public int $version = 0,
    ) {
    }

    /** @param array<string, ?string> $row a `kalaka_grants` row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['event_id'],
            (int) $row['user_id'],
            isset($row['view_id']) ? (int) $row['view_id'] : null,
            $row['bound_value'] ?? null,
            self::actionsFromStored((string) $row['actions']),
            (int) $row['id'],
            (int) $row['version'],
        );
    }

    /**
     * @return list<string>
     * @throws SystemFailure when the stored JSON is not a list of strings
     */
    public static function actionsFromStored(string $json): array
    {
        $actions = json_decode($json, true);
        if (!is_array($actions) || !array_is_list($actions)) {
            $error = new SystemFailure(null, new \UnexpectedValueException('A stored grant is malformed.'));
            throw $error;
        }
        foreach ($actions as $action) {
            if (!is_string($action)) {
                $error = new SystemFailure(null, new \UnexpectedValueException('A stored grant is malformed.'));
                throw $error;
            }
        }

        return $actions;
    }

    public function actionsJson(): string
    {
        $json = wp_json_encode($this->actions, self::JSON_FLAGS);
        if ($json === false) {
            $error = new SystemFailure(null, new \LogicException('A grant\'s actions are not JSON-encodable.'));
            throw $error;
        }

        return $json;
    }

    /**
     * The view of a grant row from `GrantQuery::forUser()`: null for the master view, false when its view
     * is missing (the grant then holds nothing).
     *
     * @param array{view_id: ?int, filter: ?string, columns: ?string} $grant
     */
    public static function viewShape(array $grant): ViewShape|false|null
    {
        if ($grant['view_id'] === null) {
            return null;
        }
        if ($grant['filter'] === null || $grant['columns'] === null) {
            return false;
        }

        return ViewShape::fromStored($grant['filter'], $grant['columns']);
    }
}
