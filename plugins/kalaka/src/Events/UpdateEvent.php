<?php

declare(strict_types=1);

namespace Kalaka\Events;

use Kalaka\Events\Api\EventState;
use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;

/**
 * Replaces the whole editable record: name, capacity, the four core points and the custom points.
 * The state, the latch and the archive time are never touched here.
 */
final class UpdateEvent implements AdminUseCase
{
    private const DAY = '^[0-9]{4}-[0-9]{2}-[0-9]{2}$';

    public function __construct(private readonly EventRepository $repository = new EventRepository())
    {
    }

    public static function name(): string
    {
        return 'kalaka/update-event';
    }

    public function inputSchema(): array
    {
        $day = ['type' => ['string', 'null'], 'pattern' => self::DAY];

        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'minimum' => 1],
                'version' => ['type' => 'integer', 'minimum' => 1],
                'name' => ['type' => 'string'],
                'capacity' => ['type' => ['integer', 'null'], 'minimum' => 0],
                'registration_opens_on' => $day,
                'registration_closes_on' => $day,
                'starts_on' => $day,
                'ends_on' => $day,
                'points' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer', 'minimum' => 1],
                            'name' => ['type' => 'string'],
                            'date' => ['type' => 'string', 'pattern' => self::DAY],
                        ],
                        'required' => ['name', 'date'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => [
                'id',
                'version',
                'name',
                'capacity',
                'registration_opens_on',
                'registration_closes_on',
                'starts_on',
                'ends_on',
                'points',
            ],
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
        $id = (int) $input['id'];
        $record = $this->repository->findForUpdate($id, $context) ?? throw new NotFound();
        $context->touch(Subject::event($id));
        if ($record->state === EventState::Archived) {
            throw new RuleViolation();
        }

        $name = trim((string) $input['name']);
        $length = mb_strlen($name, 'UTF-8');
        if ($length < 1 || $length > 191) {
            throw new InvalidInput();
        }
        $capacity = $input['capacity'] === null ? null : (int) $input['capacity'];
        $opens = self::day($input['registration_opens_on']);
        $closes = self::day($input['registration_closes_on']);
        $starts = self::day($input['starts_on']);
        $ends = self::day($input['ends_on']);
        if (
            ($opens !== null && $closes !== null && $opens > $closes)
            || ($starts !== null && $ends !== null && $starts > $ends)
        ) {
            throw new InvalidInput();
        }
        [$points, $nextPointId] = $this->customPoints($input['points'], $record);

        $required = [$capacity, $opens, $closes, $starts, $ends];
        if ($record->state !== EventState::Draft && in_array(null, $required, true)) {
            throw new RuleViolation();
        }

        $record->name = $name;
        $record->capacity = $capacity;
        $record->registrationOpensOn = $opens;
        $record->registrationClosesOn = $closes;
        $record->startsOn = $starts;
        $record->endsOn = $ends;
        $record->points = $points;
        $record->nextPointId = $nextPointId;
        $record->version = (int) $input['version'];
        $this->repository->upsert($record, $context);

        return null;
    }

    /**
     * The custom points as they will be stored: an item with a stored id keeps it, one without gets
     * the next id, a stored point left out is dropped.
     *
     * @return array{list<array{id: int, name: string, date: string}>, int}
     * @throws InvalidInput
     */
    private function customPoints(mixed $items, EventRecord $record): array
    {
        $stored = array_column($record->points, 'id');
        $next = $record->nextPointId;
        $given = [];
        $names = [];
        $points = [];
        foreach (is_array($items) ? $items : [] as $item) {
            if (!is_array($item)) {
                throw new InvalidInput();
            }
            $name = trim((string) $item['name']);
            $length = mb_strlen($name, 'UTF-8');
            $key = mb_strtolower($name, 'UTF-8');
            $date = self::day($item['date']);
            if ($length < 1 || $length > 191 || isset($names[$key]) || $date === null) {
                throw new InvalidInput();
            }
            $names[$key] = true;

            if (isset($item['id'])) {
                $id = (int) $item['id'];
                if (!in_array($id, $stored, true) || isset($given[$id])) {
                    throw new InvalidInput();
                }
                $given[$id] = true;
            } else {
                $id = $next++;
            }
            $points[] = ['id' => $id, 'name' => $name, 'date' => $date];
        }
        usort($points, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        return [$points, $next];
    }

    /** @throws InvalidInput when it is not a calendar day */
    private static function day(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $day = (string) $value;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $day, new \DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d') !== $day) {
            throw new InvalidInput();
        }

        return $day;
    }
}
