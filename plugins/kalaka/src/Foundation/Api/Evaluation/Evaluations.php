<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Evaluation;

use Kalaka\Foundation\Api\Actor\Actor;
use Kalaka\Foundation\Api\Actor\SystemActor;
use Kalaka\Foundation\Api\Audit\ErrorRecord;
use Kalaka\Foundation\Api\Error\KalakaException;
use Kalaka\Foundation\Api\Error\VersionConflict;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Foundation\ErrorLog;
use Kalaka\Foundation\Evaluation\EvaluateRegistration;
use Kalaka\Foundation\Evaluation\FlagEvaluationFailure;
use Kalaka\Foundation\Evaluation\RuleFailed;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * Evaluates one registration now, in a transaction of its own (foundation.md, "Failures" and "Bulk changes"): the
 * periodic run and a money settings apply both come through here. A failure that is not retryable (a held lock, a
 * version conflict) gets the "evaluation failed" flag, so the hourly sweep retries it, and is then thrown on.
 */
final class Evaluations
{
    public function __construct(private readonly ?Runner $runner = null)
    {
    }

    /**
     * @throws KalakaException when the registration cannot be evaluated; nothing of its evaluation is saved
     */
    public function evaluate(RegistrationKey $key, Actor $actor): void
    {
        $input = ['event_id' => $key->eventId, 'code' => $key->code];
        try {
            $this->runner()->run(new EvaluateRegistration(), $input, $actor);
        } catch (KalakaException $error) {
            if (!self::retryable($error)) {
                $this->flag($input, $error);
            }

            throw $error;
        }
    }

    /** Whether a failure is left for the next run instead of flagged: a held lock or a version conflict. */
    public static function retryable(KalakaException $error): bool
    {
        return $error->isRetryable() || $error instanceof VersionConflict;
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
            $this->runner()->run(
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

    private function runner(): Runner
    {
        return $this->runner ?? Runner::current();
    }
}
