<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Portal;

use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\Foundation\ObjectCache;
use Kalaka\Ledger\Api\LineKind;
use Kalaka\Ledger\Api\PayerKind;
use Kalaka\Ledger\Api\PayerKindInput;
use Kalaka\Ledger\Api\PayerKindSource;
use Kalaka\Portal\Api\OtherPayerMoney;
use Kalaka\Portal\Api\StatusRead;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\RegistrationStatus;

/** The other payers' money tables of the status read (`otherPayerMoney`), built like the participant's. [UNAPPROVED N692] */
final class OtherPayerMoneyTest extends PortalTestCase
{
    /** Declares the kinds, in this order; the guard restores the contributions after the test. */
    private function declare(string ...$kinds): void
    {
        $with = clone Contributions::current();
        foreach ($kinds as $id => $label) {
            $with->payerKindSource(new class ($id, $label) implements PayerKindSource {
                public function __construct(private readonly string $id, private readonly string $label)
                {
                }

                public function kind(): PayerKind
                {
                    return new PayerKind($this->id, $this->label);
                }

                public function appliesTo(PayerKindInput $input): bool
                {
                    return true;
                }
            });
        }
        Contributions::record($with);
    }

    private function entry(RegistrationKey $key, string $amount, string $id, string $payer, LineKind $kind): void
    {
        $this->record($this->line($key, $kind, $amount, $id, '2026-12-02', payer: $payer));
    }

    /** @return list<OtherPayerMoney> */
    private function others(RegistrationKey $key): array
    {
        $view = (new StatusRead())->of($key);
        self::assertNotNull($view);

        return $view->otherPayerMoney;
    }

    private function sharing(string $code, RegistrationStatus $status = RegistrationStatus::Registered): RegistrationKey
    {
        $this->declare(congregation: 'Gyülekezet');
        $key = $this->stored($code, $status);
        $this->charge($key, '40000');
        $this->charge($key, '10000', 'congregation');
        $this->entry($key, '-4000', 'g1', 'congregation', LineKind::ManualPayment);
        $this->entry($key, '1500', 'g2', 'congregation', LineKind::ManualRefund);

        return $key;
    }

    public function testTheCongregationHasItsOwnTableShapedLikeTheParticipants(): void
    {
        $key = $this->sharing('12A3');

        $view = (new StatusRead())->of($key);
        self::assertNotNull($view);
        self::assertCount(1, $view->otherPayerMoney);
        $table = $view->otherPayerMoney[0];
        self::assertSame('congregation', $table->payer);
        self::assertSame('Gyülekezet', $table->label);
        self::assertSame(['Részvételi díj'], array_map(static fn ($row) => $row->label, $table->money->owed));
        self::assertSame('10000', rtrim(rtrim($table->money->owedTotal->amount(), '0'), '.'));
        // A payment lowers what is owed, a refund raises it; the movements show money in as positive.
        self::assertSame(
            ['payment', 'refund'],
            array_map(static fn ($row) => $row->type->value, $table->money->movements)
        );
        self::assertEqualsCanonicalizing(
            ['4000', '-1500'],
            array_map(static fn ($row) => rtrim(rtrim($row->amount->amount(), '0'), '.'), $table->money->movements)
        );
        self::assertSame('2500', rtrim(rtrim($table->money->paidTotal->amount(), '0'), '.'));
        self::assertSame('7500', rtrim(rtrim($table->money->balance->amount(), '0'), '.'));
        self::assertSame('7500', rtrim(rtrim($table->money->due?->amount() ?? '', '0'), '.'));
        self::assertNull($table->money->overpaid);
        // The participant's table is unchanged: its own lines only.
        self::assertSame('40000', rtrim(rtrim($view->money->owedTotal->amount(), '0'), '.'));
        self::assertSame([], $view->money->movements);
        self::assertSame(['congregation'], array_keys($view->payment?->otherPayers ?? []));
    }

    public function testARegistrationWithNoOtherPayerHasAnEmptyList(): void
    {
        $this->declare(congregation: 'Gyülekezet');
        $key = $this->stored('12A3');
        $this->charge($key, '40000');

        self::assertSame([], $this->others($key));
    }

    public function testTheOrderIsLedgersPayerOrderNotLineOrder(): void
    {
        $this->declare(congregation: 'Gyülekezet', sponsor: 'Támogató');
        $key = $this->stored('12A3');
        $this->charge($key, '40000');
        $this->charge($key, '3000', 'sponsor');
        $this->charge($key, '10000', 'congregation');

        self::assertSame(['congregation', 'sponsor'], array_map(static fn ($t) => $t->payer, $this->others($key)));
    }

    public function testAPayerWithoutAKindIsListedWithItsIdForLabel(): void
    {
        $key = $this->stored('12A3');
        $this->charge($key, '3000', 'sponsor');

        $others = $this->others($key);
        self::assertCount(1, $others);
        self::assertSame('sponsor', $others[0]->label);
    }

    public function testAWithdrawnRegistrationKeepsTheTablesAsItKeepsMoney(): void
    {
        $key = $this->sharing('12A3', RegistrationStatus::Withdrawn);

        $view = (new StatusRead())->of($key);
        self::assertNotNull($view);
        self::assertNull($view->payment);
        self::assertSame('40000', rtrim(rtrim($view->money->owedTotal->amount(), '0'), '.'));
        self::assertCount(1, $view->otherPayerMoney);
        self::assertCount(2, $view->otherPayerMoney[0]->money->movements);
    }

    public function testAWaitlistedRegistrationReadsAnEmptyList(): void
    {
        $key = $this->sharing('12A3', RegistrationStatus::Waitlisted);

        self::assertSame([], $this->others($key));
    }

    public function testTheCostIsOneStatementForTheLinesAndOneForEachOtherPayerWithLines(): void
    {
        wp_timezone();
        $none = $this->stored('00A2');
        $this->charge($none, '40000');
        $one = $this->sharing('12A3');
        $counts = [];
        foreach ([$none, $one] as $key) {
            ObjectCache::clear();
            wp_timezone();
            $counts[] = count($this->statementsDuring(fn () => (new StatusRead())->of($key)));
        }

        // With one other payer: its statement, and the names of its owed lines (the fee components, no statement).
        // The base count is the registration read's, with the fact source's two request reads.
        self::assertSame(8, $counts[0]);
        self::assertSame($counts[0] + 1, $counts[1]);
    }
}
