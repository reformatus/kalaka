<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\UseCase;

use Kalaka\Admin\Api\AdminAccess;
use Kalaka\Foundation\Api\Actor\AccountActor;
use Kalaka\Foundation\Api\Actor\Actor;
use Kalaka\Foundation\Api\Actor\SecretLinkActor;
use Kalaka\Foundation\Api\Actor\Side;
use Kalaka\Foundation\Api\Error\PermissionDenied;

/**
 * The coarse permission check of an outermost run: foundation.md, "The use-case runner".
 *
 * @internal
 */
final class CoarsePermission
{
    /** @param array<string, mixed> $input the sanitized input */
    public function check(UseCase $useCase, Side $side, array $input, Actor $actor): void
    {
        if ($actor->side() !== $side) {
            throw new PermissionDenied();
        }

        if ($useCase instanceof AdminUseCase) {
            AdminAccess::check($useCase, $input, $actor);
        } elseif ($useCase instanceof PortalUseCase) {
            $this->portal($useCase, $input, $actor);
        } else {
            throw new PermissionDenied();
        }
    }

    /** @param array<string, mixed> $input */
    private function portal(PortalUseCase $useCase, array $input, Actor $actor): void
    {
        $registrations = $useCase->registrations($input);

        // A public use case acts on no existing registration: any portal-side actor, a guest included.
        if ($useCase instanceof PublicUseCase) {
            if ($registrations !== []) {
                throw new PermissionDenied();
            }

            return;
        }

        if ($actor instanceof SecretLinkActor) {
            if ($registrations === []) {
                throw new PermissionDenied();
            }
            foreach ($registrations as $key) {
                if ($key->eventId !== $actor->registration->eventId || $key->code !== $actor->registration->code) {
                    throw new PermissionDenied();
                }
            }

            return;
        }

        // Until S15 records which account owns a registration, an account passes
        // for account-level actions only.
        if ($actor instanceof AccountActor && $registrations === []) {
            return;
        }

        throw new PermissionDenied();
    }
}
