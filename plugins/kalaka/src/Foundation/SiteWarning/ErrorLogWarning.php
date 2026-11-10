<?php

declare(strict_types=1);

namespace Kalaka\Foundation\SiteWarning;

use Kalaka\Foundation\Api\SiteWarning\Severity;
use Kalaka\Foundation\Api\SiteWarning\SiteHealth;
use Kalaka\Foundation\Api\SiteWarning\Shown;
use Kalaka\Foundation\Api\SiteWarning\SiteWarning;

/**
 * PHP's error log lies under the web root, so it may be downloadable: a banner and a Site Health
 * test. engineering.md, "Platform"; foundation.md, "The error log". Rotation cannot be checked.
 */
final class ErrorLogWarning implements SiteWarning
{
    public const SITE_HEALTH_TEST = 'kalaka_error_log';

    private const TITLE = 'A PHP-hibanapló a webgyökér alatt van, így letölthető lehet';

    private const DESCRIPTION = 'A napló személyes adatot tartalmazhat. A webgyökéren kívülre kell tenni '
        . '(tárhelybeállítás vagy WP_DEBUG_LOG), és 30 naponként törölni.';

    private const GOOD_LABEL = 'A PHP-hibanapló a webgyökéren kívül van';

    private const GOOD_DESCRIPTION = 'A napló 30 naponkénti törlését a tárhelyen kell beállítani.';

    public function id(): string
    {
        return 'error_log';
    }

    /** The details are the active log path. */
    public function check(): ?Shown
    {
        $path = (string) ini_get('error_log');
        if (!self::isUnderWebRoot($path)) {
            return null;
        }

        return new Shown(Severity::Error, self::TITLE, self::DESCRIPTION, $path);
    }

    public function siteHealth(): SiteHealth
    {
        return new SiteHealth(self::SITE_HEALTH_TEST, self::GOOD_LABEL, self::GOOD_DESCRIPTION);
    }

    /**
     * Unset (the server's own log) and `syslog` are outside. A relative path is PHP's, relative to
     * the working directory. A path is compared with `.`, `..` and the symbolic links of its
     * longest existing part resolved.
     */
    private static function isUnderWebRoot(string $path): bool
    {
        if ($path === '' || $path === 'syslog') {
            return false;
        }
        if (!str_starts_with($path, '/')) {
            $path = (string) getcwd() . '/' . $path;
        }

        return str_starts_with(self::resolved($path) . '/', rtrim(self::resolved(ABSPATH), '/') . '/');
    }

    private static function resolved(string $path): string
    {
        $tail = [];
        for ($head = $path; $head !== '/' && $head !== '.' && $head !== ''; $head = dirname($head)) {
            $real = realpath($head);
            if ($real !== false) {
                return self::normalized($real . '/' . implode('/', $tail));
            }
            array_unshift($tail, basename($head));
        }

        return self::normalized($path);
    }

    private static function normalized(string $path): string
    {
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '..') {
                array_pop($parts);
            } elseif ($part !== '' && $part !== '.') {
                $parts[] = $part;
            }
        }

        return '/' . implode('/', $parts);
    }
}
