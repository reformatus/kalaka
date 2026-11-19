<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Ledger;

use Kalaka\Foundation\Api\Actor\SystemActor;
use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\PermissionDenied;
use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Ledger\Api\IdempotencyConflict;
use Kalaka\Ledger\Api\Ledger;
use Kalaka\Ledger\Api\LineKind;
use Kalaka\Ledger\Api\NewLine;
use Kalaka\Ledger\Api\PaymentMethod;
use Kalaka\Ledger\RecordPayment;
use Kalaka\Ledger\RecordRefund;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Tests\Integration\Foundation\AdminProbe;

/** S22: the "record refund" use case (owner ruling 2026-11-18, point 5). */
final class RecordRefundTest extends ManualEntryTestCase
{
    public function testItRecordsOnePositiveManualRefundLine(): void
    {
        $this->exec(new RecordRefund(), $this->paymentInput(['method' => 'cash', 'note' => 'Teszt visszautalás']));

        $lines = $this->lines();
        self::assertCount(1, $lines);
        self::assertSame('manual_refund', $lines[0]['kind']);
        self::assertSame('5000.00000000', $lines[0]['amount']);
        self::assertSame('cash', $lines[0]['method']);
        self::assertSame('0', $lines[0]['correction']);
        self::assertSame('2026-11-28 23:00:00.000000', $lines[0]['value_date']);
        self::assertSame('Teszt visszautalás', $this->notes()[0]['note']);
    }

    public function testARefundWithoutANoteIsAllowed(): void
    {
        $this->exec(new RecordRefund(), $this->paymentInput());

        self::assertSame([], $this->notes());
    }

    /** The owner's ruling 2026-11-19: a refund is never a correction; that is the correction's own use case. */
    public function testARefundTakesNoCorrectionFlag(): void
    {
        try {
            $this->exec(new RecordRefund(), $this->paymentInput(['correction' => true, 'note' => 'ok']));
            self::fail('A refund took a correction flag.');
        } catch (InvalidInput) {
            self::assertSame([], $this->lines());
        }
    }

    /** C4-2: the refund replays like a payment: the same key and input write once. */
    public function testTheSameKeyAndInputWritesOnce(): void
    {
        $first = $this->exec(new RecordRefund(), $this->paymentInput(['note' => 'ref 1']));
        $second = $this->exec(new RecordRefund(), $this->paymentInput(['note' => 'ref 1']));

        self::assertSame($first, $second);
        self::assertCount(1, $this->lines());
        self::assertCount(1, $this->notes());
    }

    /** C4-2: the refund's audit entry holds what was recorded and never the note. */
    public function testTheAuditEntryHoldsWhatWasRecordedAndNoNote(): void
    {
        $this->exec(new RecordRefund(), $this->paymentInput(['method' => 'cash', 'note' => 'Teszt titok']));

        $entry = null;
        foreach ($this->entries() as $candidate) {
            if ($candidate['action'] === 'kalaka/record-refund') {
                $entry = $candidate;
            }
        }
        self::assertNotNull($entry);
        self::assertSame('succeeded', $entry['outcome']);
        $changes = (string) $entry['changes'];
        self::assertStringContainsString('manual_refund', $changes);
        self::assertStringContainsString('cash', $changes);
        self::assertStringNotContainsString('titok', $changes);
        self::assertContains('registration/' . $this->event . '/00A0', $this->subjectsOf($entry['id']));
    }

    /** C4-1: the same key reused for a refund after a payment is a conflict. */
    public function testTheSameKeyOnAPaymentAndARefundIsAConflict(): void
    {
        $this->exec(new RecordPayment(), $this->paymentInput());

        try {
            $this->exec(new RecordRefund(), $this->paymentInput());
            self::fail('A refund reused the payment key.');
        } catch (IdempotencyConflict $conflict) {
            self::assertSame(409, $conflict->status());
            self::assertSame('kalaka_idempotency_conflict', $conflict->errorCode());
            self::assertCount(1, $this->lines());
        }
    }

    /** C4-1: the same key reused for a payment with another payer is a conflict. */
    public function testTheSameKeyForAPaymentWithAnotherPayerIsAConflict(): void
    {
        $this->recordPaymentWithPayer('sponsor');

        try {
            $this->exec(new RecordPayment(), $this->paymentInput());
            self::fail('A payment reused the key of another payer.');
        } catch (IdempotencyConflict $conflict) {
            self::assertSame(409, $conflict->status());
            self::assertSame('kalaka_idempotency_conflict', $conflict->errorCode());
            self::assertCount(1, $this->lines());
        }
    }

    /** C2-1: a key with a trailing newline is invalid input, not a system failure. */
    public function testAKeyWithATrailingNewlineIsInvalidInput(): void
    {
        foreach ([new RecordPayment(), new RecordRefund()] as $position => $useCase) {
            try {
                $this->exec($useCase, $this->paymentInput(['idempotency_key' => "k" . $position . "\n"]));
                self::fail('A key with a trailing newline was accepted.');
            } catch (InvalidInput) {
                self::assertSame([], $this->lines());
            }
        }
    }

    public function testThePaymentActionDoesNotCarryTheRefund(): void
    {
        $user = $this->plainUser();
        $this->grantOn($user, ['kalaka_record_payment']);

        $this->expectException(PermissionDenied::class);
        $this->exec(new RecordRefund(), $this->paymentInput(), new UserActor($user));
    }

    public function testTheRefundActionRecordsInsideItsView(): void
    {
        $user = $this->plainUser();
        $this->grantOn($user, ['kalaka_record_refund']);

        $this->exec(new RecordRefund(), $this->paymentInput(), new UserActor($user));

        self::assertCount(1, $this->lines());
    }

    public function testARefundSharesTheAmountAndDateRules(): void
    {
        foreach ([['amount' => '0'], ['value_date' => '2026-12-05'], ['currency' => 'EUR']] as $bad) {
            try {
                $this->exec(new RecordRefund(), $this->paymentInput($bad));
                self::fail('Bad input was accepted.');
            } catch (InvalidInput) {
                self::assertSame([], $this->lines());
            }
        }
    }

    public function testARefundTakesEveryPaymentMethod(): void
    {
        foreach (['bank_transfer', 'cash', 'card_terminal'] as $position => $method) {
            $this->exec(
                new RecordRefund(),
                $this->paymentInput(['method' => $method, 'idempotency_key' => 'm' . $position])
            );
        }

        self::assertCount(3, $this->lines());
    }

    /**
     * Records the payment line the use case's input names, for a payer the extension surface will declare:
     * the Ledger takes any payer-kind ID, and only the use case checks the event's declared kinds.
     */
    private function recordPaymentWithPayer(string $payer): void
    {
        $key = new RegistrationKey($this->event, '00A0');
        $this->runner->run(
            new AdminProbe(function (array $input, Context $context) use ($key, $payer): int {
                $this->lockRegistrations($context, $key);

                return (new Ledger())->record(
                    new NewLine(
                        $key,
                        LineKind::ManualPayment,
                        $payer,
                        Money::of('-5000', Currency::HUF),
                        new Subject('wp_user', null, (string) $this->admin->userId),
                        'manual:k1',
                        '2026-11-29',
                        null,
                        PaymentMethod::BankTransfer,
                    ),
                    $context
                )->line->id;
            }),
            [],
            new SystemActor()
        );
    }
}
