<?php

declare(strict_types=1);

namespace Kalaka\Ledger\Api;

/**
 * Where a payer kind comes from: a module that has a payer other than the participant contributes one
 * (`Contributions::payerKindSource()`), and `PayerKinds` asks it per event (modules-and-extensions.md, "The
 * extension surface"; money.md, "Ledger"). A kind is only declared: it has an ID and a Hungarian label, and the
 * source says per event whether it applies there, from a plain read of the module's own setting; it runs no
 * other code.
 */
interface PayerKindSource
{
    /** The kind: an ID of the form `Payer::isValid()` (never the participant) and a label. */
    public function kind(): PayerKind;

    /** Whether the kind applies to the event: who may pay, and whether the payer is listed with no lines. */
    public function appliesTo(PayerKindInput $input): bool;
}
