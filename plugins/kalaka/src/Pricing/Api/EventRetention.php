<?php

declare(strict_types=1);

namespace Kalaka\Pricing\Api;

use Kalaka\Pricing\RetentionRepository;

/**
 * The one read of an event's retention rule: the stored ID, or the default when none is stored, and the rule
 * registered under it. The composition, the money settings and the status-act preview all read it here.
 * [UNAPPROVED N671]
 */
final class EventRetention
{
    public function __construct(private readonly RetentionRepository $rules = new RetentionRepository())
    {
    }

    /** The ID stored for the event (the default when none is), whether or not a rule is registered under it. */
    public function idOf(int $eventId): string
    {
        $record = $this->rules->find($eventId);

        return $record === null ? Retention::DEFAULT : $record->rule;
    }

    /** The registered rule of the ID stored for the event; null when none is (its extension is not active now). */
    public function find(int $eventId): ?RetentionRule
    {
        return RetentionRules::find($this->idOf($eventId));
    }

    /**
     * @throws FeeComponentFailed named `retention` when no rule is registered under the stored ID, so the
     *     evaluation flags the registration like any failing component, until a rule is chosen again
     */
    public function ruleOf(int $eventId): RetentionRule
    {
        $rule = $this->find($eventId);
        if ($rule === null) {
            $error = new FeeComponentFailed(Retention::LINE);
            throw $error;
        }

        return $rule;
    }
}
