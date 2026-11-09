<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Ledger;

use Kalaka\Ledger\Api\LineKind;
use Kalaka\Ledger\Api\PaymentFact;
use Kalaka\Registrations\Api\RegistrationKey;

/** AC6: the payment facts of a registration. */
final class PaymentFactsTest extends LedgerTestCase
{
    public function testOnlyThePaymentKindsAreReturnedOrderedByTheirInstant(): void
    {
        update_option('timezone_string', 'Europe/Budapest');
        $registration = $this->registration();
        $stored = $this->record(
            $this->line($registration, LineKind::Charge, '48000', 'k1'),
            $this->line($registration, LineKind::Adjustment, '-100', 'k2'),
            $this->line($registration, LineKind::ManualPayment, '-20000', 'k3', '2026-12-03'),
            $this->line($registration, LineKind::Payment, '-10000', 'k4', null, '2026-12-01 10:00:00'),
            $this->line($registration, LineKind::Refund, '500', 'k5', null, '2026-12-03 09:00:00'),
            $this->line($registration, LineKind::ManualRefund, '300', 'k6', '2026-12-02'),
            $this->line($registration, LineKind::Transfer, '-700', 'k7', '2026-12-01'),
        );

        $facts = $this->ledger->paymentFactsOf($registration);

        self::assertSame(
            [$stored[6]->id, $stored[3]->id, $stored[5]->id, $stored[2]->id, $stored[4]->id],
            array_map(static fn (PaymentFact $fact): int => $fact->id, $facts)
        );
        self::assertSame(
            [LineKind::Transfer, LineKind::Payment, LineKind::ManualRefund, LineKind::ManualPayment, LineKind::Refund],
            array_map(static fn (PaymentFact $fact): LineKind => $fact->kind, $facts)
        );
        self::assertSame(
            ['-700.00000000', '-10000.00000000', '300.00000000', '-20000.00000000', '500.00000000'],
            array_map(static fn (PaymentFact $fact): string => $fact->amount->amount(), $facts)
        );
        self::assertSame(
            ['participant'],
            array_values(array_unique(array_map(static fn (PaymentFact $fact): string => $fact->payer, $facts)))
        );

        $manual = $facts[3];
        self::assertNotNull($manual->valueDate);
        self::assertSame('2026-12-02 23:00:00.000000', $manual->valueDate->format('Y-m-d H:i:s.u'));
        self::assertNull($manual->paidAt);
        self::assertSame(
            '2026-12-02 23:00:00',
            $manual->valueAt()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')
        );
        self::assertEquals($manual->valueDate, $manual->valueAt());

        $online = $facts[1];
        self::assertNull($online->valueDate);
        self::assertNotNull($online->paidAt);
        self::assertSame('2026-12-01 10:00:00', $online->paidAt->format('Y-m-d H:i:s'));
        self::assertEquals($online->paidAt, $online->valueAt());
    }

    /** #42 AC14: one line of every kind; the facts are every payment-fact kind and no charge or adjustment. */
    public function testALineOfEveryPaymentFactKindAndNoOtherIsReturned(): void
    {
        $registration = $this->registration();
        $lines = [];
        foreach (LineKind::cases() as $number => $kind) {
            $amount = $kind->allows($this->money('100')) ? '100' : '-100';
            $lines[] = $this->fitted($registration, $kind, $amount, 'kind:' . $number);
        }
        $this->record(...$lines);

        $kinds = array_map(
            static fn (PaymentFact $fact): string => $fact->kind->value,
            $this->ledger->paymentFactsOf($registration)
        );
        sort($kinds);
        $expected = array_map(
            static fn (LineKind $kind): string => $kind->value,
            array_values(array_filter(LineKind::cases(), static fn (LineKind $kind): bool => $kind->isPaymentFact()))
        );
        sort($expected);

        self::assertSame(['manual_payment', 'manual_refund', 'payment', 'refund', 'transfer'], $expected);
        self::assertSame($expected, $kinds);
        self::assertNotContains(LineKind::Charge->value, $kinds);
        self::assertNotContains(LineKind::Adjustment->value, $kinds);
    }

    public function testFactsOfTheSameInstantComeInTheOrderOfTheirIds(): void
    {
        update_option('timezone_string', 'Europe/Budapest');
        $registration = $this->registration();
        $stored = $this->record(
            $this->line($registration, LineKind::ManualPayment, '-3', 'k1', '2026-12-03'),
            $this->line($registration, LineKind::Payment, '-2', 'k2', null, '2026-12-02 23:00:00'),
            $this->line($registration, LineKind::ManualPayment, '-1', 'k3', '2026-12-03'),
        );

        $ids = array_map(static fn (PaymentFact $fact): int => $fact->id, $this->ledger->paymentFactsOf($registration));

        self::assertSame([$stored[0]->id, $stored[1]->id, $stored[2]->id], $ids);
    }

    public function testTheInstantOfAValueDateDoesNotFollowTheSiteZoneAfterRecording(): void
    {
        $registration = $this->registration();
        $this->record($this->line($registration, LineKind::ManualPayment, '-1', 'k1', '2026-12-03'));

        update_option('timezone_string', 'UTC');
        $inUtc = $this->ledger->paymentFactsOf($registration)[0]->valueAt();
        update_option('timezone_string', 'Europe/Budapest');
        $inBudapest = $this->ledger->paymentFactsOf($registration)[0]->valueAt();

        self::assertSame(
            '2026-12-02 23:00:00',
            $inUtc->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')
        );
        self::assertSame(
            '2026-12-02 23:00:00',
            $inBudapest->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')
        );
    }

    public function testARegistrationWithoutPaymentsHasNoFacts(): void
    {
        $registration = $this->registration();
        $this->record($this->line($registration, LineKind::Charge, '1000', 'k1'));

        self::assertSame([], $this->ledger->paymentFactsOf($registration));
        self::assertSame([], $this->ledger->paymentFactsOf(new RegistrationKey(self::EVENT, '00Z9')));
    }
}
