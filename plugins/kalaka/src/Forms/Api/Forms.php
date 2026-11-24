<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

use Kalaka\Events\Api\Event;
use Kalaka\Forms\Engine\FormEngine;
use Kalaka\Forms\Engine\FormPosts;
use Kalaka\Forms\EventFormHost;
use Kalaka\Forms\ParticipantField\FieldBlocks;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\ParticipantFields\Api\ParticipantField;
use Kalaka\ParticipantFields\Api\ParticipantFields;
use Kalaka\Registrations\Api\RegistrationFacts;

/**
 * Reads forms and checks answers against them. It reads WordPress posts, so it runs before or
 * after a use case's transaction, never inside it: a submit reads the form and its definition
 * first and passes them in. `participantValues()` is the one exception: it reads no post.
 */
final class Forms
{
    public function __construct(
        private readonly FormEngine $engine = new FormEngine(new EventFormHost()),
        private readonly FormPosts $posts = new FormPosts(),
    ) {
    }

    /**
     * The published form with that ID, or null for anything else: another post type, a form that
     * is not published or whose event or placement setting is missing or invalid.
     *
     * @throws SystemFailure when the form has no revision
     */
    public function form(int $id): ?Form
    {
        return $this->engine->form($id);
    }

    /**
     * The definition of the form's current version, or of an earlier revision of the same form. The current
     * version must also agree with the event's participant fields (a key of another type, or a `system`
     * field's key, makes it unusable): a form written around the save check stays closed and is named in
     * the broken-form warning. An earlier version is read as it was.
     *
     * A form being served to a visitor (`$serving`: the page, the re-fetch, the submit and the broken-form
     * scan) with an account step (the event's current account mode) must also hold the account block
     * (`account_block_missing`), and every field an extension requires of the event's registration form
     * (`required_field_missing`: an event with a price ladder needs `price_tier`). A stored submission's
     * definition (a mail's recipient and context) is read without those checks, so a mode or ladder change never
     * costs an existing registration its mail.
     *
     * @throws SystemFailure when the version is not a revision of the form or the definition has a problem
     */
    public function definition(Form $form, ?int $version = null, bool $serving = false): FormDefinition
    {
        return $this->engine->definition($form, $version, $serving);
    }

    /**
     * The definition of the version a form page was built from, which must be the form's latest version
     * (S76 follow-up, N8): a re-fetch and a submit accept nothing else.
     *
     * @throws FormOutdated when the version is not the latest
     * @throws SystemFailure when the definition has a problem
     */
    public function latestDefinition(Form $form, int $version): FormDefinition
    {
        return $this->engine->latestDefinition($form, $version);
    }

    /**
     * The parsed blocks of the form's version with every branch block marked with its chain number and
     * branch index (`Engine\Condition\ChainMarks`, the numbering the definition reads): what a form page renders,
     * block by block with `render_block()`, outside `Values::render()`. A top-level page renders as
     * `<div data-kalaka-page="N" [data-kalaka-terminal] hidden>…</div>` (`PageMarkup`); the after-submit
     * section is left out.
     *
     * An answer-driven chain ships every branch, each as `<div data-kalaka-chain="N" data-kalaka-branch="i"
     * hidden>…</div>` (`BranchMarkup`); the page shows the one `FormAvailability::$chains` holds. A
     * withheld chain (`FormDefinition::withheldChains()`: it reads an event value or a held fact) ships
     * only the branch the availability holds, without `hidden`, and nothing of the others.
     *
     * @param FormAvailability|null $availability what shows for the page's answers; without it no branch
     *     of a withheld chain ships
     * @return list<array<string, mixed>>
     * @throws SystemFailure when the version is not a revision of the form, or a branch is misplaced
     */
    public function blocks(Form $form, ?int $version = null, ?FormAvailability $availability = null): array
    {
        return $this->engine->blocks($form, $version, $availability);
    }

    /**
     * The after-submit section of the form's version (`AfterSubmit`, N12) rendered with the context
     * (`AfterSubmit::context()`): its inner blocks through `Values::render()`, conditions resolved, values
     * filled. Null when the form has none; the caller then shows its default text.
     *
     * @throws SystemFailure when the version is not a revision of the form, or a key the context lacks
     */
    public function afterSubmit(Form $form, int $version, ValueContext $context): ?string
    {
        return $this->engine->afterSubmit($form, $version, $context);
    }

    /**
     * What keeps the form from being used, in this order: the event is not open (returned
     * alone), the registration slot's date window, the password, the required role, an account
     * (`EventFormHost::refusalsOf()`). Pure: the caller passes the moment, the user's roles,
     * whether the password was given and whether someone is logged in.
     *
     * @param list<string> $roles
     * @return list<AccessRefusal>
     */
    public function refusals(
        Form $form,
        Event $event,
        \DateTimeImmutable $now,
        array $roles,
        bool $passwordGiven,
        bool $loggedIn
    ): array {
        return EventFormHost::refusalsOf($form, $event, $now, $roles, $passwordGiven, $loggedIn);
    }

    /**
     * The event's participant fields (`ParticipantFields::of()`), each with the forms of the event that hold
     * it now (`ParticipantField::$forms`). It reads Kaláka's own table and the event's form posts, so it runs
     * outside a use case's transaction.
     *
     * @return list<ParticipantField>
     */
    public function participantFields(int $eventId, bool $includeRetired = false): array
    {
        $holders = [];
        foreach ($this->posts->ofEvent($eventId) as $post) {
            foreach (array_keys(FieldBlocks::of($post->post_content, Contributions::current())) as $key) {
                $holders[$key][] = ['form_id' => $post->ID, 'title' => $post->post_title];
            }
        }

        return array_map(
            static fn (ParticipantField $field): ParticipantField => $field->withForms($holders[$field->key] ?? []),
            (new ParticipantFields())->of($eventId, $includeRetired)
        );
    }

    /**
     * The values of one registration's stored answers over the event's participant fields (a document's value
     * context): one value for every field of the event, retired ones included, so a key that only another
     * form holds reads as `Value::none()` of its type and never fails. A field with no stored answer, a
     * `system` field (until decisions can be read here) and a field of a type no longer contributed read as
     * none too. A stored answer of a key the event does not have is left out.
     *
     * Unlike the rest of this class it reads no post, only `ParticipantFields::of()` (its table and the block
     * registry), and each stored answer is typed by its field type's `value()`, which fires no hook and writes
     * nothing; so the evaluation calls it inside the transaction (`Registrations::factsOf()`).
     *
     * @param array<string, string> $stored field key → the stored value
     * @return array<string, Value> field key → value
     */
    public function participantValues(int $eventId, array $stored): array
    {
        return $this->participantFacts($eventId, $stored)->values;
    }

    /**
     * `participantValues()` with each field's kind beside its value (N19): the block type and the number
     * field's decimals a money rule needs to tell a consent from a quantity. One walk, one read of
     * `ParticipantFields::of()`, the same exception as `participantValues()`.
     *
     * @param array<string, string> $stored field key → the stored value
     */
    public function participantFacts(int $eventId, array $stored): ParticipantFacts
    {
        $values = [];
        $kinds = [];
        foreach ($this->typedFields($eventId) as [$field, $type]) {
            $kinds[$field->key] = new FieldKind($field->type, (int) ($field->settings['decimals'] ?? 0));
            if ($type === null) {
                $values[$field->key] = Value::none(ValueType::Text);
                continue;
            }
            $answer = $field->source === ParticipantField::SOURCE_SYSTEM ? null : ($stored[$field->key] ?? null);
            $values[$field->key] = $answer === null
                ? Value::none($type->valueType())
                : $type->value(new FieldTypeInput($answer, self::attributesOf($field, $answer)));
        }

        return new ParticipantFacts($values, $kinds);
    }

    /**
     * The keys of the event's participant fields whose value is a path (`PathFieldType`, the congregation
     * field), retired ones included: a condition reads them with `contains`, never `equals`.
     *
     * @return list<string>
     */
    public function pathKeys(int $eventId): array
    {
        $keys = [];
        foreach ($this->typedFields($eventId) as [$field, $type]) {
            if ($type instanceof PathFieldType) {
                $keys[] = $field->key;
            }
        }

        return $keys;
    }

    /**
     * The keys of the event's participant fields an additional form's condition may read from the registration's
     * stored values, with their value types: not a `system` field (a decision, not a stored answer), not a field
     * of a type no longer contributed, and not a path (`pathKeys()`: read with `contains` against the field's own
     * ancestors, which only a form's own field offers). Retired ones are read too, since their values are stored.
     *
     * @return array<string, ValueType>
     */
    public function storedTypes(int $eventId): array
    {
        $types = [];
        foreach ($this->typedFields($eventId) as [$field, $type]) {
            $readable = $type !== null && $field->source !== ParticipantField::SOURCE_SYSTEM;
            if ($readable && !$type instanceof PathFieldType) {
                $types[$field->key] = $type->valueType();
            }
        }

        return $types;
    }

    /**
     * The event's participant fields, retired ones included, each with its contributed type (null when no
     * type is contributed for it any more).
     *
     * @return list<array{ParticipantField, FieldType|null}>
     */
    private function typedFields(int $eventId): array
    {
        $types = Contributions::current()->fieldTypes();
        $typed = [];
        foreach ((new ParticipantFields())->of($eventId, true) as $field) {
            $typed[] = [$field, $types[$field->type] ?? null];
        }

        return $typed;
    }

    /**
     * The block attributes a field type reads a stored answer with, from the participant field: its own
     * settings (the number field's bounds, decimals and affixes), the label, the options, and for a choice
     * whether it is multiple, which its stored form shows (a JSON list).
     *
     * @return array<string, mixed>
     */
    private static function attributesOf(ParticipantField $field, string $answer): array
    {
        $attributes = $field->settings;
        $attributes['label'] = $field->title;
        if ($field->options !== []) {
            $attributes['options'] = array_map(
                static fn (array $option): array => ['value' => $option['value'], 'label' => $option['label']],
                $field->options
            );
            $attributes['multiple'] = str_starts_with($answer, '[');
        }

        return $attributes;
    }

    /**
     * The facts a submit's walk reads for the form, built the same way as its page's and re-fetch's
     * (`FormHost::facts()`) but from what the caller read: the account state, the moment, and for an additional
     * form the registration's facts (one read, so the use case can check the same read under its
     * lock). Reads the database, so it runs before a use case's transaction.
     *
     * @param RegistrationFacts|null $registration the registration's facts, as `Registrations::factsOf()` built
     *     them; null for none
     */
    public function submitFacts(
        Form $form,
        Event $event,
        ?bool $loggedIn,
        \DateTimeImmutable $now,
        ?RegistrationFacts $registration
    ): ValueContext {
        return (new EventFormHost())->factsFor($form, $event, $loggedIn, $now, $registration);
    }

    /**
     * The submit's check of the answers, with the same walk as `availability()`.
     * Decision N5 (#156): the facts are optional, so the signature of #53 stays.
     *
     * @param array<array-key, mixed> $answers field key → the answer as sent
     * @param Facts|null $facts the facts besides the answers: the event's keys (`EventValues::of()`) for a
     *     form whose conditions read them; when null, only the runner's moment (`EventValues::now()`), so
     *     every condition scope carries `now`
     * @throws InvalidInput an undeclared key or a value of the wrong JSON type
     * @throws InvalidAnswers missing or wrong answers
     * @throws FormTerminal a terminal page shows for the answers
     * @throws SystemFailure a key the facts do not declare
     */
    public function validate(FormDefinition $definition, array $answers, ?Facts $facts = null): ValidatedAnswers
    {
        return $this->engine->validate(
            $definition,
            $answers,
            $facts ?? new ValueContext(EventValues::now(Runner::current()->clock()->now()))
        );
    }

    /**
     * What the form shows for answers as they stand (a draft, "if submitted now"; S76): the read model
     * every render and re-render of the form's page builds from. Pure; errors in the answers are not
     * reported here, an answer in error reads as empty.
     *
     * @param array<array-key, mixed> $answers field key → the answer as sent
     * @param Facts $facts the facts besides the answers: the event's keys (`EventValues::of()`)
     * @param int|null $page the page whose errors to report (Tovább): the walk's
     *     errors of that page's shown fields; null reports none
     * @throws InvalidInput an undeclared key or a value of the wrong JSON type, a page out of range
     * @throws SystemFailure a key the facts do not declare
     */
    public function availability(
        FormDefinition $definition,
        array $answers,
        Facts $facts,
        ?int $page = null
    ): FormAvailability {
        return $this->engine->availability($definition, $answers, $facts, $page);
    }
}
