<?php

declare(strict_types=1);

namespace Kalaka\Foundation\SiteWarning;

use Kalaka\Foundation\Api\LoadFailure;
use Kalaka\Foundation\Api\LoadState;
use Kalaka\Foundation\Api\SiteWarning\Severity;
use Kalaka\Foundation\Api\SiteWarning\SiteHealth;
use Kalaka\Foundation\Api\SiteWarning\Shown;
use Kalaka\Foundation\Api\SiteWarning\SiteWarning;

/**
 * The load failed, so the site is closed: a banner only, from the request's own load state.
 * modules-and-extensions.md, "Failure isolation".
 */
final class LoadFailureWarning implements SiteWarning
{
    private const TITLE = 'A Kaláka nem töltődött be, ezért minden esemény zárva van';

    private const DESCRIPTION = 'A jelentkezés, a résztvevői oldalak és a levélküldés nem működik. A tárolt adatok '
        . 'érintetlenek; a hiba elhárítása után a Kaláka újra működik.';

    public function id(): string
    {
        return 'load_failure';
    }

    /** One details line per failure: `<module id>: <reason>`. */
    public function check(): ?Shown
    {
        $state = LoadState::current();
        if ($state->isOpen()) {
            return null;
        }

        return new Shown(
            Severity::Error,
            self::TITLE,
            self::DESCRIPTION,
            implode("\n", array_map(
                static fn (LoadFailure $failure): string => $failure->moduleId . ': ' . $failure->reason,
                $state->failures
            ))
        );
    }

    public function siteHealth(): ?SiteHealth
    {
        return null;
    }
}
