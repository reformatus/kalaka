<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Decision;

use Kalaka\Foundation\Api\Actor\AccountActor;
use Kalaka\Foundation\Api\Actor\Actor;
use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Actor\SecretLinkActor;
use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Registrations\Api\RegistrationKey;

/** One stored transition of a registration's decision; times are in UTC. */
final class DecisionRecord
{
    public function __construct(
        public readonly RegistrationKey $registration,
        public readonly string $kind,
        public readonly string $state,
        public readonly \DateTimeImmutable $effectiveAt,
        public readonly \DateTimeImmutable $recordedAt,
        public readonly Subject $cause,
        public readonly string $actorType,
        public readonly ?int $actorUserId,
        public readonly ?int $actorEventId,
        public readonly ?string $actorCode,
        public readonly int $id = 0,
    ) {
    }

    /** The actor columns are filled as the audit entry's are. */
    public static function by(
        Actor $actor,
        RegistrationKey $registration,
        string $kind,
        Transition $transition,
        \DateTimeImmutable $recordedAt,
    ): self {
        return new self(
            $registration,
            $kind,
            $transition->state,
            $transition->effectiveAt,
            $recordedAt,
            $transition->cause,
            $actor->type(),
            $actor instanceof UserActor || $actor instanceof AccountActor || $actor instanceof CliActor
                ? $actor->userId : null,
            $actor instanceof SecretLinkActor ? $actor->registration->eventId : null,
            $actor instanceof SecretLinkActor ? $actor->registration->code : null,
        );
    }
}
