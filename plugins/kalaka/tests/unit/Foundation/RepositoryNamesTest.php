<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Foundation;

use Kalaka\Foundation\Api\Query\Query;
use Kalaka\Foundation\Api\Repository\AppendOnlyRepository;
use Kalaka\Foundation\Api\Repository\Repository;
use PHPUnit\Framework\TestCase;

/**
 * PHPCS exempts files named `*Repository.php`, `*List.php` and `*Query.php` from the direct-query
 * rule, so a name alone must never escape it: every such file of every plugin, outside core's two
 * base folders, declares a class that extends the base (engineering.md, "Static checks").
 */
final class RepositoryNamesTest extends TestCase
{
    public function testEveryRepositoryAndQueryFileUnderEveryPluginExtendsItsBase(): void
    {
        $plugins = dirname(__DIR__, 4);
        $sources = glob($plugins . '/*/src', GLOB_ONLYDIR);

        self::assertNotEmpty($sources);
        foreach ($sources as $src) {
            $plugin = basename(dirname($src));
            $autoload = dirname($src) . '/vendor/autoload.php';

            self::assertFileExists(
                $autoload,
                'The plugin ' . $plugin . ' has src/ but no vendor/autoload.php; run composer install in plugins/'
                    . $plugin . '.'
            );
            require_once $autoload;

            $skipped = $plugin === 'kalaka'
                ? [$src . '/Foundation/Api/Repository', $src . '/Foundation/Api/Query']
                : [];
            self::assertSame([], self::violations($src, $skipped), 'In the plugin ' . $plugin);
        }
    }

    public function testTheCheckRejectsARepositoryThatExtendsNothingAndAcceptsTheBases(): void
    {
        $violations = self::violations(__DIR__ . '/NameCheckFixture', []);

        self::assertCount(1, $violations);
        self::assertStringEndsWith('BadRepository.php', $violations[0]);
    }

    public function testAnAppendOnlyRepositoryHasNoWayToUpdateOrDelete(): void
    {
        $class = new \ReflectionClass(AppendOnlyRepository::class);
        $names = [];
        foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC | \ReflectionMethod::IS_PROTECTED) as $method) {
            $names[] = $method->getName();
        }

        self::assertContains('insert', $names);
        self::assertSame([], preg_grep('/update|upsert|delete/i', $names));
        self::assertTrue($class->isSubclassOf(Repository::class));
    }

    /**
     * @param list<string> $skipped folders whose files are the bases themselves
     * @return list<string> the files that break the rule
     */
    private static function violations(string $root, array $skipped): array
    {
        $violations = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $path = $file->getPathname();
            if (preg_match('/(Repository|List|Query)\.php$/', $path, $match) !== 1) {
                continue;
            }
            foreach ($skipped as $folder) {
                if (str_starts_with($path, $folder . '/')) {
                    continue 2;
                }
            }

            $base = $match[1] === 'Repository' ? Repository::class : Query::class;
            $class = self::classOf($path);
            if ($class === null || !is_subclass_of($class, $base)) {
                $violations[] = $path;
            }
        }
        sort($violations);

        return $violations;
    }

    /** The class a file declares, loaded; null when it declares none. */
    private static function classOf(string $path): ?string
    {
        $namespace = '';
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads a plugin source file
        $tokens = token_get_all((string) file_get_contents($path));
        foreach ($tokens as $i => $token) {
            if (!is_array($token)) {
                continue;
            }
            if ($token[0] === T_NAMESPACE) {
                $namespace = self::nameAfter($tokens, $i);
            } elseif ($token[0] === T_CLASS && !self::followsDoubleColon($tokens, $i)) {
                $class = ($namespace === '' ? '' : $namespace . '\\') . self::nameAfter($tokens, $i);
                if (!class_exists($class)) {
                    require_once $path;
                }

                return class_exists($class) ? $class : null;
            }
        }

        return null;
    }

    /** @param list<array{int, string, int}|string> $tokens */
    private static function nameAfter(array $tokens, int $from): string
    {
        $name = '';
        for ($i = $from + 1; $i < count($tokens); $i++) {
            $token = $tokens[$i];
            if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_QUALIFIED], true)) {
                $name .= $token[1];
            } elseif (
                $token === ';'
                || $token === '{'
                || ($name !== '' && is_array($token) && $token[0] === T_WHITESPACE)
            ) {
                break;
            }
        }

        return $name;
    }

    /** @param list<array{int, string, int}|string> $tokens */
    private static function followsDoubleColon(array $tokens, int $at): bool
    {
        return ($tokens[$at - 1] ?? null) === '::';
    }
}
