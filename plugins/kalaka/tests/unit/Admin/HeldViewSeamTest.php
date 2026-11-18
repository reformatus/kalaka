<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

/**
 * Registration data is reached only through a held view or full access (foundation.md, "Views and
 * grants"), so only `Admin\Api\Access` makes a `HeldView`, after the access check. Its constructor is
 * public, so this guard reads every plugin's source with PHP's tokenizer: no other class writes
 * `new HeldView`. Tests make their own.
 */
final class HeldViewSeamTest extends TestCase
{
    private const ALLOWED = ['kalaka/src/Admin/Api/Access.php'];

    public function testOnlyAccessMakesAHeldView(): void
    {
        $root = dirname(__DIR__, 4);
        $sources = glob($root . '/*/src', GLOB_ONLYDIR);

        self::assertNotEmpty($sources);
        $found = [];
        foreach ((array) $sources as $source) {
            array_push($found, ...self::violations($source, $root));
        }
        self::assertSame([], array_values(array_diff($found, self::ALLOWED)));
        self::assertContains('kalaka/src/Admin/Api/Access.php', $found, 'Access makes the held views.');
    }

    public function testTheCheckFindsTheFixture(): void
    {
        $fixture = __DIR__ . '/HeldViewFixture';

        self::assertSame(['MakesHeldView.php'], self::violations($fixture, $fixture));
    }

    /** @return list<string> the files, relative to `$base`, that write `new HeldView` */
    private static function violations(string $dir, string $base): array
    {
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads a source file
            if (self::makesHeldView((string) file_get_contents($file->getPathname()))) {
                $found[] = substr($file->getPathname(), strlen($base) + 1);
            }
        }
        sort($found);

        return $found;
    }

    private static function makesHeldView(string $source): bool
    {
        $afterNew = false;
        foreach (token_get_all($source) as $token) {
            if (!is_array($token)) {
                $afterNew = false;
                continue;
            }
            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if ($token[0] === T_NEW) {
                $afterNew = true;
                continue;
            }
            $name = in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) ? $token[1] : '';
            if ($afterNew && ($name === 'HeldView' || str_ends_with($name, '\\HeldView'))) {
                return true;
            }
            $afterNew = false;
        }

        return false;
    }
}
