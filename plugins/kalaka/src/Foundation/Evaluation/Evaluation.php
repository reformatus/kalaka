<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Evaluation;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\Decision\Decision;
use Kalaka\Foundation\Api\Decision\DecisionInput;
use Kalaka\Foundation\Decision\DecisionQuery;
use Kalaka\Foundation\Decision\DecisionRecord;
use Kalaka\Foundation\Decision\DecisionRepository;
use Kalaka\Foundation\Api\Decision\DecisionRule;
use Kalaka\Foundation\Decision\DecisionRules;
use Kalaka\Mail\Api\ActionMail;
use Kalaka\Mail\Api\ActionMails;
use Kalaka\Pricing\Api\ConfirmationRule;
use Kalaka\Pricing\Api\FeeComponentFailed;
use Kalaka\Pricing\Api\Materializer;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\Registrations;

/**
 * Brings the touched registrations' money, decisions and mail up to date with their facts, in that order, in
 * the outermost use case's transaction. foundation.md, "Evaluation". [UNAPPROVED N17]
 */
final class Evaluation
{
    public function __construct(
        private readonly DecisionRules $rules,
        private readonly DecisionRepository $decisions = new DecisionRepository(),
        private readonly DecisionQuery $query = new DecisionQuery(),
        private readonly EvaluationFailureRepository $failures = new EvaluationFailureRepository(),
        private readonly Registrations $registrations = new Registrations(),
        private readonly ?Materializer $money = null,
        private readonly ActionMails $mails = new ActionMails(),
    ) {
    }

    /**
     * Evaluates each registration the attempt touched once, ascending by event and code, and
     * those first touched while evaluating after them. Each round first locks the registrations the
     * run does not hold yet, in that order, before it writes anything; a key without a row gets no
     * decision, and its flag goes. Only the first round can take such a lock: a later round comes
     * after the decision writes, which are at level Other, so a registration first touched while
     * evaluating must already be held, or the lock-order guard refuses it and the use case fails.
     */
    public function run(Context $context): void
    {
        $evaluated = [];
        while (true) {
            $pending = [];
            foreach ($context->touchedRegistrations() as $key) {
                if (!isset($evaluated[self::identity($key)])) {
                    $pending[] = $key;
                }
            }
            if ($pending === []) {
                return;
            }

            usort(
                $pending,
                static fn (RegistrationKey $a, RegistrationKey $b): int => $a->eventId <=> $b->eventId
                    ?: strcmp($a->code, $b->code)
            );
            $missing = [];
            foreach ($pending as $key) {
                if (!$context->holdsRegistration($key) && !$this->registrations->lock($key, $context)) {
                    $missing[self::identity($key)] = true;
                }
            }
            foreach ($pending as $key) {
                $evaluated[self::identity($key)] = true;
                if (!isset($missing[self::identity($key)])) {
                    $this->materialize($key, $context);
                    $this->mail($key, $this->decide($key, $context), $context);
                }
                $this->clearFlag($key, $context);
            }
        }
    }

    /**
     * The money phase, first, so the later phases see the pass's money (money.md, "Materializer"). A failing
     * fee component fails the use case as the rule `money/<id>`.
     */
    private function materialize(RegistrationKey $key, Context $context): void
    {
        if ($this->money === null) {
            return;
        }
        try {
            $this->money->materialize($key, $context);
        } catch (FeeComponentFailed $failed) {
            $error = new SystemFailure(null, new RuleFailed('money/' . $failed->componentId, $failed));
            throw $error;
        }
    }

    private function clearFlag(RegistrationKey $key, Context $context): void
    {
        $flag = $this->failures->find($key);
        if ($flag !== null) {
            $this->failures->delete($flag, $context);
        }
    }

    /**
     * The decisions phase, after money: the rules in the order they were declared, core first, then extensions.
     * A core rule reads its own kind and the core kinds decided before it; an extension rule its own kind and
     * every core kind, never another
     * extension's.
     *
     * @return list<DecisionRecord> the records this pass wrote, in order
     */
    private function decide(RegistrationKey $key, Context $context): array
    {
        if ($this->rules->all() === []) {
            return [];
        }

        $decisions = $this->query->currentOf($key);
        $coreKinds = array_map(static fn (DecisionRule $rule): string => $rule->kind(), $this->rules->core());
        $coreBefore = [];
        $written = [];
        foreach ($this->rules->all() as $rule) {
            if ($this->rules->isCore($rule->kind())) {
                $coreBefore[] = $rule->kind();
                $visible = $coreBefore;
            } else {
                $visible = [...$coreKinds, $rule->kind()];
            }
            array_push($written, ...$this->apply($rule, $visible, $key, $decisions, $context));
        }

        return $written;
    }

    /**
     * The mail phase, last: every `confirmed` record this pass wrote queues the event's confirmed mail, keyed
     * by that record, so a re-confirmation right after staff's unconfirm, a retry and a restore each mail
     * (mail.md, "Five kinds of mail"). Nothing is queued while the event has the mail off. [UNAPPROVED N17, N18]
     *
     * @param list<DecisionRecord> $written the decision records this pass wrote
     */
    private function mail(RegistrationKey $key, array $written, Context $context): void
    {
        foreach ($written as $record) {
            if ($record->kind === ConfirmationRule::KIND && $record->state === ConfirmationRule::CONFIRMED) {
                $cause = new Subject('decision', $key->eventId, (string) $record->id);
                $this->mails->due($context, ActionMail::Confirmed, $key, $cause);
            }
        }
    }

    /**
     * Asks the rule and writes each transition it returns, in order, that changes the running state.
     *
     * @param list<string> $visible the kinds the rule reads
     * @param array<string, Decision> $decisions the current decisions; gets what the rule writes
     * @return list<DecisionRecord> the records written
     */
    private function apply(
        DecisionRule $rule,
        array $visible,
        RegistrationKey $key,
        array &$decisions,
        Context $context,
    ): array {
        $kind = $rule->kind();
        try {
            $transitions = $rule->decide(new DecisionInput(
                $key,
                $context->now(),
                array_intersect_key($decisions, array_flip($visible)),
            ));
            $states = $rule->states();
        } catch (\Throwable $thrown) {
            $error = self::failed($kind, $thrown);
            throw $error;
        }

        $written = [];
        foreach ($transitions as $transition) {
            if (!array_key_exists($transition->state, $states)) {
                $error = self::failed($kind, null);
                throw $error;
            }
            if ($transition->state === ($decisions[$kind]->state ?? null)) {
                continue;
            }

            $stored = $this->decisions->insert(
                DecisionRecord::by($context->actor(), $key, $kind, $transition, $context->now()),
                $context
            );
            $decisions[$kind] = new Decision(
                $kind,
                $stored->state,
                $stored->effectiveAt,
                $stored->recordedAt,
                $stored->cause,
            );
            $written[] = $stored;
        }

        return $written;
    }

    private static function failed(string $kind, ?\Throwable $previous): SystemFailure
    {
        return new SystemFailure(null, new RuleFailed('decisions/' . $kind, $previous));
    }

    private static function identity(RegistrationKey $key): string
    {
        return $key->eventId . "\0" . $key->code;
    }
}
