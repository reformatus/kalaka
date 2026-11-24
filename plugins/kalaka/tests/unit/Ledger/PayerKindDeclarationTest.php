<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Ledger;

use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\Ledger\Api\PayerKind;
use PHPUnit\Framework\TestCase;

/** S29: a payer kind is only declared, through `Contributions::payerKind()`. */
final class PayerKindDeclarationTest extends TestCase
{
    public function testADeclaredKindIsListedInContributionOrder(): void
    {
        $to = new Contributions();
        $to->payerKind(new PayerKind('congregation', 'Gyülekezet'), static fn (int $eventId): bool => $eventId === 5);
        $to->payerKind(new PayerKind('sponsor', 'Támogató'), static fn (): bool => true);

        self::assertEquals(
            [new PayerKind('congregation', 'Gyülekezet'), new PayerKind('sponsor', 'Támogató')],
            $to->payerKinds()
        );
        self::assertEquals(
            [new PayerKind('congregation', 'Gyülekezet'), new PayerKind('sponsor', 'Támogató')],
            $to->payerKindsFor(5)
        );
        self::assertEquals([new PayerKind('sponsor', 'Támogató')], $to->payerKindsFor(6));
    }

    public function testAMalformedRepeatedOrParticipantKindFailsTheContributor(): void
    {
        $to = new Contributions();
        $always = static fn (): bool => true;
        $to->payerKind(new PayerKind('congregation', 'Gyülekezet'), $always);

        $refused = [['congregation', 'Másik'], ['participant', 'Jelentkező'], ['Bad-Id', 'Rossz'], ['sponsor', '']];
        foreach ($refused as [$id, $label]) {
            try {
                $to->payerKind(new PayerKind($id, $label), $always);
                self::fail($id . ' should be refused.');
            } catch (\InvalidArgumentException) {
                self::assertCount(1, $to->payerKinds());
            }
        }
    }
}
