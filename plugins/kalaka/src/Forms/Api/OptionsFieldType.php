<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

/**
 * An optional marker beside `FieldType`, a separate interface: the type's block holds its options as a list of
 * `{value, label}` in its `options` attribute, as a choice does (modules-and-extensions.md, "Contracts": a
 * capability is a separate optional interface). On save `FieldKeys` derives a missing, invalid or repeated value
 * from its label, and the definition check refuses a block without one valid option or with a repeated value
 * (`invalid_options`), as for a choice.
 */
interface OptionsFieldType
{
}
