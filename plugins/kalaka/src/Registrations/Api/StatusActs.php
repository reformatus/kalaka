<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

use Kalaka\Registrations\RegistrationRepository;

/**
 * Which status acts a registration's status allows now: a plain read, for the detail read's `can` flags and the
 * preview. It answers the acts only, never the row, so a reader outside Registrations' data layer may ask it. The
 * grant is a separate question (the runner's coarse check).
 */
final class StatusActs
{
    public function __construct(private readonly RegistrationRepository $registrations = new RegistrationRepository())
    {
    }

    /**
     * @return list<StatusAct> empty when there is no such registration
     */
    public function allowedFor(RegistrationKey $key): array
    {
        $status = $this->registrations->find($key)?->status;
        if ($status === null) {
            return [];
        }

        return array_values(array_filter(
            StatusAct::cases(),
            static fn (StatusAct $act): bool => $act->allowedFrom($status)
        ));
    }
}
