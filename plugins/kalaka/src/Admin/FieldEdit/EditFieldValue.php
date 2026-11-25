<?php

declare(strict_types=1);

namespace Kalaka\Admin\FieldEdit;

use Kalaka\Admin\Api\Access;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\PermissionDenied;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\UseCase\PreviewableUseCase;
use Kalaka\Registrations\Api\FieldCorrections;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * Staff correct one field value of one registration, with a reason (events-and-forms.md, "Changing participant
 * fields after submit"; foundation.md, "Views and grants"). A field change: it carries no action, so a grant holds
 * it when the registration is inside its view, and the fine check inside `execute` needs the field's column
 * read-write (`Access::mayWrite()`); full access passes both. The value arrives already in its stored form
 * (`PreparedEdit`, made by `FieldEditInput::prepare()` outside the run).
 *
 * Not a submission: nothing is added to the submission list; the latest write wins and every write is audited.
 * The seats follow the value through the contributed claim sources (`Registrations\Api\FieldCorrections`): the old
 * value's seat is freed and the new one's taken by staff placing, which may go over the limit. The runner then
 * evaluates the registration (decisions, money, confirmation, mail); the edit sends no mail of its own.
 * With `preview` the act does everything but the write and the seat moves: the right, the conflict and the unchanged
 * value are checked as in the real run, and it returns what the real run would report, so a caller can warn before
 * it asks. The runner writes no audit entry for a preview that succeeded. [UNAPPROVED N534]
 * [UNAPPROVED N160, N161, N165, N167, N168, N169]
 *
 * Lock order: the pools of the seats to take (quota level), then the registration, then the value.
 */
final class EditFieldValue implements AdminUseCase, PreviewableUseCase
{
    public function __construct(
        private readonly PreparedEdit $edit,
        private readonly FieldCorrections $corrections = new FieldCorrections(),
        private readonly Access $access = new Access(),
    ) {
    }

    public static function name(): string
    {
        return 'kalaka/edit-field-value';
    }

    /**
     * `value` is the answer in the shape the field type takes (null clears), `expected` the stored value the
     * screen showed (null: none; leave it out to skip the check), `reason` 1 to 500 characters.
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'event_id' => ['type' => 'integer', 'minimum' => 1],
                'code' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 16],
                'field' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
                'value' => ['type' => ['string', 'number', 'boolean', 'array', 'null']],
                'expected' => ['type' => ['string', 'null']],
                'reason' => ['type' => 'string', 'minLength' => 1, 'maxLength' => FieldEditInput::REASON_MAX],
                'preview' => ['type' => 'boolean'],
            ],
            'required' => ['event_id', 'code', 'field', 'reason'],
            'additionalProperties' => false,
        ];
    }

    public function isPreview(array $input): bool
    {
        return ($input['preview'] ?? false) === true;
    }

    /** A field change: no action; the grant's view and the column decide. */
    public function action(): ?string
    {
        return null;
    }

    public function event(array $input): int
    {
        return (int) $input['event_id'];
    }

    public function targets(array $input): array
    {
        return [new RegistrationKey((int) $input['event_id'], (string) $input['code'])];
    }

    /**
     * @return array{changed: bool, over_limit: list<string>} `over_limit`: the fields whose pool was full when the
     *     seat was taken (with `preview`, would be); `changed`: whether the value differs (with `preview`, would)
     */
    public function execute(array $input, Context $context): array
    {
        $key = new RegistrationKey((int) $input['event_id'], (string) $input['code']);
        $field = (string) $input['field'];
        $edit = $this->edit;
        if ($edit->eventId !== $key->eventId || $edit->field->key !== $field) {
            $error = new SystemFailure(null, new \LogicException('The prepared value is for another field.'));
            throw $error;
        }
        $new = $edit->stored;

        // The plan is a plain read; the pools it takes seats in are locked before the registration is.
        $plan = $this->corrections->plan($key, $field, $new);
        $this->corrections->lockPools($plan, $context);
        $status = $this->corrections->lock($key, $context) ?? throw new NotFound();
        if (!$this->access->mayWrite($context->actor(), $key, 'field:' . $field, $context)) {
            $error = new PermissionDenied();
            throw $error;
        }

        // Another write that slipped in between the plan and the lock, or a stored value other than the one the
        // caller saw, is a conflict; an identical value is nothing to do.
        $expected = $input['expected'] ?? null;
        $unchanged = $this->corrections->check(
            $context,
            $key,
            $plan,
            $field,
            $new,
            array_key_exists('expected', $input),
            is_string($expected) ? $expected : null
        );
        if ($unchanged) {
            return ['changed' => false, 'over_limit' => []];
        }
        if ($this->isPreview($input)) {
            return [
                'changed' => true,
                'over_limit' => $this->corrections->wouldOverfill($plan, $key, $status, $context),
            ];
        }

        $this->corrections->write($key, $field, $new, $context);
        $over = $this->corrections->move($plan, $key, $status, $context);

        return ['changed' => true, 'over_limit' => $over];
    }
}
