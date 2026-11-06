<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Evaluation;

use Kalaka\Foundation\Api\Actor\SystemActor;
use Kalaka\Foundation\Api\Audit\Correlation;
use Kalaka\Foundation\Api\Audit\ErrorRecord;
use Kalaka\Foundation\Api\Error\KalakaException;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * The periodic run's evaluation of one registration, in a transaction of its own. A registration
 * that cannot be evaluated gets the "evaluation failed" flag. foundation.md, "Failures".
 */
final class PeriodicEvaluation
{
    public function __construct(private readonly Runner $runner)
    {
    }

    /** @return bool whether the registration was evaluated */
    public function evaluate(RegistrationKey $key): bool
    {
        $input = ['event_id' => $key->eventId, 'code' => $key->code];
        try {
            $this->runner->run(new EvaluateRegistration(), $input, new SystemActor());

            return true;
        } catch (KalakaException $error) {
            $this->flag($input, $error);

            return false;
        }
    }

    /** @param array{event_id: int, code: string} $input */
    private function flag(array $input, KalakaException $error): void
    {
        $rule = 'evaluation';
        for ($cause = $error->getPrevious(); $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof RuleFailed) {
                $rule = $cause->getMessage();
                break;
            }
        }

        try {
            $this->runner->run(
                new FlagEvaluationFailure(),
                $input + ['rule' => $rule, 'error' => ErrorRecord::json($error, EvaluateRegistration::name())],
                new SystemActor()
            );
        } catch (\Throwable $writeError) {
            error_log(
                'Kaláka: evaluation failure not flagged: rule ' . $rule . ', correlation ' . Correlation::id()
                . ': ' . $writeError::class
            );
        }
    }
}
