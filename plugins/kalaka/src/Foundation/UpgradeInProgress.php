<?php

declare(strict_types=1);

namespace Kalaka\Foundation;

/**
 * Another request holds the module's schema-upgrade lock. The module is not booted for this request,
 * which closes the site for it, but the boot does not log it: the upgrade is running.
 *
 * @internal
 */
final class UpgradeInProgress extends \RuntimeException
{
}
