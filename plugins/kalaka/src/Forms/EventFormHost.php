<?php

declare(strict_types=1);

namespace Kalaka\Forms;

use Kalaka\Events\Api\AccountMode;
use Kalaka\Events\Api\Event;
use Kalaka\Events\Api\Events;
use Kalaka\Events\Api\EventState;
use Kalaka\Forms\Api\AccessRefusal;
use Kalaka\Forms\Api\AccountStep;
use Kalaka\Forms\Api\AfterSubmit;
use Kalaka\Forms\Api\AnswerSet;
use Kalaka\Forms\Api\DraftClaimChoice;
use Kalaka\Forms\Api\EventValues;
use Kalaka\Forms\Api\Form;
use Kalaka\Forms\Api\FormDraft;
use Kalaka\Forms\Api\FormHost;
use Kalaka\Forms\Api\FormPlacement;
use Kalaka\Forms\Api\Forms;
use Kalaka\Forms\Api\ValueContext;
use Kalaka\Forms\ParticipantField\SharedKeyCheck;
use Kalaka\Foundation\Api\Actor\Actor;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\Registrations\Api\DraftClaim;
use Kalaka\Registrations\Api\Drafts;
use Kalaka\Registrations\Api\RegistrationSubmit;
use Kalaka\Registrations\Api\SubmitRequest;
use Kalaka\Registrations\Api\SubmitToken;

/**
 * Kaláka's event form, the form engine's first host (`Api\FormHost`): a form belongs to one event
 * (`Form::$eventId`). The conditions read the event's keys (`EventValues`), the access refusals are the
 * event's (`refusalsOf()`), a field key is unique across the event's participant fields
 * (`ParticipantField\SharedKeyCheck`), a submit is a registration (`Registrations\Api\RegistrationSubmit`),
 * the after-submit section reads the event's keys and the new registration's (`AfterSubmit`), and a
 * registration form needs or offers an account by the event's account mode (`accountStepOf()`).
 */
final class EventFormHost implements FormHost
{
    public function __construct(
        private readonly Events $events = new Events(),
        private readonly ?RegistrationSubmit $registrationSubmit = null,
        private readonly Drafts $drafts = new Drafts(),
    ) {
    }

    public function factTypes(): array
    {
        return EventValues::FORM_TYPES;
    }

    /** The event's keys and `account.logged_in`: yes or no on a form with an account step, none on one without. */
    public function facts(Form $form): ?ValueContext
    {
        $event = $this->event($form);
        if ($event === null) {
            return null;
        }
        $loggedIn = self::accountStepOf($form, $event) === null ? null : is_user_logged_in();

        return new ValueContext(EventValues::form($event, $loggedIn));
    }

    public function refusals(Form $form, \DateTimeImmutable $now, array $roles, bool $passwordGiven): array
    {
        $event = $this->event($form);

        return $event === null
            ? [AccessRefusal::EventNotOpen]
            : self::refusalsOf($form, $event, $now, $roles, $passwordGiven, is_user_logged_in());
    }

    /**
     * What keeps the form from being used, in this order: the event is not open (returned alone), the
     * registration slot's date window, the password, the required role, and last an account: someone not
     * logged in on a registration form of a `mandatory` event (S15). Pure: `Forms::refusals()` is this, which
     * a submit checks under the event's lock.
     *
     * @param list<string> $roles
     * @return list<AccessRefusal>
     */
    public static function refusalsOf(
        Form $form,
        Event $event,
        \DateTimeImmutable $now,
        array $roles,
        bool $passwordGiven,
        bool $loggedIn
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
        if (
            !$loggedIn
            && $form->placement === FormPlacement::Registration
            && $event->accountMode === AccountMode::Mandatory
        ) {
            $refusals[] = AccessRefusal::AccountRequired;
        }

        return $refusals;
    }

    public function window(Form $form): array
    {
        $event = $this->event($form);

        return ['opens' => $event?->registrationOpens, 'closes' => $event?->registrationCloses];
    }

    public function keyProblem(Form $form, string $content): ?array
    {
        return SharedKeyCheck::problem($content, $form->eventId, Contributions::current());
    }

    /**
     * What a form opens with: on a form with an account step, the logged-in account's address in the contact email
     * field (it stays editable; the page and the re-fetch's block fill it only while the field is empty, never over
     * an answer typed). The person's draft is `draft()`. Nothing else yet: an additional form's current values (S60)
     * come here.
     */
    public function prefill(Form $form): array
    {
        $event = $this->event($form);
        if ($event === null || self::accountStepOf($form, $event) === null || !is_user_logged_in()) {
            return [];
        }
        $email = wp_get_current_user()->user_email;
        $contact = $email === '' ? null : $this->contactEmail($form);

        return $contact === null ? [] : [$contact => $email];
    }

    /** The key of the form's contact email field; null when the definition has none. */
    private function contactEmail(Form $form): ?string
    {
        foreach ((new Forms())->definition($form)->fields as $field) {
            if ($field->isContactEmail()) {
                return $field->key;
            }
        }

        return null;
    }

    /**
     * A registration form's draft (S74): the logged-in person's own, or a link's or the quiz's guest draft
     * the cookie names. An additional form has none (re-asked with S60).
     *
     * TODO(S15): no draft in the event's account mode `off` (S15-6) once S15 records the mode.
     * [UNAPPROVED N9]
     */
    public function draft(Form $form): FormDraft
    {
        if ($form->placement !== FormPlacement::Registration) {
            return new FormDraft();
        }
        $user = get_current_user_id();

        return $this->drafts->opening($form->eventId, $form->id, $user > 0 ? $user : null, DraftClaim::current());
    }

    public function saveDraft(Form $form, int $version, AnswerSet $answers, int $page, Actor $actor): void
    {
        if ($form->placement !== FormPlacement::Registration) {
            $error = new RuleViolation();
            throw $error;
        }
        $this->drafts->save($form->eventId, $form->id, $version, $answers, $page, $actor);
    }

    public function resolveDraftClaim(Form $form, DraftClaimChoice $choice, Actor $actor): void
    {
        $token = DraftClaim::current();
        if ($token === null) {
            return;
        }
        $this->drafts->resolve($form->id, $token, $choice, $actor);
        DraftClaim::setCookie(null);
    }

    public function submitToken(): string
    {
        return SubmitToken::generate();
    }

    public function submit(
        int $formId,
        int $version,
        array $answers,
        string $token,
        array $roles,
        bool $passwordGiven,
        Actor $actor
    ): array {
        $claim = DraftClaim::current();
        $request = new SubmitRequest($formId, $version, $answers, $token, $roles, $passwordGiven, $claim);
        $result = ($this->registrationSubmit ?? new RegistrationSubmit())->submit($request, $actor)->toArray();
        // The submit deleted the cookie's guest draft when it was this form's (S74): the cookie is done then.
        if ($claim !== null && !$this->drafts->holds($claim)) {
            DraftClaim::setCookie(null);
        }

        return $result;
    }

    public function afterSubmitTypes(): array
    {
        return AfterSubmit::TYPES;
    }

    public function accountStep(Form $form): ?AccountStep
    {
        $event = $this->event($form);

        return $event === null ? null : self::accountStepOf($form, $event);
    }

    /**
     * Whether a form of the event needs an account (owner ruling 2026-11-14): a registration form in
     * `mandatory` mode needs one, in `optional` mode offers one; an additional form never does,
     * and in `off` mode no form has accounts.
     */
    public static function accountStepOf(Form $form, Event $event): ?AccountStep
    {
        return self::stepFor($form->placement, $event->accountMode);
    }

    /** The one rule behind `accountStepOf()`, for a caller that has the placement and the mode only. */
    public static function stepFor(FormPlacement $placement, AccountMode $mode): ?AccountStep
    {
        if ($placement !== FormPlacement::Registration) {
            return null;
        }

        return match ($mode) {
            AccountMode::Mandatory => AccountStep::Required,
            AccountMode::Optional => AccountStep::Offered,
            AccountMode::Off => null,
        };
    }

    private function event(Form $form): ?Event
    {
        return $this->events->find($form->eventId);
    }
}
