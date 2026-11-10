<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Foundation;

use PHPUnit\Framework\TestCase;

/**
 * Only `Foundation\ErrorLog` calls `error_log()`; no other file of any plugin's `src` does
 * (foundation.md, "Failures", "The error log"; engineering.md, "Static checks").
 */
final class ErrorLogCallersTest extends TestCase
{
    private const WRITER = '/kalaka/src/Foundation/ErrorLog.php';

    public function testOnlyTheWriterCallsErrorLog(): void
    {
        $sources = glob(dirname(__DIR__, 4) . '/*/src', GLOB_ONLYDIR);

        self::assertNotEmpty($sources);
        $callers = [];
        foreach ($sources as $src) {
            $callers = [...$callers, ...self::callers($src)];
        }

        self::assertCount(1, $callers);
        self::assertStringEndsWith(self::WRITER, str_replace('\\', '/', $callers[0]));
    }

    public function testTheCheckFindsTheFixture(): void
    {
        $callers = self::callers(__DIR__ . '/ErrorLogFixture');

        self::assertCount(1, $callers);
        self::assertStringEndsWith('LogsDirectly.php', $callers[0]);
    }

    /** @dataProvider sources */
    public function testACallIsFoundOnlyWhenItCallsTheFunction(string $source, bool $calls): void
    {
        self::assertSame($calls, self::calls('<?php ' . $source));
    }

    /** @return array<string, array{string, bool}> */
    public static function sources(): array
    {
        return [
            'a call' => ['error_log("x");', true],
            'a qualified call' => ['\error_log("x");', true],
            'a call with a space' => ['error_log ("x");', true],
            'a method of that name' => ['$log->error_log("x");', false],
            'a static method of that name' => ['Log::error_log("x");', false],
            'a string' => ['ini_set("error_log", "/tmp/x");', false],
            'a comment' => ['// error_log("x");', false],
            'a function of that name declared' => ['function error_log($x) {}', false],
        ];
    }

    /** @return list<string> the files that call `error_log()` */
    private static function callers(string $root): array
    {
        $callers = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $path = $file->getPathname();
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads a source file
            if (str_ends_with($path, '.php') && self::calls((string) file_get_contents($path))) {
                $callers[] = $path;
            }
        }
        sort($callers);

        return $callers;
    }

    /** Whether the code calls the global `error_log()`: its name followed by `(`, not a method or declaration. */
    private static function calls(string $code): bool
    {
        $tokens = array_values(array_filter(
            token_get_all($code),
            static fn ($token): bool => !is_array($token)
                || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));
        foreach ($tokens as $i => $token) {
            $name = is_array($token) && in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)
                ? ltrim($token[1], '\\')
                : null;
            if ($name !== 'error_log' || ($tokens[$i + 1] ?? null) !== '(') {
                continue;
            }
            $before = $tokens[$i - 1] ?? null;
            $notACall = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION];
            if (is_array($before) && in_array($before[0], $notACall, true)) {
                continue;
            }

            return true;
        }

        return false;
    }
}
