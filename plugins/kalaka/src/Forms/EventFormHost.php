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
use Kalaka\Forms\Api\AnswerSet;
use Kalaka\Forms\Api\DraftClaimChoice;
use Kalaka\Forms\Api\EventValues;
use Kalaka\Forms\Api\Form;
use Kalaka\Forms\Api\FormDraft;
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
use Kalaka\Forms\Engine\SubmitGuard;
use Kalaka\Forms\ParticipantField\SharedKeyCheck;
use Kalaka\Foundation\Api\Actor\AccountActor;
use Kalaka\Foundation\Api\Actor\Actor;
use Kalaka\Foundation\Api\Actor\GuestActor;
use Kalaka\Foundation\Api\Actor\SecretLinkActor;
use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\ParticipantFields\Api\ParticipantFields;
use Kalaka\Portal\Api\ActiveRegistration;
use Kalaka\Registrations\Api\DraftClaim;
use Kalaka\Registrations\Api\DraftClaimRefused;
use Kalaka\Registrations\Api\DraftClaimOutcome;
use Kalaka\Registrations\Api\Drafts;
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
 * registration's secret link) when it belongs to the form's event and is `registered` (a waitlisted registration
 * has no additional forms and its link shows the form closed, owner ruling 2026-11-26); it opens with that
 * registration's current field values, and its submit runs as the secret link's actor.
 */
final class EventFormHost implements FormHost
{
    /** A key of the dropped set choice window (`choice_open.<field key>`): it reads as empty in an older revision. */
    private const CHOICE_OPEN_PREFIX = 'choice_open.';

    public function __construct(
        private readonly Events $events = new Events(),
        private readonly ?RegistrationSubmit $registrationSubmit = null,
        private readonly Drafts $drafts = new Drafts(),
        private readonly AssignmentSets $sets = new AssignmentSets(),
        private readonly SubmitGuard $guard = new SubmitGuard(),
        private readonly Seats $seats = new Seats(),
        private ?Registrations $registrations = null,
        private readonly ?\Closure $active = null,
    ) {
    }

    /** @var array<int, array<string, PoolEntry>> the listed pools by event ID (`poolEntries()`) */
    private array $pools = [];

    /** Built on first use: `Registrations` constructs `Forms`, which constructs this host. */
    private function registrations(): Registrations
    {
        return $this->registrations ??= new Registrations();
    }

    /**
     * What a form's conditions read besides the answers (CF2): the event's keys and `account.logged_in`; for
     * every form the event's places left and its custom points (`EventValues::volatileTypes()`), and the places left
     * of every pool a seat claim source lists (`seats_left.<field key>.<option value>`, `poolTypes()`); for an
     * additional form also the registration's
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
     * none, so "empty or more than 0" never reads a removed pool as open. So is `choice_open.<key>`, a set's
     * choice window, dropped by the owner's ruling 2026-11-25: a choice that reads as empty. Only such a key
     * counts; any other unknown key stays an error. A save refuses it: a point by `DefinitionCheck`
     * (`unknown_point`), a pool's places left and a `choice_open.*` key as any key the form may not read.
     */
    public function goneTypes(Form $form, array $keys): array
    {
        $event = $this->event($form);
        $gone = [];
        foreach ($keys as $key) {
            if (str_starts_with($key, self::CHOICE_OPEN_PREFIX)) {
                $gone[$key] = ValueType::Choice;
                continue;
            }
            if ($event === null) {
                continue;
            }
            if (
                EventValues::isPointKey($key)
                && !isset(EventValues::volatileTypes($event)[$key])
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
     * reads as: none for a point or a choice, 0 for a pool's places left. A revision that is not readable has none.
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
     * as a removed one does. While the event takes no more registrations a registration form reads every pool as 0
     * (`$eventFull`): a new registrant lands on the waitlist and can choose nothing limited (owner ruling
     * 2026-11-25; the submit refuses the race, `Seats`' O4 rule). An additional form keeps the real counts.
     *
     * @return array<string, Value>
     */
    private function poolValues(Event $event, bool $eventFull): array
    {
        $seats = $this->seats;
        $values = [];
        foreach ($this->poolEntries($event) as $key => $entry) {
            if ($eventFull) {
                $values[$key] = EventValues::places(0);
                continue;
            }
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
            + EventValues::volatileTypes($event)
            + $this->poolTypes($event);
        if ($placement === FormPlacement::Additional) {
            $types += RegistrationFacts::formTypes($event);
            $types += (new Forms())->storedTypes($event->id);
        }

        return $types;
    }

    /**
     * The values of `factTypes()` on a form's page and re-fetch: the active registration's facts and field values
     * for an additional form, as they are now, and the event's keys read at the moment the caller gives
     * (one clock per act: the page, the re-fetch and the submit's walk keep it alike).
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
     * The caller gives the moment the facts are read at (the page and re-fetch the act's
     * moment, a submit's walk the same one before its transaction), so one moment is used for all the facts of a
     * read, and the page, the re-fetch and the submit's walk keep it alike: `time.now` and the event's dates read
     * it.
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
        // The event's places left, read once for `event.seats_left` and for the registration form's pools below.
        $eventLeft = $this->seats->left($this->seats->eventPool($event->id));
        $values = $this->goneValues($form) + EventValues::form($event, $loggedIn, $now)
            + EventValues::volatile($event, $eventLeft)
            + $this->poolValues($event, $form->placement === FormPlacement::Registration && $eventLeft === 0);
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
        } else {
            $status = $this->registrations()->find($target)?->status;
            if (!self::maySubmit($status)) {
                $refusals[] = AccessRefusal::RegistrationMayNotSubmit;
            }
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

    /**
     * Only a `registered` registration may submit an additional form (owner ruling 2026-11-25); the form of any
     * other (waitlisted, withdrawn, removed) shows closed (owner ruling 2026-11-26).
     */
    public static function maySubmit(?RegistrationStatus $status): bool
    {
        return $status?->isLive() === true;
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
     * an answer typed). An additional form opens with its registration's current field values (S60). The person's
     * draft is `draft()`, laid over these.
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

    /**
     * A registration form's draft (S74): the logged-in person's own, or a guest draft the cookie names (a
     * link's or the quiz's handoff, or the guest's own typing). An additional form has none (re-asked with S60).
     *
     * In every account mode, `off` included: a guest's guest draft needs no account (owner ruling 2026-11-26).
     */
    public function draft(Form $form): FormDraft
    {
        if (!$this->keepsDrafts($form)) {
            return new FormDraft();
        }
        $user = get_current_user_id();
        $draft = $this->drafts->opening(
            $form->eventId,
            $form->id,
            $user > 0 ? $user : null,
            DraftClaim::current(),
            DraftClaim::handoff()
        );
        $notice = DraftHandoff::settledNow();

        return $notice === null ? $draft : new FormDraft(
            $draft->answers,
            $draft->autosave,
            $draft->claimConflict,
            $draft->claims,
            $draft->linkChanges,
            $notice,
            $draft->guestStart,
            $draft->guestChoice
        );
    }

    public function saveDraft(
        Form $form,
        int $version,
        AnswerSet $answers,
        int $page,
        int $claims,
        Actor $actor
    ): void {
        if (!$this->keepsDrafts($form)) {
            $error = new NotFound();
            throw $error;
        }
        if (!$actor instanceof GuestActor) {
            $this->drafts->save($form->eventId, $form->id, $version, $answers, $page, $claims, $actor);

            return;
        }
        // A guest's save (owner ruling 2026-11-26): the first one creates the guest draft and gives the cookie,
        // lasting until the event's registration closes; the same draft, cookie and lifetime as a handoff's.
        $closes = $this->event($form)?->registrationCloses;
        if ($closes === null) {
            $error = new NotFound();
            throw $error;
        }
        $token = DraftClaim::current();
        if ($token === null || !$this->drafts->holdsOf($form->id, $token)) {
            // A save that creates a row counts like a handoff, per address (`SubmitGuard::checkHandoff()`); a
            // save into the draft the cookie names is bounded by the body limit, as a logged-in person's is.
            $this->guard->checkHandoff($actor);
        }
        $kept = $this->drafts->save($form->eventId, $form->id, $version, $answers, $page, $claims, $actor, $token);
        if ($kept !== null) {
            DraftClaim::setCookie($kept, $closes);
        }
    }

    /**
     * A link's or the quiz's accepted answers as a guest draft (S74, the handoff), in every account mode: the
     * claim token for the handoff cookie, and the instant the cookie lives until, the event's registration
     * closing; null when the form keeps no drafts (an additional form) or its event is gone. The answers wait
     * there for the next load (`settleHandoff()`).
     *
     * @param ?string $claimToken the token of the request's handoff cookie: a handoff left waiting is replaced
     * @return array{string, \DateTimeImmutable}|null
     */
    public function handOver(Form $form, int $version, AnswerSet $accepted, ?string $claimToken, Actor $actor): ?array
    {
        $event = $this->keepsDrafts($form) ? $this->event($form) : null;
        if ($event === null || $event->registrationCloses === null) {
            return null;
        }
        $token = $this->drafts->handOver($form->eventId, $form->id, $version, $accepted, $claimToken, $actor);

        return [$token, $event->registrationCloses];
    }

    /** A logged-in person's claim of the cookie's guest draft, on the `wp` request. */
    public function claimDraft(Form $form, string $token, AccountActor $actor): DraftClaimOutcome
    {
        return $this->drafts->claim($form->id, $token, $actor);
    }

    /**
     * The page load after a handoff (N1106): the handoff that waits under the token meets the guest draft the
     * draft cookie names (`$claimToken`, if any); see `Drafts::settleHandoff()`.
     */
    public function settleHandoff(
        Form $form,
        string $handoffToken,
        ?string $claimToken,
        Actor $actor
    ): DraftClaimOutcome {
        return $this->drafts->settleHandoff($form->id, $handoffToken, $claimToken, $actor);
    }

    /** The choice that settled the handoff into the guest's own draft; null when none (N1106). */
    public function settledGuestChoice(Form $form, string $claimToken, string $handoffToken): ?DraftClaimChoice
    {
        return $this->drafts->settledGuestChoice($form->id, $claimToken, $handoffToken);
    }

    /** The instant the draft cookies of the form live until, its event's registration closing; null when none. */
    public function cookieUntil(Form $form): ?\DateTimeImmutable
    {
        return $this->event($form)?->registrationCloses;
    }

    /** The choice that settled the person's claim of the guest draft; null when none. */
    public function settledChoice(Form $form, int $userId, string $token): ?DraftClaimChoice
    {
        return $this->drafts->settledChoice($form->eventId, $form->id, $userId, $token);
    }

    /** Whether the form has drafts: a registration form, whatever the event's account mode. */
    private function keepsDrafts(Form $form): bool
    {
        return $form->placement === FormPlacement::Registration && $this->event($form) !== null;
    }

    public function resolveDraftClaim(Form $form, DraftClaimChoice $choice, Actor $actor): void
    {
        $token = DraftClaim::current();
        if ($actor instanceof GuestActor) {
            // A guest's choice (N1106): the guest's own draft (the draft cookie) against the handoff that waits
            // (the handoff cookie); the request proves itself with both tokens, as it has no account.
            $handoff = DraftClaim::handoff();
            if ($token === null || $handoff === null) {
                $error = new DraftClaimRefused();
                throw $error;
            }
            $this->drafts->resolveGuest($form->id, $token, $handoff, $choice, $actor);

            return;
        }
        if ($token === null) {
            $error = new DraftClaimRefused();
            throw $error;
        }
        // The cookie stays, so a repeat after a lost answer finds the same claim; the next load of the form's
        // page clears it (`DraftHandoff`: the guest draft is gone).
        $this->drafts->resolve($form->eventId, $form->id, $token, $choice, $actor);
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
        $claim = DraftClaim::current();
        $request = new SubmitRequest(
            $formId,
            $version,
            $answers,
            $token,
            $roles,
            $passwordGiven,
            $target,
            $claim
        );
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

    public function slotRequiredKeys(Form $form): array
    {
        if ($form->placement !== FormPlacement::Registration) {
            return [];
        }
        $keys = [];
        foreach (Contributions::current()->slotRequirements() as $requirement) {
            // An outside contribution point: a throwing requirement is logged and skipped, never the page
            // (modules-and-extensions.md, "Failure isolation"), as `ThemeAdapters::pick()` treats an adapter.
            $required = [];
            Boundary::background(
                'slot requirement ' . $requirement::class,
                static function () use ($requirement, $form, &$required): void {
                    $required = $requirement->requiredKeys($form->eventId);
                }
            );
            array_push($keys, ...$required);
        }

        return array_values(array_unique($keys));
    }

    /**
     * The event's participant fields' titles and option labels, retired fields and options included: what a form shows
     * where it leaves a label empty. For a code-declared (`extension`) field these are the declarer's texts, its admin
     * name.
     */
    public function labelFallbacks(Form $form): array
    {
        $fallbacks = [];
        foreach ((new ParticipantFields())->of($form->eventId, true) as $field) {
            $options = [];
            foreach ($field->options as $option) {
                $options[$option['value']] = $option['label'];
            }
            $fallbacks[$field->key] = ['title' => $field->title, 'options' => $options];
        }

        return $fallbacks;
    }

    private function event(Form $form): ?Event
    {
        return $this->events->find($form->eventId);
    }
}
