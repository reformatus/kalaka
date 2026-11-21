<?php

declare(strict_types=1);

namespace Kalaka\Ledger\Api;

/** money.md, "Recording payments by hand": the type of a manual fee entry gives the sign of what it composes. */
enum ManualFeeType: string
{
    /** „Díj”: composes plus the amount. */
    case Charge = 'charge';

    /** „Kedvezmény”: composes minus the amount. */
    case Discount = 'discount';
}
