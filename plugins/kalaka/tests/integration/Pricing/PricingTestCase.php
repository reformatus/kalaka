<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Pricing;

use Kalaka\Events\EventRecord;
use Kalaka\Events\EventRepository;
use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Foundation\Decision\DecisionRules;
use Kalaka\Foundation\Evaluation\Evaluation;
use Kalaka\Ledger\Api\Ledger;
use Kalaka\Ledger\Api\LineKind;
use Kalaka\Ledger\Api\NewLine;
use Kalaka\Ledger\Api\Payer;
use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Pricing\Api\FeeComponent;
use Kalaka\Pricing\Api\Materializer;
use Kalaka\Pricing\Api\MoneySettings;
use Kalaka\Pricing\Api\ProposedMoneySettings;
use Kalaka\Pricing\CoreFeeComponents;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\RegistrationStatus;
use Kalaka\Tests\Integration\Foundation\TestClock;
use Kalaka\Tests\Integration\Registrations\RegistrationsTestCase;

/**
 * The base of the Pricing tests: an event in HUF, a runner whose evaluation has the money phase (built from
 * the runner's TestClock, now = 2026-11-30 08:00 UTC), and helpers to read the charge lines written.
 */
abstract class PricingTestCase extends RegistrationsTestCase
{
    protected int $event;

    protected Ledger $ledger;

    protected MoneySettings $settings;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        update_option('timezone_string', 'Europe/Budapest');
        $this->ledger = new Ledger();
        $this->useComponents();
        $stored = $this->inRun(
            fn (array $input, Context $context): EventRecord => (new EventRepository())->upsert(
                new EventRecord('Teszt tábor', currency: Currency::HUF),
                $context
            )
        );
        self::assertInstanceOf(EventRecord::class, $stored);
        $this->event = $stored->id;
    }

    /** The runner and the settings with core's components and the given test components. */
    protected function useComponents(FeeComponent ...$more): void
    {
        $components = new CoreFeeComponents($more);
        $this->runner = new Runner(
            new TestClock($this->utc('2026-11-30 08:00:00')),
            true,
            new Evaluation(new DecisionRules(), money: new Materializer($components))
        );
        $this->settings = new MoneySettings($this->runner, $components);
    }

    protected function huf(string $amount): Money
    {
        return Money::of($amount, Currency::HUF);
    }

    /** Sets the event's fixed price through a money settings apply. */
    protected function setPrice(string $amount): void
    {
        $proposed = new ProposedMoneySettings($this->huf($amount));
        $report = $this->settings->apply($this->event, $proposed, new CliActor(null));
        self::assertSame([], $report->failed());
    }

    protected function register(
        string $code,
        RegistrationStatus $status = RegistrationStatus::Registered,
        string $createdAt = '2026-11-30 08:00:00',
    ): RegistrationKey {
        $this->insertRegistration($this->newRegistration($this->event, $code, $status, $createdAt));

        return new RegistrationKey($this->event, $code);
    }

    /** Records a manual payment for the payer, in a run that holds the registration. */
    protected function pay(RegistrationKey $key, string $amount, string $payer = Payer::PARTICIPANT): void
    {
        $this->inRun(function (array $input, Context $context) use ($key, $amount, $payer): void {
            $this->lockRegistrations($context, $key);
            $this->ledger->record(
                new NewLine(
                    $key,
                    LineKind::ManualPayment,
                    $payer,
                    $this->huf('-' . $amount),
                    new Subject('test_payment', null, 'p'),
                    'pay:' . $payer . ':' . $amount,
                    '2026-12-01',
                ),
                $context
            );
        });
    }

    /**
     * The charge lines of the registration, oldest first, as "source payer amount key".
     *
     * @return list<string>
     */
    protected function charges(RegistrationKey $key): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT source_key, payer, amount, idempotency_key FROM %i'
            . " WHERE event_id = %d AND code = %s AND kind = 'charge' ORDER BY id",
            $wpdb->prefix . 'kalaka_ledger_lines',
            $key->eventId,
            $key->code
        ), ARRAY_A);
        self::assertIsArray($rows);

        return array_map(
            static fn (array $row): string => $row['source_key'] . ' ' . $row['payer'] . ' '
                . self::plain((string) $row['amount']) . ' ' . $row['idempotency_key'],
            $rows
        );
    }

    private static function plain(string $amount): string
    {
        return str_contains($amount, '.') ? rtrim(rtrim($amount, '0'), '.') : $amount;
    }

    protected function balance(RegistrationKey $key, string $payer = Payer::PARTICIPANT): string
    {
        $amount = $this->ledger->balancesOf($key)->of($payer, Currency::HUF)->amount();

        return self::plain($amount);
    }
}
