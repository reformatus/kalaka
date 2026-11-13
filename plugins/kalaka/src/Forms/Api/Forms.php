<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

use Kalaka\Events\Api\Event;
use Kalaka\Events\Api\EventState;
use Kalaka\Forms\AnswerValidator;
use Kalaka\Forms\DefinitionParser;
use Kalaka\Forms\FormPosts;
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
     * The definition of the form's current version, or of an earlier revision of the same form.
     *
     * @throws SystemFailure when the version is not a revision of the form or the definition has a problem
     */
    public function definition(Form $form, ?int $version = null): FormDefinition
    {
        $version ??= $form->version;

        return $this->parser->parse($form, $version, $this->posts->content($form, $version), Contributions::current());
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
     * @param array<array-key, mixed> $answers field key → the answer as sent
     * @throws InvalidInput an undeclared key or a value of the wrong JSON type
     * @throws InvalidAnswers missing or wrong answers
     */
    public function validate(FormDefinition $definition, array $answers): ValidatedAnswers
    {
        return $this->validator->validate($definition, $answers, Contributions::current());
    }
}
