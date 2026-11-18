<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Evaluation;

use Kalaka\Foundation\Api\Actor\SystemActor;
use Kalaka\Foundation\Api\Error\KalakaException;
use Kalaka\Foundation\Api\Evaluation\Evaluations;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * The periodic run's evaluation of one registration, through `Evaluations`: a transaction of its own, and the
 * "evaluation failed" flag on a failure that is not retryable. A retryable one (a held lock, a version conflict of a
 * system run) is skipped and left for the next run. foundation.md, "Failures".
 */
final class PeriodicEvaluation
{
    private readonly Evaluations $evaluations;

    public function __construct(Runner $runner)
    {
        $this->evaluations = new Evaluations($runner);
    }

    /** @return ?bool whether the registration was evaluated; null when it was skipped for a retryable failure */
    public function evaluate(RegistrationKey $key): ?bool
    {
        try {
            $this->evaluations->evaluate($key, new SystemActor());

            return true;
        } catch (KalakaException $error) {
            return Evaluations::retryable($error) ? null : false;
        }
    }
}
