<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

/** What a value is, and so how a condition compares it. */
enum ValueType: string
{
    case Number = 'number';
    case Date = 'date';
    case Text = 'text';
    case Choice = 'choice';
}
