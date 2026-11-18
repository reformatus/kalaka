<?php

declare(strict_types=1);

namespace Kalaka\Admin\View;

use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\ParticipantFields\Api\ParticipantFields;
use Kalaka\Registrations\Api\RegistrationStatus;

/**
 * Sets which members the master view's sum row counts in one column (S37): `status`, or a participant
 * field with options. A site-level use case: only full access. The counts are stored in the event's master
 * row of `kalaka_views` (`master` 1), written through `ViewRepository` and audited with it; the row is
 * inserted on the first save, and the unique key on `master_event` keeps it the only one. An empty list
 * returns the column to its default. The last save wins: the picker sends no version.
 *
 * The field's options are read before the run (`prepared()`), as `ParticipantFields::of()` reads outside a
 * transaction.
 */
final class SaveMasterCounts implements AdminUseCase
{
    /** @param ?array<string, list<string>> $options the option values by field key; null: none read */
    public function __construct(
        private readonly Events $events = new Events(),
        private readonly ViewRepository $repository = new ViewRepository(),
        private readonly ViewList $list = new ViewList(),
        private readonly ?array $options = null,
    ) {
    }

    /**
     * The use case with the options of the event's participant fields read now, outside any run.
     *
     * @param array<string, mixed> $input
     */
    public static function prepared(array $input): self
    {
        $options = [];
        $eventId = is_int($input['event_id'] ?? null) ? $input['event_id'] : 0;
        if ($eventId > 0) {
            foreach ((new ParticipantFields())->of($eventId, true) as $field) {
                $options[$field->key] = array_map(
                    static fn (array $option): string => $option['value'],
                    $field->options
                );
            }
        }

        return new self(options: $options);
    }

    public static function name(): string
    {
        return 'kalaka/save-master-counts';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'event_id' => ['type' => 'integer', 'minimum' => 1],
                'column' => ['type' => 'string'],
                'members' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['event_id', 'column', 'members'],
            'additionalProperties' => false,
        ];
    }

    public function action(): ?string
    {
        return null;
    }

    public function event(array $input): ?int
    {
        return null;
    }

    public function targets(array $input): array
    {
        return [];
    }

    public function execute(array $input, Context $context): null
    {
        $eventId = (int) $input['event_id'];
        $column = (string) $input['column'];
        $members = $this->members($column, $input['members']);
        if ($this->events->find($eventId) === null) {
            throw new NotFound();
        }

        $stored = $this->list->master($eventId);
        $view = $stored === null ? null : $this->repository->findForUpdate($stored['id'], $context);
        if ($view === null) {
            if ($members !== []) {
                $this->repository->upsert(View::newMaster($eventId, [$column => $members]), $context);
            }

            return null;
        }
        if (($view->shape->counts[$column] ?? []) === $members) {
            return null;
        }
        $view->shape = $view->shape->withCounts($column, $members);
        $this->repository->upsert($view, $context);

        return null;
    }

    /**
     * @return list<string> each once, in the order given
     * @throws InvalidInput when the column cannot carry counts or a value is not one of its members
     */
    private function members(string $column, mixed $members): array
    {
        if ($column === 'status') {
            $valid = array_map(static fn (RegistrationStatus $s): string => $s->value, RegistrationStatus::cases());
        } elseif (str_starts_with($column, 'field:')) {
            $valid = $this->options[substr($column, 6)] ?? [];
        } else {
            $valid = [];
        }
        if ($valid === [] || !is_array($members)) {
            throw new InvalidInput();
        }

        $list = [];
        foreach ($members as $member) {
            if (!is_string($member) || !in_array($member, $valid, true)) {
                throw new InvalidInput();
            }
            $list[$member] = $member;
        }

        return array_values($list);
    }
}
