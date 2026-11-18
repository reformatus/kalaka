<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

/**
 * An optional marker beside `FieldType`, a separate interface (modules-and-extensions.md, "Contracts"): the type's
 * field is never prefilled from a link or the quiz's handoff (S74), only from its host's stored values. A consent
 * is one: it must be the person's own act, never ticked by a link (Master default 2026-11-18). [UNAPPROVED N13]
 */
interface RefusesLinkPrefill
{
}
