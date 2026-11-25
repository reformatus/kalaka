<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\UseCase;

/**
 * A use case that can run as a preview: the same checks and the same answer as the real run, from plain reads,
 * and nothing written. The runner then writes no audit entry for a preview that succeeded (foundation.md,
 * "Audit"): the one rule for every preview, so no use case decides it. A preview that refused is audited like
 * any refusal. A preview that touched a registration is a programming error and fails. [UNAPPROVED N534]
 */
interface PreviewableUseCase extends UseCase
{
    /**
     * Whether this call is a preview. Asked of the checked input.
     *
     * @param array<string, mixed> $input
     */
    public function isPreview(array $input): bool;
}
