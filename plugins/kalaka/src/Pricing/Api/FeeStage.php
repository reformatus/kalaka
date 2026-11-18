<?php

declare(strict_types=1);

namespace Kalaka\Pricing\Api;

/**
 * The fixed sequence of named stages core composes the fee in (money.md, "Materializer"): a fee component
 * declares its stage; there are no order numbers. The cases are in composition order.
 */
enum FeeStage: string
{
    case Tier = 'tier';
    case Support = 'support';
    case Share = 'share';
    case Aid = 'aid';
    case Extras = 'extras';
}
