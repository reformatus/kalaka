<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation;

use Kalaka\Foundation\Api\Actor\Actor;
use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Actor\SecretLinkActor;
use Kalaka\Foundation\Api\Actor\SystemActor;
use Kalaka\Foundation\Api\Audit\Correlation;
use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\Error\SiteClosed;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\LoadFailure;
use Kalaka\Foundation\Api\LoadState;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * A failed load closes the site: `requireOpen()` refuses with `SiteClosed` (a 503 with
 * `SystemFailure`'s text and no `Hibaazonosító` line), the runner refuses every use case before it
 * writes anything, and the boundary does not log it (modules-and-extensions.md, "Failure isolation").
 */
final class SiteClosedTest extends AuditTestCase
{
    private const TEXT = 'Váratlan hiba történt. Próbáld újra később.';

    /** @var list<LoadFailure> */
    private array $loadFailures = [];

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function set_up(): void
    {
        parent::set_up();
        $this->loadFailures = LoadState::current()->failures;
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function tear_down(): void
    {
        LoadState::record($this->loadFailures);
        parent::tear_down();
    }

    private static function close(): void
    {
        LoadState::record([new LoadFailure('x', 'y')]);
    }

    public function testSiteClosedIsA503SystemFailureWithItsTextAndNoIdLine(): void
    {
        $error = new SiteClosed();

        self::assertInstanceOf(SystemFailure::class, $error);
        self::assertSame(self::TEXT, $error->displayMessage());
        $wpError = $error->toWpError();
        self::assertSame('kalaka_site_closed', $wpError->get_error_code());
        self::assertSame(self::TEXT, $wpError->get_error_message());
        self::assertSame(['status' => 503, 'correlation' => Correlation::id()], $wpError->get_error_data());
    }

    public function testRequireOpenPassesWhileOpenAndRefusesWhileClosed(): void
    {
        LoadState::current()->requireOpen();

        self::close();
        $this->expectException(SiteClosed::class);
        LoadState::current()->requireOpen();
    }

    /** @return iterable<string, array{Actor}> */
    public static function actors(): iterable
    {
        yield 'the system' => [new SystemActor()];
        yield 'the CLI' => [new CliActor(null)];
    }

    /** @dataProvider actors */
    public function testWhileClosedTheRunnerRefusesEveryUseCaseAndWritesNothingNotEvenAFailedEntry(Actor $actor): void
    {
        $ran = false;
        self::close();

        $error = null;
        $statements = $this->statementsDuring(function () use ($actor, &$ran, &$error): void {
            try {
                $this->runner->run(new AdminProbe(static function () use (&$ran): string {
                    $ran = true;

                    return 'ran';
                }), [], $actor);
            } catch (SiteClosed $e) {
                $error = $e;
            }
        });

        self::assertInstanceOf(SiteClosed::class, $error);
        self::assertFalse($ran);
        self::assertSame([], $this->entries());
        self::assertSame([], $statements);
    }

    public function testThePortalSideIsRefusedToo(): void
    {
        self::close();

        $key = new RegistrationKey(1, '00A0');
        $this->expectException(SiteClosed::class);
        $this->runner->run(new PortalProbe([$key], static fn (): string => 'ran'), [], new SecretLinkActor($key));
    }

    public function testOnceOpenAgainTheRunnerRuns(): void
    {
        self::close();
        LoadState::record([]);

        self::assertSame('ran', $this->runner->run(
            new AdminProbe(static fn (array $input, Context $context): string => 'ran'),
            [],
            new SystemActor()
        ));
    }

    public function testTheBoundaryShowsItAsA503AndDoesNotLogItInTheForegroundOrBackground(): void
    {
        $shown = null;
        $log = $this->capturedErrorLog(static function () use (&$shown): void {
            $shown = Boundary::shown('test', new SiteClosed());
        });

        self::assertInstanceOf(SiteClosed::class, $shown);
        self::assertSame('', $log);
        self::assertSame('', $this->capturedErrorLog(static function (): void {
            Boundary::background('test hook', static function (): never {
                throw new SiteClosed();
            });
        }));
        self::assertSame('<p>' . self::TEXT . '</p>', Boundary::render('test', static function (): never {
            throw new SiteClosed();
        }));
    }

    /** @return list<string> the statements sent while the callback ran */
    private function statementsDuring(\Closure $callback): array
    {
        $statements = [];
        $capture = static function (string $query) use (&$statements): string {
            $statements[] = $query;

            return $query;
        };
        add_filter('query', $capture);
        try {
            $callback();
        } finally {
            remove_filter('query', $capture);
        }

        return $statements;
    }
}
