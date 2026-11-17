<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Forms;

use Kalaka\Events\PublishEvent;
use Kalaka\Forms\Api\FormCaching;
use Kalaka\Forms\Api\RateLimited;
use Kalaka\Forms\Engine\RateLimiter;
use Kalaka\Forms\Engine\Rest\FormRoutes;
use Kalaka\Forms\Engine\SubmitGuard;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Registrations\Api\SubmitToken;
use Kalaka\Tests\Integration\Foundation\TestClock;

/**
 * S14: the submit route's rate limit, through `rest_do_request()`: every attempt counts, the one over the
 * limit is 429 `kalaka_rate_limited` with `Retry-After` and `no-store` and reaches no use case, the next
 * window counts afresh, a logged-in person counts by account and a guest by address, a forwarded header
 * from an unnamed proxy is ignored, no address is stored, and a broken counter lets the submit through.
 */
final class SubmitRateLimitTest extends FormsTestCase
{
    private const ANSWERS = ['name' => 'Teszt Elek', 'email' => 'teszt@example.org'];

    private Runner $previous;

    /** @var array<string, mixed> */
    private array $server;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function set_up(): void
    {
        parent::set_up();
        $this->server = $_SERVER;
        $_SERVER['REMOTE_ADDR'] = '192.0.2.10';
        $this->previous = Runner::current();
        Runner::publish($this->runner);
        $GLOBALS['wp_rest_server'] = null;
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function tear_down(): void
    {
        $_SERVER = $this->server;
        Runner::publish($this->previous);
        $GLOBALS['wp_rest_server'] = null;
        wp_set_current_user(0);
        parent::tear_down();
    }

    private function openForm(): int
    {
        $event = $this->create();
        $this->update($event, ['capacity' => 100]);
        $this->transition(new PublishEvent(), $event);

        return $this->insertForm($this->slotFields(), ['kalaka_event_id' => $event]);
    }

    /** @param array<string, mixed> $answers */
    private function submit(int $form, array $answers = self::ANSWERS): \WP_REST_Response
    {
        $request = new \WP_REST_Request('POST', '/' . FormRoutes::NAMESPACE . '/forms/' . $form . '/submit');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body((string) wp_json_encode([
            'version' => $this->readForm($form)->version,
            'answers' => $answers,
            'token' => SubmitToken::generate(),
        ]));

        return rest_do_request($request);
    }

    /** Uses up the window's attempts with refused submits (an undeclared key): they count too. */
    private function useUp(int $form): void
    {
        for ($i = 0; $i < SubmitGuard::LIMIT; $i++) {
            $response = $this->submit($form, ['nope' => 'x']);
            self::assertNotSame(429, $response->get_status());
        }
    }

    private function code(\WP_REST_Response $response): ?string
    {
        $data = $response->get_data();

        return is_array($data) && is_string($data['code'] ?? null) ? $data['code'] : null;
    }

    private function registrations(int $form): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test inspection; table prefix only
            "SELECT COUNT(*) FROM {$wpdb->prefix}kalaka_registrations WHERE event_id = %d",
            $this->readForm($form)->eventId
        ));
    }

    private function atUtc(string $time): void
    {
        $this->useClock(new TestClock(new \DateTimeImmutable($time, new \DateTimeZone('UTC'))));
        Runner::publish($this->runner);
    }

    public function testTheAttemptOverTheLimitIsRefusedWithRetryAfterAndReachesNoUseCase(): void
    {
        $this->atUtc('2026-11-30 08:03:20');
        $form = $this->openForm();
        $this->useUp($form);
        $entries = count($this->entriesOf('kalaka-portal/submit-registration'));

        $refused = $this->submit($form);

        self::assertSame(429, $refused->get_status());
        self::assertSame(RateLimited::CODE, $this->code($refused));
        $headers = $refused->get_headers();
        self::assertSame('400', $headers['Retry-After'] ?? null);
        self::assertSame(FormCaching::CACHE_CONTROL, $headers['Cache-Control'] ?? null);
        self::assertSame(0, $this->registrations($form));
        self::assertCount($entries, $this->entriesOf('kalaka-portal/submit-registration'));
    }

    public function testTheNextWindowCountsAfresh(): void
    {
        $form = $this->openForm();
        $this->useUp($form);
        self::assertSame(429, $this->submit($form)->get_status());

        $this->atUtc('2026-11-30 08:10:00');

        $accepted = $this->submit($form);
        self::assertSame(200, $accepted->get_status());
        self::assertSame(1, $this->registrations($form));
    }

    public function testAGuestCountsByAddressAndAForwardedHeaderIsIgnored(): void
    {
        $form = $this->openForm();
        $this->useUp($form);

        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.7';
        self::assertSame(429, $this->submit($form)->get_status());

        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        $_SERVER['REMOTE_ADDR'] = '192.0.2.11';
        self::assertSame(200, $this->submit($form)->get_status());
    }

    public function testALoggedInPersonCountsByAccountNotByTheSharedAddress(): void
    {
        $form = $this->openForm();
        $this->useUp($form);

        wp_set_current_user(self::factory()->user->create());
        self::assertSame(200, $this->submit($form, ['name' => 'Első', 'email' => 'elso@example.org'])->get_status());

        $other = self::factory()->user->create();
        wp_set_current_user($other);
        for ($i = 0; $i < SubmitGuard::LIMIT; $i++) {
            $this->submit($form, ['nope' => 'x']);
        }
        self::assertSame(429, $this->submit($form)->get_status());
    }

    public function testNoAddressIsStored(): void
    {
        global $wpdb;
        $form = $this->openForm();
        $this->submit($form);

        $rows = $wpdb->get_results(
            "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE '%kalaka\\_rl\\_%'",
            ARRAY_A
        );
        self::assertNotEmpty($rows);
        foreach ($rows as $row) {
            self::assertStringNotContainsString('192.0.2.10', $row['option_name'] . $row['option_value']);
        }
    }

    public function testABrokenCounterLetsTheSubmitThrough(): void
    {
        $form = $this->openForm();
        $broken = static function (mixed $value, string $option): mixed {
            if (str_contains($option, 'kalaka_rl_')) {
                throw new \RuntimeException('counter down');
            }

            return $value;
        };
        add_filter('pre_option', $broken, 10, 2);
        $file = (string) wp_tempnam('rate-limit');
        $log = ini_set('error_log', $file);
        try {
            for ($i = 0; $i <= SubmitGuard::LIMIT; $i++) {
                $this->submit($form, ['nope' => 'x']);
            }
            $response = $this->submit($form);
        } finally {
            remove_filter('pre_option', $broken, 10);
            ini_set('error_log', (string) $log);
        }

        self::assertSame(200, $response->get_status());
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local scratch log
        self::assertStringContainsString('rate limit submit', (string) file_get_contents($file));
    }

    public function testAClientWhoseCountIsBusyIsRefusedForASecond(): void
    {
        $limiter = new RateLimiter('test', 5, 600);
        $now = new \DateTimeImmutable('2026-11-30 08:00:00', new \DateTimeZone('UTC'));
        $key = hash_hmac('sha256', 'test|' . intdiv($now->getTimestamp(), 600) . '|someone', wp_salt('nonce'));
        // Another connection holds the client's lock, as a parallel attempt would.
        $other = new \wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        $lock = (new \Kalaka\Foundation\Api\Database\NamedLock('rl_' . substr($key, 0, 32)))->scoped();
        $other->query($other->prepare('SELECT GET_LOCK(%s, 0)', $lock));
        try {
            $limiter->hit('someone', $now);
            self::fail('A busy count refuses the attempt.');
        } catch (RateLimited $refused) {
            self::assertSame(1, $refused->retryAfter);
        } finally {
            $other->query($other->prepare('SELECT RELEASE_LOCK(%s)', $lock));
            $other->close();
        }

        $limiter->hit('someone', $now);
        self::assertSame('1', (string) get_transient(RateLimiter::transient($key)));
    }
}
