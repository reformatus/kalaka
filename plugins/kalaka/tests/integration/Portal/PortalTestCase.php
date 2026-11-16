<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Portal;

use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Ledger\Api\LineKind;
use Kalaka\Portal\ActiveRegistrationState;
use Kalaka\Portal\Api\StatusRead;
use Kalaka\Portal\StatusMarkup;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\RegistrationStatus;
use Kalaka\Tests\Integration\Ledger\LedgerTestCase;

/**
 * The base of the Portal tests: a published event with bank details, a transfer-reference prefix and a
 * payment-due N of 7 days; the clock fixed at 2026-11-30 09:00 in Budapest; helpers for registrations,
 * ledger lines, decisions and the active registration.
 */
abstract class PortalTestCase extends LedgerTestCase
{
    protected const NOW = '2026-11-30 08:00:00.123456';

    private Runner $production;

    private int $lineNumber = 0;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        $this->production = Runner::current();
        Runner::publish($this->runner);
        ActiveRegistrationState::set(null, false);
        $this->event();
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function tear_down(): void
    {
        ActiveRegistrationState::set(null, false);
        Runner::publish($this->production);

        parent::tear_down();
    }

    /** The test event's row, written behind the repository; `$columns` override the defaults. */
    protected function event(array $columns = []): void
    {
        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'kalaka_events', ['id' => self::EVENT]);
        $wpdb->insert($wpdb->prefix . 'kalaka_events', $columns + [
            'id' => self::EVENT,
            'version' => 1,
            'name' => 'Nyári tábor',
            'state' => 'published',
            'currency' => 'HUF',
            'capacity' => 100,
            'points' => '[]',
            'transfer_beneficiary' => 'Példa Egyesület',
            'transfer_account' => '11111111-22222222-33333333',
            'transfer_reference_prefix' => 'TAB27',
            'payment_due_days' => 7,
        ]);
        \Kalaka\Foundation\ObjectCache::clear();
    }

    /** A stored registration of the test event, which became `registered` (when it is) at `$at` (UTC). */
    protected function stored(
        string $code,
        RegistrationStatus $status = RegistrationStatus::Registered,
        string $at = '2026-11-30 08:00:00',
    ): RegistrationKey {
        $this->insertRegistration($this->newRegistration(self::EVENT, $code, $status, $at));

        return new RegistrationKey(self::EVENT, $code);
    }

    protected function charge(RegistrationKey $key, string $amount, string $payer = 'participant'): void
    {
        $this->record($this->line($key, LineKind::Charge, $amount, 'c' . (++$this->lineNumber), payer: $payer));
    }

    /** A bank transfer recorded by hand, of the amount paid. */
    protected function pay(RegistrationKey $key, string $amount): void
    {
        $this->record(
            $this->line($key, LineKind::ManualPayment, '-' . $amount, 'p' . (++$this->lineNumber), '2026-11-29')
        );
    }

    /** A decision record, written behind the evaluation (no rule writes these kinds yet). */
    protected function decide(RegistrationKey $key, string $kind, string $state): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'kalaka_decisions', [
            'event_id' => $key->eventId,
            'code' => $key->code,
            'kind' => $kind,
            'state' => $state,
            'effective_at' => '2026-11-29 10:00:00.000000',
            'recorded_at' => '2026-11-29 10:00:00.000000',
            'cause_type' => 'test',
            'cause_key' => '1',
            'actor_type' => 'system',
        ]);
    }

    protected function activate(?RegistrationKey $key, bool $badLink = false): void
    {
        ActiveRegistrationState::set($key, $badLink);
    }

    /** The status block's markup for the active registration. */
    protected function statusBlock(?StatusRead $read = null): string
    {
        return StatusMarkup::status(($read ?? new StatusRead())->forActive(), ActiveRegistrationState::badLink());
    }

    protected function transferBlock(): string
    {
        return StatusMarkup::transfer((new StatusRead())->forActive(), ActiveRegistrationState::badLink());
    }

    /** The markup's text content, tags dropped, no-break spaces as spaces. */
    protected static function text(string $html): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', wp_strip_all_tags(
            (string) preg_replace('/<\/(p|h2|h3|tr|th|td)>/', '$0 ', $html)
        ))));
    }
}
