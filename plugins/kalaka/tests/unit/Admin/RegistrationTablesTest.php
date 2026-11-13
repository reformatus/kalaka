<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

/**
 * On the admin side registration data is read only through the view query (foundation.md, "Views and
 * grants"; engineering.md, "Static checks"). deptrac keeps Admin from the Registrations classes; this
 * keeps it from their tables, which a query names in a string: only `Admin\Api\ViewQuery` names them.
 *
 * [UNAPPROVED S36-7]
 */
final class RegistrationTablesTest extends TestCase
{
    private const TABLES = '/kalaka_(registrations|field_values|submissions|decisions)\b/';

    private const ALLOWED = ['Api/ViewQuery.php'];

    public function testOnlyTheViewQueryNamesARegistrationTable(): void
    {
        $root = dirname(__DIR__, 3) . '/src/Admin';

        self::assertDirectoryExists($root);
        self::assertSame([], self::violations($root));
    }

    public function testTheCheckFindsTheFixture(): void
    {
        $violations = self::violations(__DIR__ . '/RegistrationTablesFixture');

        self::assertSame(['ReadsRegistrations.php'], $violations);
    }

    /** @return list<string> the files, relative to the root, whose strings name a registration table */
    private static function violations(string $root): array
    {
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($root) + 1);
            if (in_array($relative, self::ALLOWED, true)) {
                continue;
            }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads a source file
            foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                if (
                    is_array($token)
                    && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
                    && preg_match(self::TABLES, $token[1]) === 1
                ) {
                    $found[] = $relative;
                    break;
                }
            }
        }
        sort($found);

        return $found;
    }
}
