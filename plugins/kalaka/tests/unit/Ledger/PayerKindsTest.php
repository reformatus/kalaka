<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Ledger;

use Kalaka\Ledger\Api\PayerKind;
use Kalaka\Ledger\Api\PayerKinds;
use Kalaka\Ledger\Api\PaymentMethod;
use PHPUnit\Framework\TestCase;

/** S22: the payer kinds and the payment methods, with their labels. */
final class PayerKindsTest extends TestCase
{
    public function testTheParticipantIsDeclaredFirstWithALabel(): void
    {
        $kinds = (new PayerKinds())->of(5);

        self::assertEquals([new PayerKind('participant', 'Jelentkező')], $kinds);
        self::assertSame('Jelentkező', (new PayerKinds())->label('participant'));
    }

    public function testAnUndeclaredKindHasNoLabel(): void
    {
        self::assertNull((new PayerKinds())->label('congregation'));
    }

    public function testEveryMethodHasAStoredValueAndALabel(): void
    {
        self::assertSame(
            ['bank_transfer' => 'Átutalás', 'cash' => 'Készpénz', 'card_terminal' => 'Bankkártya'],
            array_column(
                array_map(
                    static fn (PaymentMethod $method): array => [
                        'value' => $method->value,
                        'label' => $method->label(),
                    ],
                    PaymentMethod::cases()
                ),
                'label',
                'value'
            )
        );
    }
}
