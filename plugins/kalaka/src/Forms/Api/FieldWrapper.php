<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

/** The wrapper element of a single-control field, as a theme adapter chooses it. A choice is always a `div`. */
enum FieldWrapper: string
{
    case Div = 'div';
    case P = 'p';
}
