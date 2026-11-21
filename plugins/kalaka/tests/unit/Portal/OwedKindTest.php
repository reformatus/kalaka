<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Portal;

use Kalaka\Portal\Api\OwedKind;
use Kalaka\Pricing\Api\FeeStage;
use Kalaka\Pricing\Api\LineName;
use PHPUnit\Framework\TestCase;

/** What an owed row is follows what names its source, never the sign. */
final class OwedKindTest extends TestCase
{
    public function testTheTierStageIsTheBasePriceWhateverNamesIt(): void
    {
        self::assertSame(OwedKind::Base, OwedKind::of(new LineName(LineName::FEE, 'Díj', stage: FeeStage::Tier)));
        self::assertSame(
            OwedKind::Base,
            OwedKind::of(new LineName(LineName::COMPONENT, 'Teszt sáv', stage: FeeStage::Tier))
        );
    }

    public function testEveryOtherLineIsReadByItsType(): void
    {
        self::assertSame(
            OwedKind::ManualCharge,
            OwedKind::of(new LineName(LineName::MANUAL_CHARGE, 'Kézi díj', 'Teszt', stage: FeeStage::Extras))
        );
        self::assertSame(
            OwedKind::ManualDiscount,
            OwedKind::of(new LineName(LineName::MANUAL_DISCOUNT, 'Kézi kedvezmény', null, stage: FeeStage::Extras))
        );
        self::assertSame(
            OwedKind::Component,
            OwedKind::of(new LineName(LineName::COMPONENT, 'Teszt', stage: FeeStage::Support))
        );
        self::assertSame(OwedKind::Fee, OwedKind::of(new LineName(LineName::FEE, 'Díj')));
    }
}
