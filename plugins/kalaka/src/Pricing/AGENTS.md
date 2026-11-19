# Pricing

Pricing is the fixed price, the fee-component framework, confirmation,
retention and the materializer
([Core modules](../../../../docs/architecture/modules-and-extensions.md#core-modules)).

## Sections it implements

- money.md: the fixed price of [Core money scope](../../../../docs/architecture/money.md#core-money-scope),
  [Money settings apply](../../../../docs/architecture/money.md#money-settings-apply),
  [Materializer](../../../../docs/architecture/money.md#materializer),
  the split per payer that [Ledger](../../../../docs/architecture/money.md#ledger)'s invoices show,
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
- A component may owe per item (`FeeTargets::ofItems()`): each item is
  recorded and reversed on its own under the source key
  `<component ID>:<item>` (item `[a-z0-9_]{1,40}`, at most 64 characters),
  and an item the component no longer returns is reversed. A component ID
  matches `[a-z][a-z0-9_]*` (no colon, which would be another component's
  item).
- Lines of components not registered now are never touched, and
  `adjustment` lines never count as recorded.
- A fee component returns a value and writes nothing. Core composes the
  components in the fixed stage order tier → support → share → aid →
  extras; a component sees only the totals of earlier stages, and the
  computed values earlier stages put into its facts (`FeeTargets::$computed`,
  dotted keys the facts do not have; never stored). A throw, a malformed
  payer or another currency than the event's is `FeeComponentFailed`, which
  fails the use case as the rule `money/<component id>`.
- A component that cannot say what is owed and a person can act on why
  throws `Api\FeeRefusal` with a text for staff and, when its form can reach the case, one for the registrant (none:
  the registrant gets the generic failure);
  the evaluation refuses the use case with the actor side's text
  (`Api\FeeRefused`, a `RuleViolation` with its own code `kalaka_fee_refused`), still naming the rule
  `money/<component id>`, so the periodic run flags it.
- The components are core's fixed price, then those the modules contribute
  (`Contributions::feeComponent()`, IDs unique), core's Extras among them. The fixed
  price is the base only without a ladder: it is charged only when no
  component provides the event's base (`Api\ProvidesBase`, which a ladder
  with tiers does), whatever that component owes a registration.
- A module's settings reach a preview and an apply as
  `Api\ProposedComponentSettings`: the preview composes its `component()` in
  place of the registered one; the apply runs its `save()` use case before
  re-evaluating anything.
- Only a `registered` registration composes; every other status owes zero
  (retention is an empty step until S23).
- The money phase runs after the decisions phase, with the registration's
  row lock held.
- A money settings change goes preview → apply: the preview writes nothing;
  the apply saves the settings in one use case, then re-evaluates each
  `registered` and `waitlisted` registration in its own, through Foundation's
  `Evaluations` (Pricing never marks a registration touched itself); one
  failure never stops the others, a failed one is flagged "evaluation failed",
  and it converges on its next evaluation. The preview refuses what the apply
  refuses (a negative price).
- A fee component can also be a `Api\ConfigurableFeeComponent`: it writes its settings
  as plain data and reads them back (`settingsToArray()`, `settingsFromArray()`
  into `ProposedComponentSettings`), so `wp kalaka money-settings show|preview|apply`
  (`Cli/`) carries every component's settings as JSON by component ID without core
  knowing any of them; core itself reads and writes only the fixed price. The
  command is a thin entry point over `Api\MoneySettings`: it runs as the command
  line, or as WP-CLI's `--user`, and the apply asks first.
- Once confirmed, a registration never becomes unconfirmed because amounts
  change later, an apply included (owner rule, 2026-11-14; confirmation is
  S20's).
- No mail and no UI text come from Pricing.
- Only Pricing writes its tables.
