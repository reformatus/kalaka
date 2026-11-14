<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation\Seed;

use Kalaka\Events\Cli\EventSeeder;
use Kalaka\Foundation\Api\LoadFailure;
use Kalaka\Foundation\Api\LoadState;
use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\Foundation\Api\Module\Module;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Foundation\Seed\Seed;
use Kalaka\Foundation\Api\Entry\CommandFailure;
use Kalaka\Tests\Integration\Events\EventsTestCase;
use Kalaka\Tests\Integration\Foundation\TestClock;

/** The base of the seed tests: the runner in savepoint mode, a clock at 2026-11-10 12:00 in Budapest, the core seeders. */
abstract class SeedTestCase extends EventsTestCase
{
    protected TestClock $seedClock;

    protected Contributions $contributions;

    /** @var list<LoadFailure> */
    private array $loadFailures = [];

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        $this->loadFailures = LoadState::current()->failures;
        $this->seedClock = new TestClock($this->local('2026-11-10 12:00:00'));
        $this->useClock($this->seedClock);
        $this->contributions = new Contributions();
        $this->contributions->seeder(new EventSeeder());
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function tear_down(): void
    {
        LoadState::record($this->loadFailures);

        parent::tear_down();
    }

    protected function seed(): Seed
    {
        return new Seed(
            self::modulesForTests(),
            $this->contributions,
            fn (): Runner => $this->runner,
            $this->seedClock,
        );
    }

    /** @return list<string> the lines the seed reported */
    protected function seeded(bool $drop = false, string $environmentType = 'local'): array
    {
        $lines = [];
        $this->seed()->run($drop, $environmentType, static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        return $lines;
    }

    /** @return list<Module> the modules as kalaka.php lists them */
    public static function modulesForTests(): array
    {
        return [
            new \Kalaka\Foundation\Module(),
            new \Kalaka\Events\Module(),
            new \Kalaka\ParticipantFields\Module(),
            new \Kalaka\Forms\Module(),
            new \Kalaka\Assignments\Module(),
            new \Kalaka\Registrations\Module(),
            new \Kalaka\Pricing\Module(),
            new \Kalaka\Ledger\Module(),
            new \Kalaka\Payments\Module(),
            new \Kalaka\Mail\Module(),
            new \Kalaka\Portal\Module(),
            new \Kalaka\Admin\Module(),
        ];
    }

    protected function closeTheSite(): void
    {
        LoadState::record([new LoadFailure('x', 'y')]);
    }

    protected function refusalOf(\Closure $work): string
    {
        try {
            $work();
        } catch (CommandFailure $e) {
            return $e->getMessage();
        }
        self::fail('The seed should have refused.');
    }

    /** @return list<string> the logins of the six seeded users that exist */
    protected function seededLogins(): array
    {
        return array_values(array_filter(
            ['administrator', 'editor', 'author', 'contributor', 'subscriber', 'kalaka_admin'],
            static fn (string $login): bool => get_user_by('login', $login) !== false
        ));
    }
}
