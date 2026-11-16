<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Account;

use Kalaka\Events\Api\AccountMode;
use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\SiteWarning\Severity;
use Kalaka\Foundation\Api\SiteWarning\SiteHealth;
use Kalaka\Foundation\Api\SiteWarning\Shown;
use Kalaka\Foundation\Api\SiteWarning\SiteWarning;

/**
 * S15: a published event whose account mode is `mandatory` or `optional` needs WordPress's registration page
 * open ("Anyone can register", `users_can_register`) and new users made `subscriber` (`default_role`). These
 * are site settings Kaláka does not change; this warning names the wrong one.
 * // [UNAPPROVED T3] the warning's texts.
 */
final class SettingsWarning implements SiteWarning
{
    public const TITLE = 'A fiókos jelentkezéshez módosítani kell a WordPress beállításait';

    public const DESCRIPTION = 'Van olyan közzétett esemény, amelyhez fiók kell vagy választható, ezért a '
        . 'Beállítások > Általános oldalon kapcsold be a „Bárki regisztrálhat” lehetőséget, és az új '
        . 'felhasználók alapértelmezett szerepköre legyen „Előfizető”.';

    public function __construct(private readonly Events $events = new Events())
    {
    }

    public function id(): string
    {
        return 'account_settings';
    }

    public function check(): ?Shown
    {
        $details = self::problems();
        if ($details === [] || !$this->accountsInUse()) {
            return null;
        }

        return new Shown(Severity::Error, self::TITLE, self::DESCRIPTION, implode("\n", $details));
    }

    public function siteHealth(): ?SiteHealth
    {
        return null;
    }

    /** @return list<string> the settings that are wrong, in English */
    public static function problems(): array
    {
        $problems = [];
        if ((string) get_option('users_can_register') !== '1') {
            $problems[] = 'users_can_register is off';
        }
        if (get_option('default_role') !== 'subscriber') {
            $problems[] = 'default_role is not subscriber';
        }

        return $problems;
    }

    private function accountsInUse(): bool
    {
        foreach ($this->events->published() as $event) {
            if ($event->accountMode !== AccountMode::Off) {
                return true;
            }
        }

        return false;
    }
}
