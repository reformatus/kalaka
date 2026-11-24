<?php

declare(strict_types=1);

namespace Kalaka\Events;

use Kalaka\Events\Api\AccountBlockMissing;
use Kalaka\Events\Api\AccountMode;
use Kalaka\Events\Api\EventState;
use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Time\Instant;
use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;

/**
 * Replaces the whole editable record: name, capacity, the four core points and the custom points,
 * each a local wall time `YYYY-MM-DD HH:MM` in the site time zone, stored as a UTC instant.
 * The state, the latch and the archive time are never touched here.
 *
 * The payment and account settings (`account_mode`, `payment_due_days`, `transfer_beneficiary`,
 * `transfer_account`, `transfer_reference_prefix`) are optional keys: one that is absent stays as it is, one
 * given replaces its setting, and `null` or an empty text clears it (the mode has no null: `off`). The texts
 * are kept as entered, trimmed. Changing the account mode to one with accounts is refused while a published
 * registration form of the event lacks the account block (events-and-forms.md, "Forms implementation"); the
 * forms are read before the run and passed in (`Api\Events::update()`), as a use case reads no post.
 */
final class UpdateEvent implements AdminUseCase
{
    private const LOCAL_TIME = '^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}$';

    private const TEXT_LENGTHS = [
        'transfer_beneficiary' => 191,
        'transfer_account' => 64,
        'transfer_reference_prefix' => 32,
    ];

    /**
     * @param list<int>|null $formsWithoutAccountBlock the published registration forms of the event that lack the
     *     account block, read before the run for the requested account mode (`Api\Events::update()`); null is a
     *     caller that did not read them, which only matters when the mode changes to one with accounts
     */
    public function __construct(
        private readonly EventRepository $repository = new EventRepository(),
        private readonly ?array $formsWithoutAccountBlock = null,
    ) {
    }

    public static function name(): string
    {
        return 'kalaka/update-event';
    }

    public function inputSchema(): array
    {
        $at = ['type' => ['string', 'null'], 'pattern' => self::LOCAL_TIME];

        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'minimum' => 1],
                'version' => ['type' => 'integer', 'minimum' => 1],
                'name' => ['type' => 'string'],
                'capacity' => ['type' => ['integer', 'null'], 'minimum' => 0, 'maximum' => 50000],
                'registration_opens_at' => $at,
                'registration_closes_at' => $at,
                'starts_at' => $at,
                'ends_at' => $at,
                'account_mode' => ['type' => 'string', 'enum' => array_column(AccountMode::cases(), 'value')],
                'payment_due_days' => ['type' => ['integer', 'null'], 'minimum' => 0, 'maximum' => 65535],
                'transfer_beneficiary' => ['type' => ['string', 'null']],
                'transfer_account' => ['type' => ['string', 'null']],
                'transfer_reference_prefix' => ['type' => ['string', 'null']],
                'points' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer', 'minimum' => 1],
                            'name' => ['type' => 'string'],
                            'at' => ['type' => 'string', 'pattern' => self::LOCAL_TIME],
                        ],
                        'required' => ['name', 'at'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => [
                'id',
                'version',
                'name',
                'capacity',
                'registration_opens_at',
                'registration_closes_at',
                'starts_at',
                'ends_at',
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
        $opens = self::at($input['registration_opens_at']);
        $closes = self::at($input['registration_closes_at']);
        $starts = self::at($input['starts_at']);
        $ends = self::at($input['ends_at']);
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

        $settings = $this->settings($input, $record);

        $record->name = $name;
        $record->capacity = $capacity;
        $record->registrationOpensAt = $opens;
        $record->registrationClosesAt = $closes;
        $record->startsAt = $starts;
        $record->endsAt = $ends;
        $record->points = $points;
        $record->nextPointId = $nextPointId;
        foreach ($settings as $field => $value) {
            $record->{$field} = $value;
        }
        $record->version = (int) $input['version'];
        $this->repository->upsert($record, $context);

        return null;
    }

    /**
     * The payment and account settings the input gives, by the record's property: what is absent is left out.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     * @throws InvalidInput when a text is longer than its column
     * @throws AccountBlockMissing when the mode would leave a published registration form without its account block
     */
    private function settings(array $input, EventRecord $record): array
    {
        $settings = [];
        if (array_key_exists('payment_due_days', $input)) {
            $settings['paymentDueDays'] = $input['payment_due_days'] === null ? null : (int) $input['payment_due_days'];
        }
        $properties = [
            'transfer_beneficiary' => 'transferBeneficiary',
            'transfer_account' => 'transferAccount',
            'transfer_reference_prefix' => 'transferReferencePrefix',
        ];
        foreach (self::TEXT_LENGTHS as $key => $limit) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $text = $input[$key] === null ? '' : trim((string) $input[$key]);
            if (mb_strlen($text, 'UTF-8') > $limit) {
                throw new InvalidInput();
            }
            $settings[$properties[$key]] = $text === '' ? null : $text;
        }
        if (array_key_exists('account_mode', $input)) {
            $mode = AccountMode::from((string) $input['account_mode']);
            if ($mode !== $record->accountMode && $mode !== AccountMode::Off) {
                $this->requireAccountBlocks();
            }
            $settings['accountMode'] = $mode;
        }

        return $settings;
    }

    /** @throws RuleViolation when a published registration form lacks the account block */
    private function requireAccountBlocks(): void
    {
        if ($this->formsWithoutAccountBlock === null) {
            $error = new SystemFailure(null, new \LogicException('The forms of the event were not read first.'));
            throw $error;
        }
        if ($this->formsWithoutAccountBlock !== []) {
            $error = new AccountBlockMissing($this->formsWithoutAccountBlock);
            throw $error;
        }
    }

    /**
     * The custom points as they will be stored: an item with a stored id keeps it, one without gets
     * the next id, a stored point left out is dropped.
     *
     * @return array{list<array{id: int, name: string, at: \DateTimeImmutable}>, int}
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
            $at = self::at($item['at']);
            if ($length < 1 || $length > 191 || isset($names[$key]) || $at === null) {
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
            $points[] = ['id' => $id, 'name' => $name, 'at' => $at];
        }
        usort($points, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        return [$points, $next];
    }

    /** @throws InvalidInput when it is not an existing local time in the site time zone */
    private static function at(mixed $value): ?\DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        return Instant::fromLocal((string) $value) ?? throw new InvalidInput();
    }
}
