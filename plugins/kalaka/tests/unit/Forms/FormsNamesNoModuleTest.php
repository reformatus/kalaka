<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Forms;

use PHPUnit\Framework\TestCase;

/**
 * Forms stays module-agnostic: another module's blocks reach it only through a `BlockHost` contribution, never
 * by name (Forms/AGENTS.md).
 */
final class FormsNamesNoModuleTest extends TestCase
{
    public function testFormsSourceNamesNoMailBlockOrClass(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(dirname(__DIR__, 3) . '/src/Forms', \FilesystemIterator::SKIP_DOTS)
        );
        $checked = 0;
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || !in_array($file->getExtension(), ['php', 'js', 'json'], true)) {
                continue;
            }
            $checked++;
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads a source file
            $source = (string) file_get_contents($file->getPathname());
            self::assertDoesNotMatchRegularExpression(
                '~kalaka/mail-|Kalaka\\\\Mail\\\\|HOST_ONLY_BLOCKS~',
                $source,
                $file->getPathname()
            );
        }
        self::assertGreaterThan(10, $checked);
    }
}
