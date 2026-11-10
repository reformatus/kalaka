<?php

declare(strict_types=1);

namespace Kalaka\Mail\Pause;

use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;

/**
 * A person resumes sending, whatever paused it. The errors in a row stay, so a delivery that fails again
 * right after pauses sending at once (mail.md, "Failures"). No nudge: the next periodic run sends.
 * Site-level. mail.md, "Pausing".
 * // [UNAPPROVED S8-4] pause, resume and clear the queue as use cases, without screens.
 */
final class ResumeMail implements AdminUseCase
{
    public function __construct(private readonly MailStateRepository $states = new MailStateRepository())
    {
    }

    public static function name(): string
    {
        return 'kalaka/resume-mail';
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

    /** @return bool whether this call resumed sending; false when it was not paused */
    public function execute(array $input, Context $context): bool
    {
        $state = $this->states->forUpdate($context);
        $context->touch(MailStateRepository::subjectOf());
        if (!$state->isPaused()) {
            return false;
        }

        $state->resume();
        $this->states->upsert($state, $context);

        return true;
    }
}
