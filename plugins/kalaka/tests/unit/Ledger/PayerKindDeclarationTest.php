<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Ledger;

use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\Ledger\Api\PayerKind;
use Kalaka\Ledger\Api\PayerKindInput;
use Kalaka\Ledger\Api\PayerKindSource;
use PHPUnit\Framework\TestCase;

/** S29: a payer kind is declared, through a `PayerKindSource` contributed with `payerKindSource()`. */
final class PayerKindDeclarationTest extends TestCase
{
    private static function source(string $id, string $label, ?int $event = null): PayerKindSource
    {
        return new class ($id, $label, $event) implements PayerKindSource {
            public function __construct(
                private readonly string $id,
                private readonly string $label,
                private readonly ?int $event,
            ) {
            }

            public function kind(): PayerKind
            {
                return new PayerKind($this->id, $this->label);
            }

            public function appliesTo(PayerKindInput $input): bool
            {
                return $this->event === null || $this->event === $input->eventId;
            }
        };
    }

    public function testADeclaredKindIsListedInContributionOrderAndAppliesPerEvent(): void
    {
        $to = new Contributions();
        $to->payerKindSource(self::source('congregation', 'Gyülekezet', 5));
        $to->payerKindSource(self::source('sponsor', 'Támogató'));

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
        $to->payerKindSource(self::source('congregation', 'Gyülekezet'));

        $refused = [['congregation', 'Másik'], ['participant', 'Jelentkező'], ['Bad-Id', 'Rossz'], ['sponsor', '']];
        foreach ($refused as [$id, $label]) {
            try {
                $to->payerKindSource(self::source($id, $label));
                self::fail($id . ' should be refused.');
            } catch (\InvalidArgumentException) {
                self::assertCount(1, $to->payerKinds());
            }
        }
    }
}
