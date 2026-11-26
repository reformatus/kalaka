<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

/**
 * An optional marker beside `FieldType`, a separate interface
 * (modules-and-extensions.md, "Contracts"): the type's value is a choice whose data is a path, root first
 * (the congregation field's picked leaf and the nodes above it). A condition asks whether the path contains
 * one node (`contains`), or whether it is empty; `equals`, which would compare the whole path with one node,
 * is refused when the condition is checked. Core ships no such type.
 */
interface PathFieldType
{
}
