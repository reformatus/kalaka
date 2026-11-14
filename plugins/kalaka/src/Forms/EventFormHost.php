<?php

declare(strict_types=1);

namespace Kalaka\Forms;

use Kalaka\Events\Api\AccountMode;
use Kalaka\Events\Api\Event;
use Kalaka\Events\Api\Events;
use Kalaka\Events\Api\EventState;
use Kalaka\Forms\Api\AccessRefusal;
use Kalaka\Forms\Api\AccountChoice;
use Kalaka\Forms\Api\AccountStep;
use Kalaka\Forms\Api\AfterSubmit;
use Kalaka\Forms\Api\EventValues;
use Kalaka\Forms\Api\Form;
use Kalaka\Forms\Api\FormHost;
use Kalaka\Forms\Api\FormPlacement;
use Kalaka\Forms\Api\ValueContext;
use Kalaka\Forms\ParticipantField\SharedKeyCheck;
use Kalaka\Foundation\Api\Actor\Actor;
use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\Registrations\Api\RegistrationSubmit;
use Kalaka\Registrations\Api\SubmitRequest;
use Kalaka\Registrations\Api\SubmitToken;

/**
 * Kaláka's event form, the form engine's first host (`Api\FormHost`): a form belongs to one event
 * (`Form::$eventId`). The conditions read the event's keys (`EventValues`), the access refusals are the
 * event's (`refusalsOf()`), a field key is unique across the event's participant fields
 * (`ParticipantField\SharedKeyCheck`), a submit is a registration (`Registrations\Api\RegistrationSubmit`),
 * the after-submit section reads the event's keys and the new registration's (`AfterSubmit`), and a
 * registration form begins with the account step by the event's account mode (`accountStepOf()`).
 */
final class EventFormHost implements FormHost
{
    public function __construct(
        private readonly Events $events = new Events(),
        private readonly ?RegistrationSubmit $registrationSubmit = null,
    ) {
    }

    public function factTypes(): array
    {
        return EventValues::TYPES;
    }

    public function facts(Form $form): ?ValueContext
    {
        $event = $this->event($form);

        return $event === null ? null : new ValueContext(EventValues::of($event));
    }

    public function refusals(Form $form, \DateTimeImmutable $now, array $roles, bool $passwordGiven): array
    {
        $event = $this->event($form);

        return $event === null
            ? [AccessRefusal::EventNotOpen]
            : self::refusalsOf($form, $event, $now, $roles, $passwordGiven);
    }

    /**
     * What keeps the form from being used, in this order: the event is not open (returned alone), the
     * registration slot's date window, the password, the required role. Pure: `Forms::refusals()` is this,
     * which a submit checks under the event's lock.
     *
     * @param list<string> $roles
     * @return list<AccessRefusal>
     */
    public static function refusalsOf(
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
     * Nothing yet: a registration form opens empty. An additional form's current values (S60) and a draft
     * (S74) come here.
     */
    public function prefill(Form $form): array
    {
        return [];
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
        Actor $actor,
        ?AccountChoice $account = null
    ): array {
        $request = new SubmitRequest($formId, $version, $answers, $token, $roles, $passwordGiven, $account);

        return ($this->registrationSubmit ?? new RegistrationSubmit())->submit($request, $actor)->toArray();
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
     * The account step of a form of the event (owner ruling 2026-11-14): a registration form begins with it
     * in `mandatory` (sign up or log in) and `optional` mode (or skip); an additional form never does, and
     * in `off` mode no form has accounts.
     */
    public static function accountStepOf(Form $form, Event $event): ?AccountStep
    {
        if ($form->placement !== FormPlacement::Registration) {
            return null;
        }

        return match ($event->accountMode) {
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
