<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

use Kalaka\Events\Api\AccountMode;
use Kalaka\Events\Api\Event;
use Kalaka\Events\Api\Events;
use Kalaka\Forms\Api\AccessRefusal;
use Kalaka\Forms\Api\AfterSubmit;
use Kalaka\Forms\Api\EventValues;
use Kalaka\Forms\Api\Form;
use Kalaka\Forms\Api\FormDefinition;
use Kalaka\Forms\Api\FormPlacement;
use Kalaka\Forms\Api\FormRefused;
use Kalaka\Forms\Api\Forms;
use Kalaka\Forms\Api\ValueContext;
use Kalaka\Foundation\Api\Actor\AccountActor;
use Kalaka\Foundation\Api\Actor\Actor;
use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Error\VersionConflict;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\ParticipantFields\Api\ParticipantFields;
use Kalaka\Registrations\Account\AccountOutcome;
use Kalaka\Registrations\FieldValue\FieldValueRepository;
use Kalaka\Registrations\SubmitAdditionalForm;
use Kalaka\Registrations\SubmitRegistration;

/**
 * The submit of a form, as the form page's entry point calls it. Before the transaction
 * it reads the form, its definition and the event, and checks the answers with the event's values
 * (`Forms::validate()`, the walk every render uses: answers whose conditions do not hold are dropped);
 * then it runs `kalaka-portal/submit-registration` for a registration form, which decides the rest under
 * the event's lock, or `kalaka-portal/submit-additional-form` for an additional form (S60), which writes to
 * the active registration the request names (`SubmitRequest::$target`) and asks the field types what a blank
 * answer clears before its transaction opens.
 * When the event changed between the two, the answers are checked again, up to `ATTEMPTS` times.
 *
 * Accounts (S15, by the event's account mode): before any transaction it decides what the submit does about
 * accounts (`accountOutcome()`): a logged-in person owns the registration, and someone not logged in may
 * submit only in `optional` mode. The account itself was created earlier, on WordPress's registration page
 * (`Account\SignUpPage`).
 */
final class RegistrationSubmit
{
    public const ATTEMPTS = 3;

    public function __construct(
        private readonly ?Runner $runner = null,
        private readonly Forms $forms = new Forms(),
        private readonly Events $events = new Events(),
        private readonly ParticipantFields $participantFields = new ParticipantFields(),
        private readonly FieldValueRepository $values = new FieldValueRepository(),
    ) {
    }

    /**
     * The default after-submit text of each status, shown when the form has no after-submit section or it
     * fails to render (#161 N14; the waitlisted text owner 2026-11-13).
     */
    public const DEFAULT_REGISTERED = 'Köszönjük a jelentkezésedet! A további tudnivalókat e-mailben küldjük el.';

    public const DEFAULT_WAITLISTED = 'Köszönjük a jelentkezésedet! Jelenleg nincs szabad hely, ezért várólistára '
        . 'kerültél. Figyeld az e-maileidet: értesítünk, ha be tudunk hívni az eseményre.';

    /** The default after-submit text of an additional form's submission (S60), whatever the status. */
    public const DEFAULT_SUPPLEMENT = 'Köszönjük, a válaszaidat elmentettük.';

    /**
     * @param Actor $actor the person: `GuestActor`, or `AccountActor` when logged in
     * @throws NotFound no published form with that ID, or its event is gone
     * @throws \Kalaka\Forms\Api\FormOutdated the page was built from a version that is not the latest
     * @throws SubmitTokenReused the token was spent on other answers
     * @throws InvalidInput a malformed token, an undeclared field, a value of the wrong JSON type
     * @throws \Kalaka\Forms\Api\InvalidAnswers missing or wrong answers, per field
     * @throws \Kalaka\Forms\Api\FormTerminal a terminal page shows for the answers
     * @throws \Kalaka\Forms\Api\FormRefused the form cannot be used now (event not open, outside the
     *     registration window, password, role), with the codes
     * @throws RuleViolation a money-relevant field's value would be overwritten (an additional form)
     * @throws FormRefused also `account_required`: someone not logged in, in `mandatory` mode (from the use case)
     * @throws \Kalaka\Foundation\Api\Error\KalakaException anything the run throws
     */
    public function submit(SubmitRequest $request, Actor $actor): SubmitResult
    {
        if (!SubmitToken::isValid($request->token)) {
            $error = new InvalidInput();
            throw $error;
        }
        $form = $this->forms->form($request->formId) ?? throw new NotFound();
        if ($form->placement === FormPlacement::Additional && $request->target === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- an enum case, no input
            throw new FormRefused([AccessRefusal::NoActiveRegistration]);
        }
        $definition = $this->forms->latestDefinition($form, $request->version);
        $runner = $this->runner ?? Runner::current();

        for ($attempt = 1;; $attempt++) {
            $event = $this->events->find($form->eventId) ?? throw new NotFound();
            // Decided on every attempt: a retry reads the event again, and its account mode may have changed.
            $account = $this->accountOutcome($event, $actor);
            // `account.logged_in`: yes or no where the form has an account step (an account submits), none
            // where it has none: an additional form and mode `off` alike.
            $loggedIn = $form->placement === FormPlacement::Additional || $event->accountMode === AccountMode::Off
                ? null
                : $actor instanceof AccountActor;
            $answers = $this->forms->validate(
                $definition,
                $request->answers,
                new ValueContext(EventValues::form($event, $loggedIn))
            );
            $target = $request->target;
            $fieldTypes = self::fieldTypes($definition);
            if ($form->placement === FormPlacement::Additional && $target !== null) {
                // An additional form writes a supplementary submission to the registration (S60). What its
                // blank fields clear depends on the field types, which run here, outside the transaction.
                $current = [];
                foreach ($this->values->forRegistration($target) as $value) {
                    $current[$value->fieldKey] = $value;
                }
                $useCase = new SubmitAdditionalForm(
                    $form,
                    $definition,
                    $answers,
                    $event->version,
                    $request->roles,
                    $request->passwordGiven,
                    $fieldTypes,
                    $this->participantFields->of($form->eventId),
                    SubmitAdditionalForm::clearable($definition, $answers, $current),
                );
                $input = [
                    'form' => $form->id,
                    'token' => $request->token,
                    'event' => $target->eventId,
                    'code' => $target->code,
                ];
            } else {
                $useCase = new SubmitRegistration(
                    $form,
                    $definition->version,
                    $answers,
                    $event->version,
                    $request->roles,
                    $request->passwordGiven,
                    $fieldTypes,
                    $this->participantFields->of($form->eventId),
                    account: $account,
                );
                $input = ['form' => $form->id, 'token' => $request->token];
            }
            try {
                $result = $runner->run($useCase, $input, $actor);
            } catch (VersionConflict $conflict) {
                if ($attempt >= self::ATTEMPTS) {
                    throw $conflict;
                }
                continue;
            }
            if (!$result instanceof SubmitResult) {
                $error = new SystemFailure(null, new \LogicException('The submit returned no result.'));
                throw $error;
            }

            // After the transaction: the after-submit content reads the form's post.
            return $result->withAfterSubmit($this->afterSubmit($form, $definition->version, $event, $result));
        }
    }

    /**
     * What the submit does about accounts (registrations-and-identity.md, "Accounts on the registration
     * form"; owner ruling 2026-11-14, S15-6 approved 2026-11-16):
     *
     * - mode `off`: nothing, as before accounts, logged in or not;
     * - logged in: the person's account owns the registration;
     * - not logged in: nothing. In `mandatory` mode the submit refuses it as one of the form's refusals
     *   (`account_required`, `EventFormHost::refusalsOf()`), under the lock with the others.
     */
    private function accountOutcome(Event $event, Actor $actor): AccountOutcome
    {
        if ($event->accountMode === AccountMode::Off) {
            return AccountOutcome::none();
        }
        if ($actor instanceof AccountActor) {
            return AccountOutcome::loggedIn($actor->userId);
        }
        return AccountOutcome::none();
    }

    /**
     * @return array<string, string> field key → the field block's name
     */
    private static function fieldTypes(FormDefinition $definition): array
    {
        $types = [];
        foreach ($definition->fields as $field) {
            $types[$field->key] = $field->blockName;
        }

        return $types;
    }

    /**
     * The form's after-submit section rendered for the registration (#156 N12; for a repeat the original
     * one, N13), or the status's default text when there is none. The registration is committed by now,
     * so a section that fails to render is logged and the default shows instead of an error.
     */
    private function afterSubmit(Form $form, int $version, Event $event, SubmitResult $result): string
    {
        $html = null;
        Boundary::background('after-submit section of form ' . $form->id, function () use (
            &$html,
            $form,
            $version,
            $event,
            $result
        ): void {
            $context = AfterSubmit::context($event, $result->registration->code, $result->status->value);
            $html = $this->forms->afterSubmit($form, $version, $context);
        });
        if (is_string($html)) {
            return $html;
        }
        $text = match (true) {
            $result->supplementary => self::DEFAULT_SUPPLEMENT,
            $result->status === RegistrationStatus::Waitlisted => self::DEFAULT_WAITLISTED,
            default => self::DEFAULT_REGISTERED,
        };

        return '<p>' . esc_html($text) . '</p>';
    }
}
