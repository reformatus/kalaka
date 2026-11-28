<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Portal;

use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Ledger\Api\Ledger;
use Kalaka\Ledger\Api\LineKind;
use Kalaka\Ledger\Api\ManualFee;
use Kalaka\Ledger\Api\ManualFeeType;
use Kalaka\Ledger\Api\NewLine;
use Kalaka\Ledger\Api\PaymentMethod;
use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\ObjectCache;
use Kalaka\Portal\Api\MoneyRead;
use Kalaka\Portal\Api\ParticipantMoney;
use Kalaka\Portal\Api\StatusRead;
use Kalaka\Portal\StatusMarkup;
use Kalaka\Pricing\Api\FeeComponentInput;
use Kalaka\Pricing\Api\FeeStage;
use Kalaka\Pricing\Api\FeeTargets;
use Kalaka\Pricing\Api\LineName;
use Kalaka\Pricing\Api\LineNames;
use Kalaka\Pricing\Api\NamedFeeComponent;
use Kalaka\Pricing\CoreFeeComponents;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\RegistrationStatus;

/** The participant's money table: the status read's `money`, for every status (owner topics 10 and 11). */
final class ParticipantMoneyTest extends PortalTestCase
{
    /** A component that names its lines, for the rows core does not have yet. */
    private function named(string $id, FeeStage $stage, string $word): NamedFeeComponent
    {
        return new class ($id, $stage, $word) implements NamedFeeComponent {
            public function __construct(
                private readonly string $id,
                private readonly FeeStage $stage,
                private readonly string $word,
            ) {
            }

            public function id(): string
            {
                return $this->id;
            }

            public function stage(): FeeStage
            {
                return $this->stage;
            }

            public function targets(FeeComponentInput $input): FeeTargets
            {
                return FeeTargets::none();
            }

            public function lineNames(RegistrationKey $registration, array $items): array
            {
                return ['' => new LineName(LineName::COMPONENT, $this->word)];
            }
        };
    }

    private function read(): StatusRead
    {
        return new StatusRead(money: new MoneyRead(new Ledger(), new LineNames(new CoreFeeComponents([
            $this->named('trip', FeeStage::Extras, 'Kirándulás'),
            $this->named('church', FeeStage::Support, 'Gyülekezeti támogatás'),
        ]))));
    }

    private function tableOf(RegistrationKey $key): ParticipantMoney
    {
        $view = $this->read()->of($key);
        self::assertNotNull($view);

        return $view->money;
    }

    private static function plain(Money $amount): string
    {
        return rtrim(rtrim($amount->amount(), '0'), '.');
    }

    /** @return list<string> */
    private static function owedOf(ParticipantMoney $money): array
    {
        return array_map(
            static fn ($row): string => $row->label . '|' . $row->kind->value . '|' . self::plain($row->amount),
            $money->owed
        );
    }

    /** @return list<string> */
    private static function movementsOf(ParticipantMoney $money): array
    {
        return array_map(
            static fn ($row): string => implode('|', [
                $row->type->value,
                $row->direction?->value ?? '-',
                $row->methodLabel ?? '-',
                $row->note ?? '-',
                $row->valueDay,
                self::plain($row->amount),
            ]),
            $money->movements
        );
    }

    /** Every case: the owed rows sum to the owed total, the movements to the paid total. */
    private function assertAddsUp(ParticipantMoney $money): void
    {
        $owed = Money::zero(Currency::HUF);
        foreach ($money->owed as $row) {
            $owed = $owed->plus($row->amount);
        }
        $paid = Money::zero(Currency::HUF);
        foreach ($money->movements as $row) {
            $paid = $paid->plus($row->amount);
        }
        self::assertTrue($owed->isEqualTo($money->owedTotal), 'the rows sum to the owed total');
        self::assertTrue($paid->isEqualTo($money->paidTotal), 'the movements sum to the paid total');
        self::assertTrue($money->owedTotal->minus($money->paidTotal)->isEqualTo($money->balance));
    }

    private function source(RegistrationKey $key, string $amount, string $source, string $payer = 'participant'): void
    {
        $this->record($this->line(
            $key,
            LineKind::Charge,
            $amount,
            'c' . ++$this->seq,
            payer: $payer,
            source: $source
        ));
    }

    private int $seq = 0;

    private function manual(
        RegistrationKey $key,
        LineKind $kind,
        string $amount,
        string $day,
        PaymentMethod $method,
        ?string $note = null,
    ): void {
        $this->record(new NewLine(
            $key,
            $kind,
            'participant',
            $this->money($amount),
            new Subject('wp_user', null, '1'),
            'm' . ++$this->seq,
            $day,
            null,
            $method,
            false,
            $note
        ));
    }

    private function correction(RegistrationKey $key, bool $raises, string $amount, string $reason): void
    {
        $this->record(new NewLine(
            $key,
            $raises ? LineKind::ManualPayment : LineKind::ManualRefund,
            'participant',
            $this->money($raises ? '-' . $amount : $amount),
            new Subject('wp_user', null, '1'),
            'k' . ++$this->seq,
            null,
            null,
            null,
            true,
            $reason
        ));
    }

    private function online(RegistrationKey $key, LineKind $kind, string $amount, string $at): void
    {
        $this->record($this->line($key, $kind, $amount, 'o' . ++$this->seq, null, $at));
    }

    private function manualFee(RegistrationKey $key, ManualFeeType $type, string $amount, string $title): int
    {
        $id = $this->inRun(function (array $input, Context $context) use ($key, $type, $amount, $title): int {
            $this->lockRegistrations($context, $key);

            return $this->ledger->recordManualFee(
                new ManualFee(
                    0,
                    $key,
                    $type,
                    'participant',
                    $this->money($amount),
                    $title,
                    'f' . ++$this->seq,
                    null,
                    $context->now()
                ),
                $context
            )->fee->id;
        });
        self::assertIsInt($id);
        $signed = $type === ManualFeeType::Charge ? $amount : '-' . $amount;
        $this->source($key, $signed, 'manual:' . $id);

        return $id;
    }

    private function erase(RegistrationKey $key): void
    {
        $this->inRun(function (array $input, Context $context) use ($key): void {
            $this->lockRegistrations($context, $key);
            $this->ledger->eraseNotesOf($key, $context);
        });
    }

    /** The owner's example table (topic 10 and 11), end to end. */
    public function testTheOwnersExample(): void
    {
        $key = $this->stored('12A3');
        $this->source($key, '60000', 'fixed_price');
        $this->source($key, '-10000', 'church');
        $this->source($key, '3000', 'trip');
        $this->manual($key, LineKind::ManualPayment, '-8000', '2026-11-27', PaymentMethod::CardTerminal);
        $this->manual($key, LineKind::ManualPayment, '-15000', '2026-11-28', PaymentMethod::BankTransfer, 'TAB27 12A3');
        $this->manual($key, LineKind::ManualRefund, '4000', '2026-11-29', PaymentMethod::BankTransfer, 'Teszt');
        $this->correction($key, true, '1000', 'visszafizetés összegének javítása');

        $money = $this->tableOf($key);

        // The base price first; the positive row before the negative one although the ledger order is reversed.
        self::assertSame([
            'Részvételi díj|base|60000',
            'Kirándulás|component|3000',
            'Gyülekezeti támogatás|component|-10000',
        ], self::owedOf($money));
        self::assertSame([
            'payment|-|Bankkártya|-|2026-11-27|8000',
            'payment|-|Átutalás|TAB27 12A3|2026-11-28|15000',
            'refund|-|Átutalás|Teszt|2026-11-29|-4000',
            // The correction counts from the moment it is recorded: 2026-11-30 09:00 in Budapest, so it is last.
            'correction|raises|-|visszafizetés összegének javítása|2026-11-30|1000',
        ], self::movementsOf($money));
        self::assertSame('53000', self::plain($money->owedTotal));
        self::assertSame('20000', self::plain($money->paidTotal));
        self::assertSame('33000', self::plain($money->balance));
        self::assertSame('33000', self::plain($money->due ?? Money::zero(Currency::HUF)));
        self::assertNull($money->overpaid);
        $this->assertAddsUp($money);
    }

    public function testNothingPaidShowsNoMovementsAndAZeroPaidTotal(): void
    {
        $key = $this->stored('12A3');
        $this->source($key, '48000', 'fixed_price');

        $money = $this->tableOf($key);

        self::assertSame([], $money->movements);
        self::assertSame('0', self::plain($money->paidTotal));
        self::assertSame('48000', self::plain($money->due ?? Money::zero(Currency::HUF)));
        $this->assertAddsUp($money);
    }

    public function testAnOverpaymentHasNoDueAndASettledRegistrationHasAZeroDue(): void
    {
        $key = $this->stored('12A3');
        $this->source($key, '48000', 'fixed_price');
        $this->manual($key, LineKind::ManualPayment, '-50000', '2026-12-02', PaymentMethod::Cash);

        $over = $this->tableOf($key);
        self::assertNull($over->due);
        self::assertSame('2000', self::plain($over->overpaid ?? Money::zero(Currency::HUF)));
        $this->assertAddsUp($over);

        $settled = $this->stored('00A2');
        $this->source($settled, '1000', 'fixed_price');
        $this->manual($settled, LineKind::ManualPayment, '-1000', '2026-12-02', PaymentMethod::Cash);
        $money = $this->tableOf($settled);
        self::assertNull($money->overpaid);
        self::assertNotNull($money->due);
        self::assertSame('0', self::plain($money->due));
    }

    public function testOnlyDueIsRoundedUp(): void
    {
        $key = $this->stored('12A3');
        $this->source($key, '1000.50', 'fixed_price');

        $money = $this->tableOf($key);

        self::assertSame('1000.5', self::plain($money->owedTotal));
        self::assertSame('1000.5', self::plain($money->balance));
        self::assertSame('1001', self::plain($money->due ?? Money::zero(Currency::HUF)));
    }

    public function testACorrectionEitherWayKeepsItsDirectionAndTheSameDayKeepsLedgerOrder(): void
    {
        $key = $this->stored('12A3');
        $this->source($key, '10000', 'fixed_price');
        // Recorded first: a correction counts from now (2026-11-30 09:00 Budapest), a later ledger line dated
        // the same day counts from 00:00; the order is the day, then the ledger, not the instant (N9).
        $this->correction($key, false, '700', 'Teszt lefelé');
        $this->manual($key, LineKind::ManualPayment, '-300', '2026-11-30', PaymentMethod::Cash);
        $this->correction($key, true, '200', 'Teszt felfelé');

        $money = $this->tableOf($key);

        self::assertSame([
            'correction|lowers|-|Teszt lefelé|2026-11-30|-700',
            'payment|-|Készpénz|-|2026-11-30|300',
            'correction|raises|-|Teszt felfelé|2026-11-30|200',
        ], self::movementsOf($money));
        self::assertSame('-200', self::plain($money->paidTotal));
        $this->assertAddsUp($money);
    }

    public function testAnOnlinePaymentAndRefundReadOnlineAndCountFromTheSiteTimeZoneDay(): void
    {
        $key = $this->stored('12A3');
        $this->source($key, '10000', 'fixed_price');
        // 23:30 UTC on 2026-12-01 is 00:30 on 2026-12-02 in Budapest.
        $this->online($key, LineKind::Payment, '-6000', '2026-12-01 23:30:00');
        $this->online($key, LineKind::Refund, '1000', '2026-12-03 10:00:00');

        $money = $this->tableOf($key);

        self::assertSame([
            'payment|-|Online|-|2026-12-02|6000',
            'refund|-|Online|-|2026-12-03|-1000',
        ], self::movementsOf($money));
        $this->assertAddsUp($money);
    }

    /** A withdrawal reverses every line; a re-price writes a difference: one row per source, zero rows dropped. */
    public function testWithdrawnAndRemovedRegistrationsKeepTheirMovementsAndShowNoReversedRows(): void
    {
        foreach ([RegistrationStatus::Withdrawn, RegistrationStatus::Removed] as $index => $status) {
            $key = $this->stored(['00A5', '00A8'][$index], $status);
            $this->source($key, '40000', 'fixed_price');
            $this->source($key, '20000', 'fixed_price');
            $this->source($key, '3000', 'trip');
            $this->manual($key, LineKind::ManualPayment, '-30000', '2026-12-02', PaymentMethod::BankTransfer);
            $this->source($key, '-3000', 'trip');

            $money = $this->tableOf($key);

            self::assertSame(['Részvételi díj|base|60000'], self::owedOf($money), 'a re-price is one row');
            self::assertSame(['payment|-|Átutalás|-|2026-12-02|30000'], self::movementsOf($money));

            $this->source($key, '-60000', 'fixed_price');
            $reversed = $this->tableOf($key);
            self::assertSame([], $reversed->owed);
            self::assertSame('0', self::plain($reversed->owedTotal));
            self::assertSame('2026-12-02', $reversed->movements[0]->valueDay);
            self::assertNull($reversed->due);
            self::assertSame('30000', self::plain($reversed->overpaid ?? Money::zero(Currency::HUF)));
            $this->assertAddsUp($reversed);
        }
    }

    public function testAWaitlistedRegistrationHasAnEmptyTable(): void
    {
        $money = $this->tableOf($this->stored('12A3', RegistrationStatus::Waitlisted));

        self::assertSame([], $money->owed);
        self::assertSame([], $money->movements);
        self::assertSame('0', self::plain($money->owedTotal));
        self::assertNotNull($money->due);
        self::assertSame('0', self::plain($money->due));
    }

    public function testAMovedPaymentIsARefundWithItsMethodOnOneRegistrationAndAPaymentOnTheOther(): void
    {
        $from = $this->stored('12A3');
        $to = $this->stored('00A2');
        $this->source($from, '10000', 'fixed_price');
        $this->source($to, '10000', 'fixed_price');
        $this->manual($from, LineKind::ManualPayment, '-5000', '2026-12-01', PaymentMethod::BankTransfer);
        $bank = PaymentMethod::BankTransfer;
        $this->manual($from, LineKind::ManualRefund, '5000', '2026-12-02', $bank, 'Áthelyezve: 00A2');
        $this->manual($to, LineKind::ManualPayment, '-5000', '2026-12-02', $bank, 'Áthelyezve: 12A3');

        self::assertSame([
            'payment|-|Átutalás|-|2026-12-01|5000',
            'refund|-|Átutalás|Áthelyezve: 00A2|2026-12-02|-5000',
        ], self::movementsOf($this->tableOf($from)));
        self::assertSame(
            ['payment|-|Átutalás|Áthelyezve: 12A3|2026-12-02|5000'],
            self::movementsOf($this->tableOf($to))
        );
    }

    public function testManualRowsAreNamedByTheirFactAndAnErasedTitleFallsBackByType(): void
    {
        $key = $this->stored('12A3');
        $this->source($key, '40000', 'fixed_price');
        $this->manualFee($key, ManualFeeType::Discount, '500', 'Teszt kedvezmény');
        $this->manualFee($key, ManualFeeType::Charge, '3000', 'Teszt póló');
        $this->manual($key, LineKind::ManualPayment, '-1000', '2026-12-02', PaymentMethod::Cash, 'Teszt megjegyzés');

        $money = $this->tableOf($key);
        self::assertSame([
            'Részvételi díj|base|40000',
            'Teszt póló|manual_charge|3000',
            'Teszt kedvezmény|manual_discount|-500',
        ], self::owedOf($money));
        self::assertSame(['payment|-|Készpénz|Teszt megjegyzés|2026-12-02|1000'], self::movementsOf($money));

        $this->erase($key);
        ObjectCache::clear();

        $erased = $this->tableOf($key);
        self::assertSame([
            'Részvételi díj|base|40000',
            'Díj|manual_charge|3000',
            'Kedvezmény|manual_discount|-500',
        ], self::owedOf($erased));
        self::assertSame(['payment|-|Készpénz|-|2026-12-02|1000'], self::movementsOf($erased));
        self::assertSame('42500', self::plain($erased->owedTotal));
    }

    public function testAnErasedCorrectionKeepsItsTypeAndDirectionWithoutItsText(): void
    {
        $key = $this->stored('12A3');
        $this->source($key, '10000', 'fixed_price');
        $this->correction($key, true, '200', 'Teszt');
        $this->erase($key);

        self::assertSame(['correction|raises|-|-|2026-11-30|200'], self::movementsOf($this->tableOf($key)));
    }

    public function testALineOfAnUnnamedSourceReadsTheFallbackWordAndOnlyTheTierIsTheBasePrice(): void
    {
        $key = $this->stored('12A3');
        $this->source($key, '40000', 'fixed_price');
        // An unregistered component's line, and a line of another source type.
        $this->source($key, '-2000', 'retention');
        $this->record($this->line($key, LineKind::Charge, '1500', 'x1', source: 'misc', sourceType: 'other'));

        $money = $this->tableOf($key);

        self::assertSame(
            ['Részvételi díj|base|40000', 'Díj|fee|1500', 'Díj|fee|-2000'],
            self::owedOf($money)
        );
        $this->assertAddsUp($money);
    }

    public function testOnlyTheParticipantsOwnLinesAreRead(): void
    {
        $key = $this->stored('12A3');
        $this->source($key, '40000', 'fixed_price');
        $this->source($key, '5000', 'fixed_price', 'congregation');
        $this->record($this->line(
            $key,
            LineKind::ManualPayment,
            '-9000',
            'q1',
            '2026-12-02',
            payer: 'congregation'
        ));

        $money = $this->tableOf($key);

        self::assertSame(['Részvételi díj|base|40000'], self::owedOf($money));
        self::assertSame([], $money->movements);
        self::assertSame('40000', self::plain($money->owedTotal));
    }

    public function testTheActiveRegistrationSeesOnlyItsOwnTable(): void
    {
        $mine = $this->stored('12A3');
        $other = $this->stored('00A2');
        $this->source($mine, '1000', 'fixed_price');
        $this->source($other, '9000', 'fixed_price');
        $this->manual($other, LineKind::ManualPayment, '-4000', '2026-12-02', PaymentMethod::Cash, 'Teszt másik');

        $this->activate($mine);
        $view = (new StatusRead())->forActive();

        self::assertNotNull($view);
        self::assertSame('1000', self::plain($view->money->owedTotal));
        self::assertSame([], $view->money->movements);

        $this->activate(null);
        self::assertNull((new StatusRead())->forActive());
        $this->activate(new RegistrationKey(self::EVENT, '00B3'));
        self::assertNull((new StatusRead())->forActive());
    }

    /** SB-11: a closed or archived event still shows nothing in the block, though the read carries the money. */
    public function testAClosedEventShowsNothingButTheReadStillCarriesTheMoney(): void
    {
        $key = $this->stored('12A3');
        $this->source($key, '48000', 'fixed_price');
        $this->event(['state' => 'archived']);
        $this->activate($key);

        $view = (new StatusRead())->forActive();

        self::assertNotNull($view);
        self::assertSame('48000', self::plain($view->money->owedTotal));
        self::assertStringNotContainsString('48', self::text(StatusMarkup::status($view, false)));
    }

    public function testTheTableCostsTheSameNumberOfStatementsWhateverTheLineCount(): void
    {
        wp_timezone(); // WordPress loads its options once; that read is not the money's
        $few = $this->stored('12A3');
        $many = $this->stored('00A2');
        foreach ([[$few, 10, 2], [$many, 30, 8]] as [$key, $count, $fees]) {
            $this->source($key, '40000', 'fixed_price');
            for ($line = 1; $line <= $fees; $line++) {
                $type = $line % 2 === 0 ? ManualFeeType::Charge : ManualFeeType::Discount;
                $this->manualFee($key, $type, '100', 'Teszt');
            }
            for ($line = 1; $line <= $count; $line++) {
                $this->manual($key, LineKind::ManualPayment, '-10', '2026-12-02', PaymentMethod::Cash, 'Teszt');
            }
        }

        $counts = [];
        foreach ([$few, $many] as $key) {
            ObjectCache::clear();
            wp_timezone();
            $counts[] = count($this->statementsDuring(fn () => $this->read()->of($key)));
        }

        self::assertSame($counts[0], $counts[1]);
        // The registration, the event, the decisions, the summary, the statement and the manual fees.
        self::assertSame(6, $counts[0]);
    }
}
