<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

/**
 * The wrapper element of a single-text-control field (name, email, phone, date, text), as a theme
 * adapter chooses it. A choice and a consent are always a `div`.
 */
enum FieldWrapper: string
{
    case Div = 'div';
    case P = 'p';
}
