<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Ledger;

use Kalaka\Foundation\Api\Repository\AppendOnlyRepository;
use Kalaka\Ledger\LedgerLineRepository;
use PHPUnit\Framework\TestCase;

/** AC8: ledger lines are append-only and only the module's own classes name the table. */
final class LedgerLineRepositoryTest extends TestCase
{
    public function testItExtendsTheAppendOnlyBaseAndDeclaresNoUpdateOrDelete(): void
    {
        $class = new \ReflectionClass(LedgerLineRepository::class);

        self::assertTrue($class->isSubclassOf(AppendOnlyRepository::class));
        foreach ($class->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() === LedgerLineRepository::class) {
                self::assertDoesNotMatchRegularExpression('/upsert|update|delete/i', $method->getName());
            }
        }
        self::assertFalse($class->hasMethod('upsert'));
        self::assertFalse($class->hasMethod('update'));
        self::assertFalse($class->hasMethod('delete'));
    }

    public function testOnlyTheModuleAndItsRepositoryAndQueriesNameTheTable(): void
    {
        $root = dirname(__DIR__, 3) . '/src';
        $named = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (
                str_ends_with($file->getPathname(), '.php')
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads a plugin source file
                && str_contains((string) file_get_contents($file->getPathname()), 'kalaka_ledger_lines')
            ) {
                $named[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }
        sort($named);

        self::assertSame(
            [
                'Ledger/BalanceQuery.php',
                'Ledger/LedgerLineRepository.php',
                'Ledger/Module.php',
                'Ledger/PaymentFactQuery.php',
            ],
            $named
        );
    }
}
