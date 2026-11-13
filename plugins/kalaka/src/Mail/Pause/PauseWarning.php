<?php

declare(strict_types=1);

namespace Kalaka\Mail\Pause;

use Kalaka\Foundation\Api\SiteWarning\Severity;
use Kalaka\Foundation\Api\SiteWarning\Shown;
use Kalaka\Foundation\Api\SiteWarning\SiteHealth;
use Kalaka\Foundation\Api\SiteWarning\SiteWarning;

/**
 * The two mail warnings: sending paused by hand (info) and paused after errors (error), neither a Site
 * Health test. Both read the state row once per request, shared between them, and never the deliveries
 * table. The texts are the owner's (approved 2026-11-10). foundation.md, "Site warnings"; mail.md, "Pausing".
 */
final class PauseWarning implements SiteWarning
{
    private const MANUAL_TITLE = 'A levélküldés szünetel';

    private const MANUAL_DESCRIPTION = 'A levelek a küldési sorban gyűlnek.'
        . ' A küldés a Kaláka › Levelezés oldalon folytatható.';

    private const ERRORS_TITLE = 'A levélküldés hiba miatt szünetel';

    private const ERRORS_DESCRIPTION = 'Két egymást követő küldési kísérlet hibával zárult, ezért a Kaláka'
        . ' szüneteltette a küldést. A levelek a küldési sorban gyűlnek. A hiba oka jellemzően a levélküldő'
        . ' bővítmény beállításaiban vagy a levelezőszolgáltatónál van; ezt kell elhárítani, a küldés csak ezután'
        . ' folytatható a Kaláka › Levelezés oldalon. Folytatáskor a Kaláka először a hibát jelző levelet próbálja'
        . ' újra elküldeni; ha az ismét hibát jelez, a küldés azonnal újra szünetel.';

    public function __construct(private readonly PauseCause $cause, private readonly RequestState $state)
    {
    }

    public function id(): string
    {
        return $this->cause === PauseCause::Manual ? 'mail_paused' : 'mail_paused_after_errors';
    }

    public function check(): ?Shown
    {
        $state = $this->state->get();
        if ($state->pausedAt === null || $state->cause !== $this->cause) {
            return null;
        }
        if ($this->cause === PauseCause::Manual) {
            return new Shown(Severity::Info, self::MANUAL_TITLE, self::MANUAL_DESCRIPTION);
        }

        return new Shown(Severity::Error, self::ERRORS_TITLE, self::ERRORS_DESCRIPTION, self::details($state));
    }

    public function siteHealth(): ?SiteHealth
    {
        return null;
    }

    /**
     * The delivery's key, the time and the raw error with its correlation ID (owner's ruling on S8-2).
     * English, one item per line.
     */
    private static function details(MailState $state): string
    {
        $lines = [];
        if ($state->deliveryId !== null) {
            $lines[] = 'Delivery ' . $state->deliveryId . ' of event ' . ($state->deliveryEventId ?? 0);
        }
        if ($state->pausedAt !== null) {
            $lines[] = 'Paused at ' . wp_date('Y-m-d H:i:s', $state->pausedAt->getTimestamp());
        }
        if ($state->error !== null) {
            $lines[] = 'Error: ' . $state->error;
        }

        return implode("\n", $lines);
    }
}
