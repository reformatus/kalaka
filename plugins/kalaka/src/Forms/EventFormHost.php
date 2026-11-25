<?php

declare(strict_types=1);

namespace Kalaka\Forms;

use Kalaka\Assignments\Api\AssignmentSets;
use Kalaka\Events\Api\AccountMode;
use Kalaka\Events\Api\Event;
use Kalaka\Events\Api\Events;
use Kalaka\Events\Api\EventState;
use Kalaka\Forms\Api\AccessRefusal;
use Kalaka\Forms\Api\AccountStep;
use Kalaka\Forms\Api\AfterSubmit;
use Kalaka\Forms\Api\EventValues;
use Kalaka\Forms\Api\Form;
use Kalaka\Forms\Api\FormHost;
use Kalaka\Forms\Api\FormPlacement;
use Kalaka\Forms\Api\FormRefused;
use Kalaka\Forms\Api\Forms;
use Kalaka\Forms\Api\RequestOptions;
use Kalaka\Forms\Api\Value;
use Kalaka\Forms\Api\ValueContext;
use Kalaka\Forms\Api\ValueType;
use Kalaka\Forms\Engine\Condition\ConditionKeys;
use Kalaka\Forms\Engine\FormPosts;
use Kalaka\Forms\ParticipantField\SharedKeyCheck;
use Kalaka\Foundation\Api\Actor\Actor;
use Kalaka\Foundation\Api\Actor\SecretLinkActor;
use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\Portal\Api\ActiveRegistration;
use Kalaka\Registrations\Api\PoolEntry;
use Kalaka\Registrations\Api\RegistrationFacts;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\Registrations;
use Kalaka\Registrations\Api\RegistrationStatus;
use Kalaka\Registrations\Api\RegistrationSubmit;
use Kalaka\Registrations\Api\Seats;
use Kalaka\Registrations\Api\SubmitRequest;
use Kalaka\Registrations\Api\SubmitToken;

/**
 * Kaláka's event form, the form engine's first host (`Api\FormHost`): a form belongs to one event
 * (`Form::$eventId`). The conditions read the event's keys (`EventValues`), the access refusals are the
 * event's (`refusalsOf()`), a field key is unique across the event's participant fields
 * (`ParticipantField\SharedKeyCheck`), a submit is a registration (`Registrations\Api\RegistrationSubmit`),
 * the after-submit section reads the event's keys and the new registration's (`AfterSubmit`), a
 * registration form needs or offers an account by the event's account mode (`accountStepOf()`), and a
 * request field's options are those of a request-mode set of the form's event
 * (`Assignments\Api\AssignmentSets`).
 *
 * An additional form (S60) writes to the active registration (`Portal\Api\ActiveRegistration`, set by the
 * registration's secret link) when it belongs to the form's event and is `registered` or `waitlisted`; it
 * opens with that registration's current field values, and its submit runs as the secret link's actor.
 */
final class EventFormHost implements FormHost
{
    /** A key of the dropped set choice window (`choice_open.<field key>`): it reads as empty in an older revision. */
    private const CHOICE_OPEN_PREFIX = 'choice_open.';

    public function __construct(
        private readonly Events $events = new Events(),
        private readonly ?RegistrationSubmit $registrationSubmit = null,
        private readonly AssignmentSets $sets = new AssignmentSets(),
        private ?Registrations $registrations = null,
        private readonly ?\Closure $active = null,
        private ?Seats $seats = null,
    ) {
    }

    /** @var array<int, array<string, PoolEntry>> the listed pools by event ID (`poolEntries()`) */
    private array $pools = [];

    /** Built on first use: `Registrations` constructs `Forms`, which constructs this host. */
    private function registrations(): Registrations
    {
        return $this->registrations ??= new Registrations();
    }

    /** Built on first use, like `registrations()`. */
    private function seats(): Seats
    {
        return $this->seats ??= new Seats();
    }

    /**
     * What a form's conditions read besides the answers (CF2): the event's keys and `account.logged_in`; for
     * every form the event's places left, its custom points and whether each request field's choice window is
     * open (`EventValues::volatileTypes()`), and the places left of every pool a seat claim source lists
     * (`seats_left.<field key>.<option value>`, `poolTypes()`); for an additional form also the registration's
     * `registration.*` facts (`RegistrationFacts::formTypes()`) and the stored values of the event's participant
     * fields, by their keys (`typesOf()`). A key the form holds as a field is read from the answers: the parser
     * drops it from this list.
     */
    public function factTypes(Form $form): array
    {
        $event = $this->event($form);

        return $event === null ? EventValues::FORM_TYPES : $this->typesOf($event, $form->placement);
    }

    /**
     * A custom point or a pool's places left that a revision's conditions read and the event no longer has (staff
     * removed the point, the option, tier or offer) is gone: a date that reads as empty, and a number that reads
     * 0, so no revision closes over it, as none closes over a removed set option (CF1). A pool reads 0, never
     * none, so "empty or more than 0" never reads a removed pool as open. Only such a key counts; any other
     * unknown key stays an error. A save refuses it: a point by `DefinitionCheck` (`unknown_point`), a pool's
     * places left as any key the form may not read.
     */
    public function goneTypes(Form $form, array $keys): array
    {
        $event = $this->event($form);
        $gone = [];
        foreach ($keys as $key) {
            if ($event === null) {
                continue;
            }
            if (
                preg_match('/\Aevent\.points\.[0-9]+\z/', $key) === 1
                && !isset(EventValues::volatileTypes($event, [])[$key])
            ) {
                $gone[$key] = ValueType::Date;
            } elseif (
                str_starts_with($key, EventValues::POOL_SEATS_PREFIX)
                && ValueContext::isKey($key)
                && !isset($this->poolTypes($event)[$key])
            ) {
                $gone[$key] = ValueType::Number;
            }
        }

        return $gone;
    }

    /**
     * The gone keys (`goneTypes()`) that the revision being walked (`Form::$version`) reads, with the value each
     * reads as: none for a point, 0 for a pool's places left. A revision that is not readable has none.
     *
     * @return array<string, Value>
     */
    private function goneValues(Form $form): array
    {
        try {
            $content = (new FormPosts())->content($form, $form->version);
        } catch (SystemFailure) {
            return [];
        }
        $values = [];
        foreach ($this->goneTypes($form, ConditionKeys::in(parse_blocks($content))) as $key => $type) {
            $values[$key] = $type === ValueType::Number ? EventValues::places(0) : Value::none($type);
        }

        return $values;
    }

    /**
     * The pools every contributed seat claim source lists for the event, as the form variables over them:
     * `seats_left.<field key>.<option value>`, a number. One path for a set option's, a tier's and an offer's
     * places (`Registrations\Api\SeatClaimSource::pools()`).
     *
     * A source is an outside point (modules-and-extensions.md, "Failure isolation"): one that throws is logged and
     * skipped, so the form keeps working without its variables, and a stored revision that reads one of its keys
     * reads 0 like a removed pool (`goneTypes()`). An entry that is no key a condition could read is left out. The
     * list is read once per event in a host's life, so `goneTypes()` and the values reuse it.
     *
     * @return array<string, PoolEntry> variable key → entry
     */
    private function poolEntries(Event $event): array
    {
        if (isset($this->pools[$event->id])) {
            return $this->pools[$event->id];
        }
        $entries = [];
        foreach (Contributions::current()->seatClaimSources() as $source) {
            $listed = [];
            $ask = static function () use ($source, $event, &$listed): void {
                $listed = $source->pools($event);
            };
            Boundary::background('seat claim source ' . $source::class, $ask);
            foreach ($listed as $entry) {
                $key = EventValues::POOL_SEATS_PREFIX . $entry->fieldKey . '.' . $entry->optionValue;
                if (substr_count($key, '.') === 2 && ValueContext::isKey($key)) {
                    $entries[$key] = $entry;
                }
            }
        }

        return $this->pools[$event->id] = $entries;
    }

    /** @return array<string, ValueType> */
    private function poolTypes(Event $event): array
    {
        return array_map(static fn (PoolEntry $entry): ValueType => ValueType::Number, $this->poolEntries($event));
    }

    /**
     * The places left of every listed pool, as values. A pool whose read throws is logged and reads 0 (closed),
     * as a removed one does.
     *
     * @return array<string, Value>
     */
    private function poolValues(Event $event): array
    {
        $seats = $this->seats();
        $values = [];
        foreach ($this->poolEntries($event) as $key => $entry) {
            $left = 0;
            Boundary::background('places left of ' . $key, static function () use ($seats, $entry, &$left): void {
                $left = $seats->left($entry->pool);
            });
            $values[$key] = EventValues::places($left);
        }

        return $values;
    }

    /**
     * The types `factTypes()` declares, for an event and a placement (the editor lists them too).
     *
     * @return array<string, ValueType>
     */
    public function typesOf(Event $event, FormPlacement $placement): array
    {
        $types = EventValues::FORM_TYPES
            + EventValues::volatileTypes($event, array_keys($this->requestSets($event->id)))
            + $this->poolTypes($event);
        if ($placement === FormPlacement::Additional) {
            $types += RegistrationFacts::formTypes($event);
            $types += (new Forms())->storedTypes($event->id);
        }

        return $types;
    }

    /**
     * The values of `factTypes()` on a form's page and re-fetch: the active registration's facts and field values
     * for an additional form, as they are now.
     */
    public function facts(Form $form, \DateTimeImmutable $now): ?ValueContext
    {
        $event = $this->event($form);
        if ($event === null) {
            return null;
        }
        $loggedIn = self::accountStepOf($form, $event) === null ? null : is_user_logged_in();
        $target = $this->target($form);
        $registration = $target === null ? null : $this->registrations()->factsOf($target, $event, $now);

        return $this->factsFor($form, $event, $loggedIn, $now, $registration);
    }

    /**
     * The values of `typesOf()` for the form, from what the caller read: the one builder behind the page, the
     * re-fetch and the submit's walk (so a key declared is a key valued). Every key is valued, and every time
     * value (`now` among them) is read at `$now`. An additional form takes the registration's `registration.*`
     * keys and its stored values from `$registration`, the one builder the mails read too
     * (`Registrations::factsOf()`); with no registration (null) it reads them as none, never fails. The places
     * left are read now, unlocked: the submit's lock decides them again.
     *
     * @param RegistrationFacts|null $registration the registration's facts (an additional form)
     */
    public function factsFor(
        Form $form,
        Event $event,
        ?bool $loggedIn,
        \DateTimeImmutable $now,
        ?RegistrationFacts $registration
    ): ValueContext {
        $open = [];
        foreach ($this->requestSets($event->id) as $key => $set) {
            $open[$key] = $set->windowContains($now);
        }
        $values = $this->goneValues($form) + EventValues::form($event, $loggedIn, $now)
            + EventValues::volatile($event, $this->seats()->left($this->seats()->eventPool($event->id)), $open)
            + $this->poolValues($event);
        if ($form->placement === FormPlacement::Additional) {
            foreach (RegistrationFacts::formTypes($event) as $key => $type) {
                $values[$key] = Value::none($type);
            }
            $values = ($registration?->formValues() ?? []) + $values;
            foreach ((new Forms())->storedTypes($event->id) as $key => $type) {
                $values[$key] = $registration !== null && $registration->has($key)
                    ? $registration->get($key)
                    : Value::none($type);
            }
        }

        return new ValueContext($values);
    }

    public function refusals(Form $form, \DateTimeImmutable $now, array $roles, bool $passwordGiven): array
    {
        $event = $this->event($form);

        if ($event === null) {
            return [AccessRefusal::EventNotOpen];
        }
        $refusals = self::refusalsOf($form, $event, $now, $roles, $passwordGiven, is_user_logged_in());
        if ($refusals === [AccessRefusal::EventNotOpen] || $form->placement !== FormPlacement::Additional) {
            return $refusals;
        }

        $target = $this->target($form);
        if ($target === null) {
            $refusals[] = AccessRefusal::NoActiveRegistration;
        } elseif (!self::maySubmit($this->registrations()->find($target)?->status)) {
            $refusals[] = AccessRefusal::RegistrationMayNotSubmit;
        }

        return $refusals;
    }

    /**
     * The registration an additional form writes to: the active registration, when it belongs to the
     * form's event; null for a registration form or without one.
     */
    public function target(Form $form): ?RegistrationKey
    {
        if ($form->placement !== FormPlacement::Additional) {
            return null;
        }
        $active = $this->active === null ? ActiveRegistration::current() : ($this->active)();

        return $active instanceof RegistrationKey && $active->eventId === $form->eventId ? $active : null;
    }

    /** Only a `registered` or `waitlisted` registration may submit an additional form. */
    public static function maySubmit(?RegistrationStatus $status): bool
    {
        return $status === RegistrationStatus::Registered || $status === RegistrationStatus::Waitlisted;
    }

    /**
     * What keeps the form from being used, in this order: the event is not open (returned alone), the
     * registration slot's date window or an additional form's open period, the password,
     * the required role, and last an account: someone not logged in on a registration form of a `mandatory`
     * event (S15). Pure: `Forms::refusals()` is this, which a submit checks under the event's lock.
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
        if ($form->placement === FormPlacement::Additional && !OpenPeriod::contains($form, $event, $now)) {
            $refusals[] = AccessRefusal::OutsideOpenPeriod;
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
     * an answer typed). An additional form opens with its registration's current field values (S60). A draft (S74)
     * comes here.
     */
    public function prefill(Form $form): array
    {
        $target = $this->target($form);
        if ($target !== null) {
            return $this->registrations()->values($target);
        }
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

    public function requestOptions(Form $form, int $setId): ?RequestOptions
    {
        $set = $this->sets->find($setId);
        if ($set === null || $set->eventId !== $form->eventId) {
            return null;
        }
        $options = [];
        foreach ($set->options as $option) {
            $options[] = ['value' => $option->key, 'label' => $option->label];
        }

        return new RequestOptions($options);
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
        $form = (new Forms())->form($formId);
        $target = $form === null ? null : $this->target($form);
        if ($form !== null && $form->placement === FormPlacement::Additional) {
            if ($target === null) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- an enum case, no input
                throw new FormRefused([AccessRefusal::NoActiveRegistration]);
            }
            // The secret link's registration acts: the runner lets it act on that registration only.
            $actor = new SecretLinkActor($target);
        }
        $request = new SubmitRequest($formId, $version, $answers, $token, $roles, $passwordGiven, $target);

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

    public function slotRequiredKeys(Form $form): array
    {
        if ($form->placement !== FormPlacement::Registration) {
            return [];
        }
        $keys = [];
        foreach (Contributions::current()->slotRequirements() as $requirement) {
            array_push($keys, ...$requirement->requiredKeys($form->eventId));
        }

        return array_values(array_unique($keys));
    }

    private function event(Form $form): ?Event
    {
        return $this->events->find($form->eventId);
    }
}
