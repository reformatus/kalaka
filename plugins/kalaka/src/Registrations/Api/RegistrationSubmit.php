<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

use Kalaka\Events\Api\Event;
use Kalaka\Events\Api\Events;
use Kalaka\Forms\Api\AfterSubmit;
use Kalaka\Forms\Api\EventValues;
use Kalaka\Forms\Api\Form;
use Kalaka\Forms\Api\FormPlacement;
use Kalaka\Forms\Api\Forms;
use Kalaka\Forms\Api\ValueContext;
use Kalaka\Foundation\Api\Actor\Actor;
use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Error\VersionConflict;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Registrations\SubmitRegistration;

/**
 * The submit of a registration form, as the form page's entry point calls it. Before the transaction
 * it reads the form, its definition and the event, and checks the answers with the event's values
 * (`Forms::validate()`, the walk every render uses: answers whose conditions do not hold are dropped);
 * then it runs `kalaka-portal/submit-registration`, which decides the rest under the event's lock.
 * When the event changed between the two, the answers are checked again, up to `ATTEMPTS` times.
 */
final class RegistrationSubmit
{
    public const ATTEMPTS = 3;

    public function __construct(
        private readonly ?Runner $runner = null,
        private readonly Forms $forms = new Forms(),
        private readonly Events $events = new Events(),
    ) {
    }

    /**
     * The default after-submit text of each status, shown when the form has no after-submit section or it
     * fails to render. [UNAPPROVED N14]
     */
    public const DEFAULT_REGISTERED = 'Köszönjük a jelentkezésedet! A további tudnivalókat e-mailben küldjük el.';

    /** [UNAPPROVED N14] */
    public const DEFAULT_WAITLISTED = 'Köszönjük a jelentkezésedet! Jelenleg nincs szabad hely, ezért várólistára '
        . 'kerültél. Ha felszabadul egy hely, e-mailben értesítünk.';

    /**
     * @param Actor $actor the person: `GuestActor`, or `AccountActor` when logged in
     * @throws NotFound no published form with that ID, or its event is gone
     * @throws \Kalaka\Forms\Api\FormOutdated the page was built from a version that is not the latest
     * @throws SubmitTokenReused the token was spent on other answers
     * @throws InvalidInput a malformed token, an undeclared field, a value of the wrong JSON type
     * @throws \Kalaka\Forms\Api\InvalidAnswers missing or wrong answers, per field
     * @throws \Kalaka\Forms\Api\FormRefused the form cannot be used now (event not open, outside the
     *     registration window, password, role), with the codes
     * @throws RuleViolation the form is not in the registration slot
     * @throws \Kalaka\Foundation\Api\Error\KalakaException anything the run throws
     */
    public function submit(SubmitRequest $request, Actor $actor): SubmitResult
    {
        if (!SubmitToken::isValid($request->token)) {
            $error = new InvalidInput();
            throw $error;
        }
        $form = $this->forms->form($request->formId) ?? throw new NotFound();
        if ($form->placement !== FormPlacement::Registration) {
            // Additional forms attach a supplementary submission: a use case of their own.
            $error = new RuleViolation();
            throw $error;
        }
        $definition = $this->forms->latestDefinition($form, $request->version);
        $runner = $this->runner ?? Runner::current();

        for ($attempt = 1;; $attempt++) {
            $event = $this->events->find($form->eventId) ?? throw new NotFound();
            $answers = $this->forms->validate(
                $definition,
                $request->answers,
                new ValueContext(EventValues::of($event))
            );
            $useCase = new SubmitRegistration(
                $form,
                $definition->version,
                $answers,
                $event->version,
                $request->roles,
                $request->passwordGiven,
            );
            try {
                $result = $runner->run($useCase, ['form' => $form->id, 'token' => $request->token], $actor);
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
        $text = $result->status === RegistrationStatus::Waitlisted
            ? self::DEFAULT_WAITLISTED
            : self::DEFAULT_REGISTERED;

        return '<p>' . esc_html($text) . '</p>';
    }
}
