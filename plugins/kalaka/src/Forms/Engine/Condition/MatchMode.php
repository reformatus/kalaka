<?php

declare(strict_types=1);

namespace Kalaka\Forms\Engine\Condition;

/** How a group combines its items. */
enum MatchMode: string
{
    case All = 'all';
    case Any = 'any';
}
