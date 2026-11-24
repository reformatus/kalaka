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
        $to->payerKind(new PayerKind('congregation', 'Gyülekezet'));
        $to->payerKind(new PayerKind('sponsor', 'Támogató'));

        self::assertEquals(
            [new PayerKind('congregation', 'Gyülekezet'), new PayerKind('sponsor', 'Támogató')],
            $to->payerKinds()
        );
    }

    public function testAMalformedRepeatedOrParticipantKindFailsTheContributor(): void
    {
        $to = new Contributions();
        $to->payerKind(new PayerKind('congregation', 'Gyülekezet'));

        $refused = [['congregation', 'Másik'], ['participant', 'Jelentkező'], ['Bad-Id', 'Rossz'], ['sponsor', '']];
        foreach ($refused as [$id, $label]) {
            try {
                $to->payerKind(new PayerKind($id, $label));
                self::fail($id . ' should be refused.');
            } catch (\InvalidArgumentException) {
                self::assertCount(1, $to->payerKinds());
            }
        }
    }
}
