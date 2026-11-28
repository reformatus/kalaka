<?php

declare(strict_types=1);

namespace Kalaka\Portal\Api;

/** The three kinds of money movement the participant's table lists. */
enum MovementType: string
{
    case Payment = 'payment';
    case Refund = 'refund';
    case Correction = 'correction';
}
