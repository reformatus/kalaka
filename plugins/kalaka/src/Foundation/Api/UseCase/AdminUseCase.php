<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\UseCase;

use Kalaka\Registrations\Api\RegistrationKey;

interface AdminUseCase extends UseCase
{
    /** The per-event action a grant must carry; null for a field change only. */
    public function action(): ?string;

    /**
     * The event the call acts in; null for a site-level use case, which needs full access.
     *
     * @param array<string, mixed> $input
     */
    public function event(array $input): ?int;

    /**
     * The registrations this call acts on, checked against the grant's view filter at run time.
     *
     * @param array<string, mixed> $input
     * @return list<RegistrationKey>
     */
    public function targets(array $input): array;
}
