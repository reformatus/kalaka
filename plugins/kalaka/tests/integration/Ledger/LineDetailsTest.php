<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Ledger;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Ledger\Api\LineKind;
use Kalaka\Ledger\Api\NewLine;
use Kalaka\Ledger\Api\PaymentMethod;
use Kalaka\Registrations\Api\RegistrationKey;

/** S22: the method, the correction flag and the note of a manual line, and the display read. */
final class LineDetailsTest extends LedgerTestCase
{
    private function manual(
        LineKind $kind,
        string $amount,
        string $key,
        ?PaymentMethod $method = PaymentMethod::Cash,
        bool $correction = false,
        ?string $note = null,
    ): NewLine {
        return new NewLine(
            new RegistrationKey(self::EVENT, '12A3'),
            $kind,
            'participant',
            $this->money($amount),
            new Subject('wp_user', null, '7'),
            $key,
            '2026-11-29',
            null,
            $method,
            $correction,
            $note,
        );
    }

    public function testTheMethodAndTheCorrectionFlagAreStoredOnTheLine(): void
    {
        $this->registration();
        $refund = $this->manual(LineKind::ManualRefund, '500', 'r1', PaymentMethod::CardTerminal, true, 'ok');
        $stored = $this->record($refund)[0];

        self::assertSame(PaymentMethod::CardTerminal, $stored->method);
        self::assertTrue($stored->correction);
        $row = $this->rowOf('kalaka_ledger_lines', self::EVENT, '12A3');
        self::assertSame('card_terminal', $row['method'] ?? null);
        self::assertSame('1', $row['correction'] ?? null);
    }

    public function testALineWithoutAMethodKeepsItNull(): void
    {
        $this->registration();
        $stored = $this->record($this->manual(LineKind::ManualPayment, '-500', 'p1', null))[0];

        self::assertNull($stored->method);
        self::assertFalse($stored->correction);
    }

    /** @return array<string, array{LineKind, string, bool, ?string}> */
    public static function brokenDetails(): array
    {
        return [
            'a method on a charge' => [LineKind::Charge, '500', false, null],
            'a correction that is no refund' => [LineKind::ManualPayment, '-500', true, 'reason'],
            'a correction without a reason' => [LineKind::ManualRefund, '500', true, null],
            'a note on a charge' => [LineKind::Charge, '500', false, 'x'],
            'a note over 500 characters' => [LineKind::ManualPayment, '-500', false, 'x'],
        ];
    }

    /** @dataProvider brokenDetails */
    public function testBrokenDetailsAreASystemFailureAndStoreNothing(
        LineKind $kind,
        string $amount,
        bool $correction,
        ?string $note,
    ): void {
        $method = $kind === LineKind::Charge && $note === null ? PaymentMethod::Cash : null;
        $note = $note === 'x' && $kind === LineKind::ManualPayment ? str_repeat('x', 501) : $note;
        $this->registration();
        $line = new NewLine(
            new RegistrationKey(self::EVENT, '12A3'),
            $kind,
            'participant',
            $this->money($amount),
            new Subject('wp_user', null, '7'),
            'b1',
            $kind->carriesValueDate() ? '2026-11-29' : null,
            null,
            $method,
            $correction,
            $note,
        );

        try {
            $this->record($line);
            self::fail('The line was accepted.');
        } catch (SystemFailure) {
            self::assertSame(0, $this->lineCount());
        }
    }

    public function testTheEntriesComeNewestFirstWithTheirNotes(): void
    {
        $registration = $this->registration();
        $this->record(
            $this->manual(LineKind::ManualPayment, '-500', 'p1', PaymentMethod::BankTransfer, false, 'Teszt ref')
        );
        $this->record($this->manual(LineKind::ManualRefund, '100', 'r1', PaymentMethod::Cash));

        $entries = $this->ledger->entriesOf($registration);

        self::assertCount(2, $entries);
        self::assertSame(LineKind::ManualRefund, $entries[0]->kind);
        self::assertNull($entries[0]->note);
        self::assertSame(LineKind::ManualPayment, $entries[1]->kind);
        self::assertSame('Teszt ref', $entries[1]->note);
        self::assertSame(PaymentMethod::BankTransfer, $entries[1]->method);
        self::assertSame('-500.00000000', $entries[1]->amount->amount());
        self::assertSame('wp_user', $entries[1]->source->type);
    }
}
