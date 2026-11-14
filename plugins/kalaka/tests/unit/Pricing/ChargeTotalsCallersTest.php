<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Pricing;

use PHPUnit\Framework\TestCase;

/**
 * Rules never read charge lines (money.md, "Materializer"): only `Pricing\Api\Materializer` calls
 * `Ledger::chargeTotalsOf()`, besides the method's own declaration in the Ledger `Api`.
 */
final class ChargeTotalsCallersTest extends TestCase
{
    private const MATERIALIZER = '/kalaka/src/Pricing/Api/Materializer.php';

    public function testOnlyTheMaterializerCallsIt(): void
    {
        $sources = glob(dirname(__DIR__, 4) . '/*/src', GLOB_ONLYDIR);
        self::assertNotEmpty($sources);

        $callers = [];
        foreach ($sources as $src) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($files as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads a source file
                $code = (string) file_get_contents($file->getPathname());
                if (preg_match('/->\s*chargeTotalsOf\s*\(/', $code) === 1) {
                    $callers[] = str_replace('\\', '/', $file->getPathname());
                }
            }
        }

        self::assertCount(1, $callers);
        self::assertStringEndsWith(self::MATERIALIZER, $callers[0]);
    }
}
