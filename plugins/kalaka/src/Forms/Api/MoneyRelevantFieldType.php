<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

/**
 * An optional marker beside `FieldType`, a separate interface: the type's value affects money. Core ships no such type
 * (modules-and-extensions.md, "Contracts": a capability is a separate optional interface).
 */
interface MoneyRelevantFieldType
{
}
