<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Foundation;

use PHPUnit\Framework\TestCase;

/**
 * PHPCS's direct-query rule exempts only `Foundation\Database\Statements`; no file of any plugin's
 * `src` silences the rule with a `phpcs:ignore` or `phpcs:disable`, whether it names the rule, a standard
 * or category that contains it, or nothing (engineering.md, "Static checks").
 */
final class DirectQueryExemptionsTest extends TestCase
{
    public function testNoPluginSourceFileSilencesTheDirectQueryRule(): void
    {
        $sources = glob(dirname(__DIR__, 4) . '/*/src', GLOB_ONLYDIR);

        self::assertNotEmpty($sources);
        foreach ($sources as $src) {
            self::assertSame([], self::violations($src), 'In the plugin ' . basename(dirname($src)));
        }
    }

    public function testTheCheckFindsTheSilencedFixture(): void
    {
        $violations = self::violations(__DIR__ . '/DirectQueryFixture');

        self::assertCount(1, $violations);
        self::assertStringEndsWith('SilencedStatement.php', $violations[0]);
    }

    /** @dataProvider comments */
    public function testACommentSilencesTheRuleWhenItNamesIt(string $comment, bool $silences): void
    {
        self::assertSame($silences, self::silences($comment));
    }

    /** @return array<string, array{string, bool}> */
    public static function comments(): array
    {
        return [
            'the rule' => ['// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- why', true],
            'one of its sniffs' => ['// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching', true],
            'among others' => ['// phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.DirectDatabaseQuery', true],
            'a disable' => ['// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange', true],
            'another rule' => ['// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared', false],
            'an enable' => ['// phpcs:enable WordPress.DB.DirectDatabaseQuery', false],
            'only in the reason' => ['// phpcs:ignore Generic -- WordPress.DB.DirectDatabaseQuery', false],
            'no sniff list' => ['// phpcs:ignore', true],
            'no sniff list, with a reason' => ['// phpcs:disable -- everything', true],
            'its category' => ['// phpcs:ignore WordPress.DB', true],
            'its standard' => ['// phpcs:ignore WordPress -- why', true],
            'a similar name' => ['// phpcs:ignore WordPress.DBX', false],
            'a whole file' => ['// phpcs:ignoreFile', true],
            'a block comment with no list' => ['/* phpcs:disable */', true],
            'a block comment naming it' => ['/* phpcs:disable WordPress.DB.DirectDatabaseQuery */', true],
            'a block comment naming another rule' => ['/* phpcs:disable Generic.Files */', false],
        ];
    }

    /** @return list<string> the files that silence the rule */
    private static function violations(string $root): array
    {
        $violations = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $path = $file->getPathname();
            if (!str_ends_with($path, '.php')) {
                continue;
            }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads a source file
            foreach (token_get_all((string) file_get_contents($path)) as $token) {
                $comment = is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true);
                if ($comment && self::silences($token[1])) {
                    $violations[] = $path;
                    break;
                }
            }
        }
        sort($violations);

        return $violations;
    }

    /**
     * Whether the comment silences the rule: a `phpcs:ignoreFile`, a `phpcs:ignore` or `phpcs:disable`
     * with no sniff list (it silences everything), or one whose list, before any ` -- ` reason, names
     * the rule, one of its sniffs, or a standard or category that contains it.
     */
    private static function silences(string $comment): bool
    {
        if (str_contains($comment, 'phpcs:ignoreFile')) {
            return true;
        }
        if (preg_match('/phpcs:(?:ignore|disable)\b([^\n]*)/', $comment, $match) !== 1) {
            return false;
        }
        $list = trim(explode(' --', $match[1], 2)[0]);
        $list = trim(preg_replace('/\*\/.*$/', '', $list) ?? $list);
        if ($list === '') {
            return true;
        }

        $rule = 'WordPress.DB.DirectDatabaseQuery';
        foreach (explode(',', $list) as $name) {
            $name = trim($name);
            if ($name === $rule || str_starts_with($name, $rule . '.') || str_starts_with($rule, $name . '.')) {
                return true;
            }
        }

        return false;
    }
}
