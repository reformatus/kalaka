<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation;

use Kalaka\Foundation\Api\Actor\SystemActor;
use Kalaka\Foundation\Api\Audit\Correlation;
use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\Entry\CommandFailure;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\SystemFailure;

/** The error boundary of every entry point (#147). */
final class BoundaryTest extends RunnerTestCase
{
    private const NAMESPACE = 'kalaka-boundary-test/v1';

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function set_up(): void
    {
        // A recording stand-in for WP-CLI, defined only when the real class is absent.
        require_once __DIR__ . '/Seed/WpCliDouble.php';
        parent::set_up();
        if (defined('WP_CLI::IS_TEST_DOUBLE')) {
            \WP_CLI::reset();
        }
    }

    /** The short ID a person sees on a 500. */
    private static function shortId(): string
    {
        return substr(Correlation::id(), 0, 8);
    }

    /**
     * Registers a route whose callback throws, and dispatches a GET to it.
     *
     * @return array{\WP_REST_Response, string} the response and what was logged meanwhile
     */
    private function routeThrowing(\Throwable $error, string $where = 'callback'): array
    {
        global $wp_rest_server;
        $wp_rest_server = new \WP_REST_Server();
        $throw = static function () use ($error): never {
            throw $error;
        };
        $endpoint = [
            'methods' => 'GET',
            'callback' => $where === 'callback' ? $throw : '__return_true',
            'permission_callback' => $where === 'permission' ? $throw : '__return_true',
        ];
        add_action('rest_api_init', static function () use ($endpoint): void {
            Boundary::route(self::NAMESPACE, '/thing', [$endpoint]);
        });
        do_action('rest_api_init', $wp_rest_server);

        $response = null;
        $log = $this->capturedErrorLog(static function () use (&$response): void {
            $response = rest_do_request(new \WP_REST_Request('GET', '/' . self::NAMESPACE . '/thing'));
        });
        self::assertInstanceOf(\WP_REST_Response::class, $response);

        return [$response, $log];
    }

    public function testAnUnexpectedErrorInARouteIsOurHungarian500WithTheCorrelationAndIsLogged(): void
    {
        [$response, $log] = $this->routeThrowing(new \ValueError('"XYZ" is not a valid backing value'));

        self::assertSame(500, $response->get_status());
        $data = $response->get_data();
        self::assertSame('kalaka_system_failure', $data['code']);
        self::assertSame('Váratlan hiba történt. Próbáld újra később.', $data['message']);
        self::assertSame(Correlation::id(), $data['data']['correlation']);
        self::assertStringContainsString(
            'Kaláka: REST /' . self::NAMESPACE . '/thing failed, correlation ' . Correlation::id()
            . ': ValueError (0): "XYZ" is not a valid backing value',
            $log
        );
    }

    public function testThePermissionCallbackIsBehindTheBoundaryToo(): void
    {
        [$response, $log] = $this->routeThrowing(new \RuntimeException('broken check'), 'permission');

        self::assertSame(500, $response->get_status());
        self::assertSame('kalaka_system_failure', $response->get_data()['code']);
        self::assertStringContainsString('RuntimeException (0): broken check', $log);
    }

    public function testAKalakaRefusalIsShownAsItIsAndNotLogged(): void
    {
        [$response, $log] = $this->routeThrowing(new NotFound());

        self::assertSame(404, $response->get_status());
        self::assertSame('kalaka_not_found', $response->get_data()['code']);
        self::assertSame(Correlation::id(), $response->get_data()['data']['correlation']);
        self::assertSame('', $log);
    }

    public function testASystemFailureNoRunRecordedIsLogged(): void
    {
        [$response, $log] = $this->routeThrowing(new SystemFailure(null, new \RuntimeException('Database error 2006')));

        self::assertSame(500, $response->get_status());
        self::assertStringContainsString('RuntimeException (0): Database error 2006', $log);
    }

    public function testAFailureARunRecordedIsNotLoggedAgain(): void
    {
        $failed = null;
        try {
            $this->runner->run(new AdminProbe(static function (): never {
                throw new \RuntimeException('inside the use case');
            }), [], new SystemActor());
        } catch (SystemFailure $e) {
            $failed = $e;
        }
        self::assertInstanceOf(SystemFailure::class, $failed);

        [$response, $log] = $this->routeThrowing($failed);

        self::assertSame(500, $response->get_status());
        self::assertSame('', $log);
    }

    public function testEveryErrorCarriesTheCorrelationAndOnlyA500ShowsIt(): void
    {
        self::assertSame(Correlation::id(), (new InvalidInput())->toWpError()->get_error_data()['correlation']);
        self::assertSame('Hibás vagy hiányzó adat.', (new InvalidInput())->displayMessage());
        self::assertSame(
            "Váratlan hiba történt. Próbáld újra később.\nHibaazonosító: " . self::shortId(),
            (new SystemFailure())->displayMessage()
        );
        self::assertSame(
            'Váratlan hiba történt. Próbáld újra később.',
            (new SystemFailure())->toWpError()->get_error_message()
        );
    }

    public function testACommandsOwnRefusalPrintsItsMessageAndIsNotLogged(): void
    {
        $this->requireTheCliDouble();

        $log = $this->capturedErrorLog(static function (): void {
            try {
                Boundary::cli(static function (): never {
                    throw new CommandFailure('File not found: x.csv');
                });
            } catch (\RuntimeException) {
                // The double's error() does not return.
            }
        });

        self::assertSame([['error', ['File not found: x.csv', 1]]], \WP_CLI::$calls);
        self::assertSame('', $log);
    }

    public function testAnUnexpectedErrorInACommandPrintsTheSystemFailureWithItsIdAndIsLogged(): void
    {
        $this->requireTheCliDouble();

        $log = $this->capturedErrorLog(static function (): void {
            try {
                Boundary::cli(static function (): never {
                    throw new \LogicException('not ready');
                });
            } catch (\RuntimeException) {
                // The double's error() does not return.
            }
        });

        self::assertSame(
            [['error', ["Váratlan hiba történt. Próbáld újra később.\nHibaazonosító: " . self::shortId(), 1]]],
            \WP_CLI::$calls
        );
        self::assertStringContainsString('Kaláka: WP-CLI command failed, correlation ', $log);
        self::assertStringContainsString('LogicException (0): not ready', $log);
    }

    public function testACommandThatSucceedsPrintsNoError(): void
    {
        $this->requireTheCliDouble();

        Boundary::cli(static function (): void {
            \WP_CLI::success('done');
        });

        self::assertSame([['success', 'done']], \WP_CLI::$calls);
    }

    public function testBackgroundWorkLetsNothingEscapeAndLogsIt(): void
    {
        $log = $this->capturedErrorLog(static function (): void {
            Boundary::background('test hook', static function (): never {
                throw new \RuntimeException('nobody watches');
            });
        });

        self::assertStringContainsString(
            'Kaláka: test hook failed, correlation ' . Correlation::id() . ': RuntimeException (0): nobody watches',
            $log
        );
    }

    public function testARenderShowsTheErrorInPlaceAndLogsIt(): void
    {
        $html = '';
        $log = $this->capturedErrorLog(static function () use (&$html): void {
            $html = Boundary::render('test block', static function (): never {
                throw new \RuntimeException('cannot render');
            });
        });

        self::assertSame(
            '<p>Váratlan hiba történt. Próbáld újra később.</p><p><small>Hibaazonosító: ' . self::shortId()
            . '</small></p>',
            $html
        );
        self::assertStringContainsString('RuntimeException (0): cannot render', $log);
        self::assertSame('<b>ok</b>', Boundary::render('test block', static fn (): string => '<b>ok</b>'));
    }

    public function testASystemFailuresContextReachesTheLogButNeverThePerson(): void
    {
        $failure = new SystemFailure('duplicate_key name');

        [$response, $log] = $this->routeThrowing($failure);

        self::assertSame(500, $response->get_status());
        self::assertSame('Váratlan hiba történt. Próbáld újra később.', $response->get_data()['message']);
        self::assertSame(Correlation::id(), $response->get_data()['data']['correlation']);
        self::assertStringContainsString(
            'SystemFailure (0): Váratlan hiba történt. Próbáld újra később. duplicate_key name',
            $log
        );

        $html = '';
        $this->capturedErrorLog(static function () use (&$html, $failure): void {
            $html = Boundary::render('test block', static function () use ($failure): never {
                throw $failure;
            });
        });
        self::assertSame(
            '<p>Váratlan hiba történt. Próbáld újra később.</p><p><small>Hibaazonosító: ' . self::shortId()
            . '</small></p>',
            $html
        );
    }

    public function testARefusalsContextIsShownToThePerson(): void
    {
        [$response] = $this->routeThrowing(new InvalidInput('A név kötelező.'));

        self::assertSame(400, $response->get_status());
        self::assertSame('Hibás vagy hiányzó adat. A név kötelező.', $response->get_data()['message']);
        self::assertSame(
            '<p>Hibás vagy hiányzó adat. A név kötelező.</p>',
            Boundary::render('test block', static function (): never {
                throw new InvalidInput('A név kötelező.');
            })
        );
    }

    public function testACommandPrintsASystemFailuresContextToTheOperator(): void
    {
        $this->requireTheCliDouble();

        $this->capturedErrorLog(static function (): void {
            try {
                Boundary::cli(static function (): never {
                    throw new SystemFailure('duplicate_key name');
                });
            } catch (\RuntimeException) {
                // The double's error() does not return.
            }
        });

        self::assertSame(
            [['error', [
                "Váratlan hiba történt. Próbáld újra később. duplicate_key name\nHibaazonosító: " . self::shortId(),
                1,
            ]]],
            \WP_CLI::$calls
        );
    }

    private function requireTheCliDouble(): void
    {
        if (!defined('WP_CLI::IS_TEST_DOUBLE')) {
            self::markTestSkipped('The real WP-CLI is loaded; the recording double is not in use.');
        }
    }
}
