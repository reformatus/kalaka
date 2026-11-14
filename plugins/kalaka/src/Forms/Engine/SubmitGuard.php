<?php

declare(strict_types=1);

namespace Kalaka\Forms\Engine;

/**
 * The check a form submit passes before it reaches the submit seam: the place spam protection (S14) fills.
 * It does nothing yet. It is core, not an extension point: a point is added only when one of our own
 * extensions needs it (modules-and-extensions.md, "Contracts"), and an interface only where there are
 * several implementations (foundation.md, "Layers").
 *
 * It runs outside any transaction, in the submit route, before the submit seam reads the form. It refuses by
 * throwing a `KalakaException`; it never writes to our tables.
 */
final class SubmitGuard
{
    /** Returns when the submit may go on; refuses it by throwing a `KalakaException`. */
    public function check(SubmitAttempt $attempt): void
    {
        unset($attempt);
    }
}
