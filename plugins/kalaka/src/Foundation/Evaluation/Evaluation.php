<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Evaluation;

use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Decision\Decision;
use Kalaka\Foundation\Decision\DecisionInput;
use Kalaka\Foundation\Decision\DecisionQuery;
use Kalaka\Foundation\Decision\DecisionRecord;
use Kalaka\Foundation\Decision\DecisionRepository;
use Kalaka\Foundation\Decision\DecisionRule;
use Kalaka\Foundation\Decision\DecisionRules;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * Brings the touched registrations' decisions up to date with their facts, in the outermost
 * use case's transaction. foundation.md, "Evaluation".
 */
final class Evaluation
{
    public function __construct(
        private readonly DecisionRules $rules,
        private readonly DecisionRepository $decisions = new DecisionRepository(),
        private readonly DecisionQuery $query = new DecisionQuery(),
        private readonly EvaluationFailureRepository $failures = new EvaluationFailureRepository(),
    ) {
    }

    /**
     * Evaluates each registration the attempt touched once, ascending by event and code, and
     * those first touched while evaluating after them.
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
            foreach ($pending as $key) {
                $evaluated[self::identity($key)] = true;
                $this->evaluate($key, $context);
            }
        }
    }

    private function evaluate(RegistrationKey $key, Context $context): void
    {
        $this->decide($key, $context);

        $flag = $this->failures->find($key);
        if ($flag !== null) {
            $this->failures->delete($flag, $context);
        }
    }

    /**
     * Core rules first, then extension rules, in registration order. A core rule reads its own
     * kind and the core kinds before it; an extension rule its own kind and every core kind.
     */
    private function decide(RegistrationKey $key, Context $context): void
    {
        $core = $this->rules->core();
        $extension = $this->rules->extension();
        if ($core === [] && $extension === []) {
            return;
        }

        $decisions = $this->query->currentOf($key);
        $coreKinds = array_map(static fn (DecisionRule $rule): string => $rule->kind(), $core);
        foreach ($core as $position => $rule) {
            $this->apply($rule, array_slice($coreKinds, 0, $position + 1), $key, $decisions, $context);
        }
        foreach ($extension as $rule) {
            $this->apply($rule, [...$coreKinds, $rule->kind()], $key, $decisions, $context);
        }
    }

    /**
     * @param list<string> $visible the kinds the rule reads
     * @param array<string, Decision> $decisions the current decisions; gets what the rule writes
     */
    private function apply(
        DecisionRule $rule,
        array $visible,
        RegistrationKey $key,
        array &$decisions,
        Context $context,
    ): void {
        $kind = $rule->kind();
        try {
            $transition = $rule->decide(new DecisionInput(
                $key,
                $context->now(),
                array_intersect_key($decisions, array_flip($visible)),
            ));
            $states = $rule->states();
        } catch (\Throwable $thrown) {
            $error = self::failed($kind, $thrown);
            throw $error;
        }

        if ($transition === null) {
            return;
        }
        if (!in_array($transition->state, $states, true)) {
            $error = self::failed($kind, null);
            throw $error;
        }
        if ($transition->state === ($decisions[$kind]->state ?? null)) {
            return;
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
