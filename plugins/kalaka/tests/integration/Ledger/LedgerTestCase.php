<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Ledger;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Ledger\Api\Ledger;
use Kalaka\Ledger\Api\LedgerLine;
use Kalaka\Ledger\Api\LineKind;
use Kalaka\Ledger\Api\NewLine;
use Kalaka\Ledger\Api\Payer;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Tests\Integration\Foundation\TestClock;
use Kalaka\Tests\Integration\Registrations\RegistrationsTestCase;

require_once __DIR__ . '/../Registrations/RegistrationsTestCase.php';

/** The base of the Ledger tests: the Api, a registration to write to and helpers to build and count lines. */
abstract class LedgerTestCase extends RegistrationsTestCase
{
    protected const EVENT = 5;

    protected Ledger $ledger;

    public function set_up(): void
    {
        parent::set_up();

        $this->ledger = new Ledger();
        $this->runner = new Runner(new TestClock($this->utc('2026-11-30 08:00:00.123456')), true);
    }

    /** A stored registration of the test event. */
    protected function registration(string $code = '12A3', int $event = self::EVENT): RegistrationKey
    {
        $this->insertRegistration($this->newRegistration($event, $code));

        return new RegistrationKey($event, $code);
    }

    protected function money(string $amount): Money
    {
        return Money::of($amount, Currency::HUF);
    }

    protected function line(
        RegistrationKey $registration,
        LineKind $kind,
        string $amount,
        string $key,
        ?string $valueDate = null,
        ?string $paidAt = null,
        string $payer = Payer::PARTICIPANT,
        string $source = 'fixed_price',
    ): NewLine {
        return new NewLine(
            $registration,
            $kind,
            $payer,
            $this->money($amount),
            new Subject('fee_component', null, $source),
            $key,
            $valueDate,
            $paidAt === null ? null : $this->utc($paidAt),
        );
    }

    /** A line with a time that fits its kind: the paid time for online kinds, a value date for manual ones. */
    protected function fitted(RegistrationKey $registration, LineKind $kind, string $amount, string $key): NewLine
    {
        return $this->line(
            $registration,
            $kind,
            $amount,
            $key,
            $kind->carriesValueDate() ? '2026-12-03' : null,
            $kind->carriesPaidTime() ? '2026-12-01 10:00:00' : null
        );
    }

    /**
     * Records the lines in one use case.
     *
     * @return list<LedgerLine>
     */
    protected function record(NewLine ...$lines): array
    {
        $stored = $this->inRun(function (array $input, Context $context) use ($lines): array {
            return array_map(fn (NewLine $line): LedgerLine => $this->ledger->record($line, $context), $lines);
        });
        self::assertIsArray($stored);

        /** @var list<LedgerLine> $stored */
        return $stored;
    }

    protected function lineCount(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}kalaka_ledger_lines");
    }
}
