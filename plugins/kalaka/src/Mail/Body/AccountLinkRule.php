<?php

declare(strict_types=1);

namespace Kalaka\Mail\Body;

use Kalaka\Events\Api\AccountMode;
use Kalaka\Events\Api\Events;
use Kalaka\Mail\Api\ActionMail;
use Kalaka\Mail\Api\ActionMailSettings;

/**
 * S15 (owner ruling 2026-11-16): in an event whose account mode is `mandatory` or `optional`, the set-password
 * link reaches a new account only inside the "Jelentkezés rögzítve" mail (`account.password_link`). So there,
 * that mail's template must read the key, or its save is refused (`BodyCheck`, and `UpdateActionMail` when a
 * template is assigned or the mail switched on), and the mail cannot be switched off (`UpdateActionMail`).
 */
final class AccountLinkRule
{
    public const NEEDS_LINK = 'Ennél az eseménynél fiókkal lehet jelentkezni, ezért a „Jelentkezés rögzítve” '
        . 'levél sablonjának tartalmaznia kell a jelszóbeállító linket (account.password_link).';

    public const NO_SWITCH_OFF = 'Ennél az eseménynél fiókkal lehet jelentkezni, ezért a „Jelentkezés rögzítve” '
        . 'levél nem kapcsolható ki.';

    public function __construct(
        private readonly Events $events = new Events(),
        private readonly ActionMailSettings $settings = new ActionMailSettings(),
    ) {
    }

    /** Whether the event has accounts, so the rule holds for it. */
    public function applies(int $eventId): bool
    {
        $event = $this->events->find($eventId);

        return $event !== null && $event->accountMode !== AccountMode::Off;
    }

    /**
     * The refusal of a template's save, or null: the template is the event's "Jelentkezés rögzítve" mail's,
     * the event has accounts, and the content does not read `account.password_link`.
     */
    public function problem(int $bodyId, string $content, ?int $eventId): ?string
    {
        if ($bodyId < 1 || $eventId === null || MailValues::readsPasswordLink($content) || !$this->applies($eventId)) {
            return null;
        }
        $registered = $this->settings->ofEvent($eventId)->mail(ActionMail::Registered);

        return $registered->bodyId === $bodyId ? self::NEEDS_LINK : null;
    }
}
