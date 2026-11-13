<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\UseCase;

/**
 * A portal use case that acts on no existing registration, such as submitting a registration form: any
 * portal-side actor may run it, a guest included. Its `registrations()` is always empty.
 */
interface PublicUseCase extends PortalUseCase
{
}
