<?php

declare(strict_types=1);

namespace Kalaka\Ledger\Api;

/**
 * Where a payer kind comes from: a module that has a payer other than the participant contributes one
 * (`Contributions::payerKindSource()`), and `PayerKinds` asks it per event (modules-and-extensions.md, "The
 * extension surface"; money.md, "Ledger"). A kind is declared: it has an ID and a Hungarian label, and the source
 * says per event whether it applies there, from a plain read of the module's own setting. That read runs inside
 * the record use cases and in the admin detail read (`PayerKinds`), fires no hooks and writes nothing; a throw
 * rolls the use case back, and in the detail read is contained (the kind is treated as not applying and logged).
 * The source runs no other code. [UNAPPROVED N149, N366]
 */
interface PayerKindSource
{
    /** The kind: an ID of the form `Payer::isValid()` (never the participant) and a label. */
    public function kind(): PayerKind;

    /** Whether the kind applies to the event: whether the payer is listed even with no lines, and so may be recorded against. */
    public function appliesTo(PayerKindInput $input): bool;
}
