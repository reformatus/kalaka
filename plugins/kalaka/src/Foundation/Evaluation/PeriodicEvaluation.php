<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Evaluation;

use Kalaka\Foundation\Api\Actor\SystemActor;
use Kalaka\Foundation\Api\Audit\ErrorRecord;
use Kalaka\Foundation\Api\Error\KalakaException;
use Kalaka\Foundation\Api\Error\VersionConflict;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Foundation\ErrorLog;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * The periodic run's evaluation of one registration, in a transaction of its own. A registration
 * that cannot be evaluated gets the "evaluation failed" flag, unless the failure is retryable (a held lock, a
 * version conflict of a system run): then it is skipped and left for the next run. foundation.md, "Failures".
 */
final class PeriodicEvaluation
{
    public function __construct(private readonly Runner $runner)
    {
    }

    // [UNAPPROVED N1] ?bool return: null = skipped for a retryable failure
    /** @return ?bool whether the registration was evaluated; null when it was skipped for a retryable failure */
    public function evaluate(RegistrationKey $key): ?bool
    {
        $input = ['event_id' => $key->eventId, 'code' => $key->code];
        try {
            $this->runner->run(new EvaluateRegistration(), $input, new SystemActor());

            return true;
        } catch (KalakaException $error) {
            // [UNAPPROVED N1] skip, no flag, for a retryable failure or a version conflict
            if ($error->isRetryable() || $error instanceof VersionConflict) {
                return null;
            }
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
            ErrorLog::failure(
                'flagging the evaluation failure of registration ' . $input['event_id'] . '/' . $input['code']
                    . ' (rule ' . $rule . ')',
                $writeError,
            );
        }
    }
}
