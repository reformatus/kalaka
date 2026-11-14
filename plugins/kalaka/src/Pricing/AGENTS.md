# Pricing

Pricing is the fixed price, the fee-component framework, confirmation,
retention and the materializer
([Core modules](../../../../docs/architecture/modules-and-extensions.md#core-modules)).

## Sections it implements

- money.md: the fixed price of [Core money scope](../../../../docs/architecture/money.md#core-money-scope),
  [Money settings apply](../../../../docs/architecture/money.md#money-settings-apply),
  [Materializer](../../../../docs/architecture/money.md#materializer),
  the invoiceable declaration of [Ledger](../../../../docs/architecture/money.md#ledger),
  the composition order that [Congregation share](../../../../docs/architecture/money.md#congregation-share)
  relies on. Confirmation and retention come later (S20, S23).
- foundation.md: the money phase of [Evaluation](../../../../docs/architecture/foundation.md#evaluation),
  [Bulk changes](../../../../docs/architecture/foundation.md#bulk-changes) for money settings.
- modules-and-extensions.md: the fee component of
  [The extension surface](../../../../docs/architecture/modules-and-extensions.md#the-extension-surface)
  and [Contracts](../../../../docs/architecture/modules-and-extensions.md#contracts).

## Invariants

- There is no tariff snapshot: money is always computed from the event's
  current settings and the registration's facts.
- Only the materializer writes `charge` lines, only through
  `Ledger\Api\Ledger`, and only the difference between what is owed and what
  is recorded per component and payer; it converges, so running it again
  writes nothing.
- Rules never read charge lines. Only `Api\Materializer` calls
  `Ledger::chargeTotalsOf()` (a unit test checks it); a fee component gets its
  `FeeComponentInput` (facts, earlier-stage totals, the event's currency) and
  reads its own current settings, never lines.
- Lines of components not registered now are never touched, and
  `adjustment` lines never count as recorded.
- A fee component returns a value and writes nothing. Core composes the
  components in the fixed stage order tier → support → share → aid →
  extras; a component sees only the totals of earlier stages. A throw, a
  malformed payer or another currency than the event's is
  `FeeComponentFailed`, which fails the use case as the rule
  `money/<component id>`.
- Only a `registered` registration composes; every other status owes zero
  (retention is an empty step until S23).
- The money phase runs after the decisions phase, with the registration's
  row lock held.
- A money settings change goes preview → apply: the preview writes nothing;
  the apply saves the settings in one use case, then re-evaluates each
  `registered` and `waitlisted` registration in its own; one failure never
  stops the others, and a failed one converges on its next evaluation.
- Once confirmed, a registration never becomes unconfirmed because amounts
  change later, an apply included (owner rule, 2026-11-14; confirmation is
  S20's).
- No mail and no UI text come from Pricing in S19.
- Only Pricing writes its tables.
