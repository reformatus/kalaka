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

        $kinds = static fn (Contributions $to): array => array_map(
            static fn (PayerKindSource $source): PayerKind => $source->kind(),
            $to->payerKindSources()
        );
        $applying = static fn (Contributions $to, int $event): array => array_map(
            static fn (PayerKindSource $source): string => $source->kind()->id,
            array_values(array_filter(
                $to->payerKindSources(),
                static fn (PayerKindSource $source): bool => $source->appliesTo(new PayerKindInput($event))
            ))
        );

        self::assertEquals(
            [new PayerKind('congregation', 'Gyülekezet'), new PayerKind('sponsor', 'Támogató')],
            $kinds($to)
        );
        self::assertSame(['congregation', 'sponsor'], $applying($to, 5));
        self::assertSame(['sponsor'], $applying($to, 6));
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
                self::assertCount(1, $to->payerKindSources());
            }
        }
    }
}
