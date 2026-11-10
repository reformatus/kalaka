<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\SiteWarning;

/**
 * A standing condition: the site cannot work correctly until something changes. Shown as an admin
 * banner, and as a Site Health test when it is an environment requirement: foundation.md,
 * "Failures", "Site warnings". A module contributes one with `Contributions::siteWarning()`.
 */
interface SiteWarning
{
    /** Unique among the site's warnings; names the warning in the error log. */
    public function id(): string;

    /**
     * What to show, or null while all is well. Writes nothing, is cheap (memory, options or a value
     * cached for the request: it runs on every admin page) and never throws.
     */
    public function check(): ?Shown;

    /** The Site Health test, for an environment requirement only; null otherwise. Cheap, writes nothing. */
    public function siteHealth(): ?SiteHealth;
}
