<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Foundation;

use PHPUnit\Framework\TestCase;

/**
 * Every entry point runs through the error boundary (foundation.md, "Errors"; engineering.md, "Static
 * checks"): `register_rest_route()` is called only inside `Api\Entry\Boundary`, `WP_CLI::add_command()`
 * only registers a command class kept in a `Cli/` folder as `*Command.php`, and every public method of
 * such a class but its constructor, static ones and ones without a body starts with `Boundary::cli(`.
 */
final class EntryPointsTest extends TestCase
{
    private const BOUNDARY = '/kalaka/src/Foundation/Api/Entry/Boundary.php';

    /** @return list<string> */
    private static function sources(): array
    {
        $sources = glob(dirname(__DIR__, 4) . '/*/src', GLOB_ONLYDIR);
        self::assertNotEmpty($sources);

        return $sources;
    }

    public function testOnlyTheBoundaryRegistersRestRoutes(): void
    {
        $callers = [];
        foreach (self::sources() as $src) {
            $callers = [...$callers, ...self::filesCalling($src, 'register_rest_route')];
        }

        self::assertCount(1, $callers);
        self::assertStringEndsWith(self::BOUNDARY, str_replace('\\', '/', $callers[0]));
    }

    public function testEveryCommandMethodStartsWithTheBoundary(): void
    {
        foreach (self::sources() as $src) {
            self::assertSame([], self::unguardedCommandMethods($src), 'In the plugin ' . basename(dirname($src)));
        }
    }

    public function testCommandsAreRegisteredOnlyFromCommandClasses(): void
    {
        $commands = [];
        foreach (self::sources() as $src) {
            foreach (self::files($src) as $file) {
                if (self::isCommandFile($file)) {
                    $commands[] = basename($file, '.php');
                }
            }
        }
        foreach (self::sources() as $src) {
            foreach (self::filesCalling($src, 'add_command') as $file) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads a source file
                $code = (string) file_get_contents($file);
                preg_match_all('/WP_CLI::add_command\(\s*[^,]+,\s*([^,)]+)/', $code, $matches);
                foreach ($matches[1] as $callable) {
                    $callable = trim($callable);
                    if (str_ends_with($callable, '::class')) {
                        $class = substr($callable, 0, -strlen('::class'));
                        $short = substr((string) strrchr('\\' . ltrim($class, '\\'), '\\'), 1);
                        $ok = in_array($short, $commands, true);
                    } else {
                        $ok = ($callable === 'new self' || str_starts_with($callable, 'new self('))
                            && self::isCommandFile($file);
                    }
                    self::assertTrue($ok, $file . ' registers ' . $callable);
                }
            }
        }
    }

    public function testTheChecksFindTheFixtures(): void
    {
        $routes = self::filesCalling(__DIR__ . '/EntryFixture', 'register_rest_route');
        self::assertCount(1, $routes);
        self::assertStringEndsWith('UnguardedRoute.php', $routes[0]);

        self::assertSame(
            ['PartlyGuardedCommand::bare', 'UnguardedCommand::__invoke'],
            array_map(
                static fn (string $method): string => basename($method),
                self::unguardedCommandMethods(__DIR__ . '/EntryFixture')
            )
        );
    }

    /** @return list<string> the PHP files under the root */
    private static function files(string $root): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (str_ends_with($file->getPathname(), '.php')) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /**
     * The tokens of a file without whitespace and comments.
     *
     * @return list<array{int, string, int}|string>
     */
    private static function tokens(string $file): array
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads a source file
        $code = (string) file_get_contents($file);

        return array_values(array_filter(
            token_get_all($code),
            static fn ($token): bool => !is_array($token)
                || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));
    }

    /** @return list<string> the files that call the function, globally or as a static method */
    private static function filesCalling(string $root, string $function): array
    {
        $files = [];
        foreach (self::files($root) as $file) {
            $tokens = self::tokens($file);
            foreach ($tokens as $i => $token) {
                $name = is_array($token) && in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)
                    ? ltrim($token[1], '\\')
                    : null;
                $before = $tokens[$i - 1] ?? null;
                $declared = is_array($before) && $before[0] === T_FUNCTION;
                if ($name === $function && ($tokens[$i + 1] ?? null) === '(' && !$declared) {
                    $files[] = $file;
                    break;
                }
            }
        }

        return $files;
    }

    /** A command class's file: `*Command.php` directly in a `Cli/` folder. */
    private static function isCommandFile(string $file): bool
    {
        $path = str_replace('\\', '/', $file);

        return basename(dirname($path)) === 'Cli' && str_ends_with($path, 'Command.php');
    }

    /** @return list<string> `<file>::<method>` of every command method that does not start with the boundary */
    private static function unguardedCommandMethods(string $root): array
    {
        $unguarded = [];
        foreach (self::files($root) as $file) {
            if (!self::isCommandFile($file)) {
                continue;
            }
            $tokens = self::tokens($file);
            foreach ($tokens as $i => $token) {
                if (!is_array($token) || $token[0] !== T_FUNCTION) {
                    continue;
                }
                $next = $tokens[$i + 1] ?? null;
                if (!is_array($next) || $next[0] !== T_STRING) {
                    continue; // a closure, not a method
                }
                $name = $next[1];
                if (!self::isPublicInstanceMethod($tokens, $i) || $name === '__construct') {
                    continue;
                }
                if (self::bodyStartsWithBoundary($tokens, $i) === false) {
                    $unguarded[] = substr($file, 0, -4) . '::' . $name;
                }
            }
        }

        return $unguarded;
    }

    /** @param list<array{int, string, int}|string> $tokens */
    private static function isPublicInstanceMethod(array $tokens, int $function): bool
    {
        // Without a visibility keyword a method is public.
        for ($j = $function - 1; $j >= 0; $j--) {
            $token = $tokens[$j];
            $modifiers = [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_FINAL, T_ABSTRACT];
            if (!is_array($token) || !in_array($token[0], $modifiers, true)) {
                break;
            }
            if ($token[0] === T_STATIC || $token[0] === T_PRIVATE || $token[0] === T_PROTECTED) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<array{int, string, int}|string> $tokens
     * @return bool|null null for a method without a body
     */
    private static function bodyStartsWithBoundary(array $tokens, int $function): ?bool
    {
        $depth = 0;
        for ($j = $function + 1; $j < count($tokens); $j++) {
            if ($tokens[$j] === '(') {
                $depth++;
            } elseif ($tokens[$j] === ')') {
                $depth--;
            } elseif ($tokens[$j] === ';' && $depth === 0) {
                return null;
            } elseif ($tokens[$j] === '{' && $depth === 0) {
                $first = $tokens[$j + 1] ?? null;
                $second = $tokens[$j + 2] ?? null;
                $third = $tokens[$j + 3] ?? null;

                return is_array($first) && ltrim($first[1], '\\') === 'Boundary'
                    && is_array($second) && $second[0] === T_DOUBLE_COLON
                    && is_array($third) && $third[1] === 'cli';
            }
        }

        return null;
    }
}
