<?php

declare(strict_types=1);

namespace Kalaka\Portal\Api;

use Kalaka\Pricing\Api\FeeStage;
use Kalaka\Pricing\Api\LineName;

/** What an owed row of the money table is, decided by what names its source and never by the amount's sign. */
enum OwedKind: string
{
    /** The base price: the line of the tier stage; the table shows it first and unsigned. */
    case Base = 'base';

    /** A line whose component names nothing: the fallback word. */
    case Fee = 'fee';

    case ManualCharge = 'manual_charge';

    case ManualDiscount = 'manual_discount';

    /** A line of a component that names its lines. */
    case Component = 'component';

    public static function of(LineName $name): self
    {
        return match (true) {
            $name->stage === FeeStage::Tier => self::Base,
            $name->type === LineName::MANUAL_CHARGE => self::ManualCharge,
            $name->type === LineName::MANUAL_DISCOUNT => self::ManualDiscount,
            $name->type === LineName::COMPONENT => self::Component,
            default => self::Fee,
        };
    }
}
