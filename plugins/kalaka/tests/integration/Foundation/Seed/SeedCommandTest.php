<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation\Seed;

use Kalaka\Foundation\Cli\SeedCommand;
use Kalaka\Foundation\Seed\Seed;

/**
 * The command's wiring (D1, D10): the --drop flag reaches the seed, a stop becomes an error with
 * exit code 1, success prints the closing line. The seed under test has no modules, so a drop
 * issues no DDL; a closed site tells the two paths apart.
 */
final class SeedCommandTest extends SeedTestCase
{
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function set_up(): void
    {
        // A recording stand-in for WP-CLI, defined only when the real class is absent.
        require_once __DIR__ . '/WpCliDouble.php';
        parent::set_up();

        if (!defined('WP_CLI::IS_TEST_DOUBLE')) {
            self::markTestSkipped('The real WP-CLI is loaded; the recording double is not in use.');
        }
        self::assertContains(wp_get_environment_type(), ['local', 'development'], 'The suite runs on a local site.');
        \WP_CLI::reset();
    }

    public function testSuccessPrintsTheProgressLinesAndTheClosingLine(): void
    {
        (new SeedCommand(fn (): Seed => $this->seed()))([], []);

        $calls = \WP_CLI::$calls;
        self::assertSame('success', $calls[count($calls) - 1][0]);
        self::assertSame('Seeded.', $calls[count($calls) - 1][1]);
        $lines = array_column(array_filter($calls, static fn (array $call): bool => $call[0] === 'line'), 1);
        self::assertCount(
            5,
            array_filter($lines, static fn (string $line): bool => preg_match('/^(Created|Kept) user /', $line) === 1)
        );
        self::assertCount(1, array_filter($calls, static fn (array $call): bool => $call[0] === 'success'));
        self::assertCount(0, array_filter($calls, static fn (array $call): bool => $call[0] === 'error'));
    }

    public function testAStopIsAnErrorWithExitCodeOneAndNoSuccessLine(): void
    {
        $this->closeTheSite();

        try {
            (new SeedCommand(fn (): Seed => $this->seed()))([], []);
            self::fail('The command should have ended in an error.');
        } catch (\RuntimeException $e) {
            self::assertSame('WP_CLI::error', $e->getMessage());
        }

        self::assertSame(
            [['error', ['Refused: Kaláka did not load cleanly; run "wp kalaka seed --drop".', 1]]],
            \WP_CLI::$calls
        );
        self::assertSame([], $this->seededLogins());
    }

    public function testTheDropFlagIsPassedToTheSeed(): void
    {
        $this->closeTheSite();

        (new SeedCommand(fn (): Seed => $this->seedWithoutModules()))([], ['drop' => true]);

        self::assertSame([['success', 'Schema recreated; run "wp kalaka seed" again.']], \WP_CLI::$calls);
    }

    public function testWithoutTheDropFlagTheSeedIsNotToldToDrop(): void
    {
        $this->closeTheSite();

        try {
            (new SeedCommand(fn (): Seed => $this->seedWithoutModules()))([], []);
            self::fail('The command should have ended in an error.');
        } catch (\RuntimeException) {
            self::assertSame(
                [['error', ['Refused: Kaláka did not load cleanly; run "wp kalaka seed --drop".', 1]]],
                \WP_CLI::$calls
            );
        }
    }

    private function seedWithoutModules(): Seed
    {
        return new Seed([], $this->contributions, $this->runner, $this->seedClock);
    }
}
