<?php

declare(strict_types=1);

namespace Kalaka\Mail\Pause;

use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;

/**
 * A person pauses sending: deliveries pile up until a person resumes. Site-level, so only full access
 * (and WP-CLI) passes. When sending is already paused, for either cause, nothing changes. mail.md, "Pausing".
 */
final class PauseMail implements AdminUseCase
{
    public function __construct(private readonly MailStateRepository $states = new MailStateRepository())
    {
    }

    public static function name(): string
    {
        return 'kalaka/pause-mail';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
    }

    public function action(): ?string
    {
        return null;
    }

    public function event(array $input): ?int
    {
        return null;
    }

    public function targets(array $input): array
    {
        return [];
    }

    /** @return bool whether this call paused sending; false when it was already paused */
    public function execute(array $input, Context $context): bool
    {
        $state = $this->states->forUpdate($context);
        $context->touch(MailStateRepository::subjectOf());
        if ($state->isPaused()) {
            return false;
        }

        $state->pause($context->now(), PauseCause::Manual, ActorUser::of($context->actor()));
        $this->states->upsert($state, $context);

        return true;
    }
}
