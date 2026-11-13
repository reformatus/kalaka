<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

use Kalaka\Events\Api\Event;
use Kalaka\Events\Api\EventState;
use Kalaka\Forms\AnswerValidator;
use Kalaka\Forms\DefinitionParser;
use Kalaka\Forms\FormPosts;
use Kalaka\Forms\ParticipantField\ParticipantFieldQuery;
use Kalaka\Forms\ParticipantField\SharedKeyCheck;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Module\Contributions;

/**
 * Reads forms and checks answers against them. It reads WordPress posts, so it runs before or
 * after a use case's transaction, never inside it: a submit reads the form and its definition
 * first and passes them in.
 */
final class Forms
{
    public function __construct(
        private readonly FormPosts $posts = new FormPosts(),
        private readonly DefinitionParser $parser = new DefinitionParser(),
        private readonly AnswerValidator $validator = new AnswerValidator(),
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
        return $this->posts->form($id);
    }

    /**
     * The definition of the form's current version, or of an earlier revision of the same form. The current
     * version must also agree with the event's participant fields (a key of another type, or a `system`
     * field's key, makes it unusable): a form written around the save check stays closed and is named in
     * the broken-form warning. An earlier version is read as it was. [UNAPPROVED N3]
     *
     * @throws SystemFailure when the version is not a revision of the form or the definition has a problem
     */
    public function definition(Form $form, ?int $version = null): FormDefinition
    {
        $version ??= $form->version;
        $content = $this->posts->content($form, $version);
        $definition = $this->parser->parse($form, $version, $content, Contributions::current());
        if ($version === $form->version) {
            $problem = SharedKeyCheck::problem($content, $form->eventId, Contributions::current());
            if ($problem !== null) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a problem code and a key, never output
                throw new SystemFailure($problem[0] . ' ' . $problem[1]);
            }
        }

        return $definition;
    }

    /**
     * What keeps the form from being used, in this order: the event is not open (returned
     * alone), the registration slot's date window, the password, the required role. Pure: the
     * caller passes the moment, the user's roles and whether the password was given.
     *
     * @param list<string> $roles
     * @return list<AccessRefusal>
     */
    public function refusals(
        Form $form,
        Event $event,
        \DateTimeImmutable $now,
        array $roles,
        bool $passwordGiven
    ): array {
        if ($event->state() !== EventState::Published) {
            return [AccessRefusal::EventNotOpen];
        }

        $refusals = [];
        if ($form->placement === FormPlacement::Registration && !$event->registrationWindowContains($now)) {
            $refusals[] = AccessRefusal::OutsideRegistrationWindow;
        }
        if ($form->hasPassword && !$passwordGiven) {
            $refusals[] = AccessRefusal::PasswordRequired;
        }
        if ($form->requiredRole !== null && !in_array($form->requiredRole, $roles, true)) {
            $refusals[] = AccessRefusal::RoleRequired;
        }

        return $refusals;
    }

    /**
     * The event's participant fields, in the order they were declared; retired ones only when asked.
     * It reads Kaláka's own table, so it may run anywhere. [UNAPPROVED N1]
     *
     * @return list<ParticipantField>
     */
    public function participantFields(int $eventId, bool $includeRetired = false): array
    {
        $fields = [];
        foreach ((new ParticipantFieldQuery())->forEvent($eventId) as $record) {
            if ($record->retiredAt !== null && !$includeRetired) {
                continue;
            }
            $options = [];
            foreach ($record->options ?? [] as $option) {
                $options[] = [
                    'value' => $option['value'],
                    'label' => $option['label'],
                    'retired' => $option['retired_at'] !== null,
                ];
            }
            $fields[] = new ParticipantField(
                $record->key,
                $record->type,
                $record->title,
                $options,
                $record->source,
                $record->retiredAt !== null,
            );
        }

        return $fields;
    }

    /**
     * @param array<array-key, mixed> $answers field key → the answer as sent
     * @throws InvalidInput an undeclared key or a value of the wrong JSON type
     * @throws InvalidAnswers missing or wrong answers
     */
    public function validate(FormDefinition $definition, array $answers): ValidatedAnswers
    {
        return $this->validator->validate($definition, $answers, Contributions::current());
    }
}
