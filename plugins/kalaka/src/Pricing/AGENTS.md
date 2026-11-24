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
  relies on, [Confirmation](../../../../docs/architecture/money.md#confirmation).
  Retention comes later (S23).
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
  is recorded per component, item and payer; it converges, so running it again
  writes nothing.
- Rules never read charge lines. Only `Api\Materializer` calls
  `Ledger::chargeTotalsOf()` (a unit test checks it); a fee component gets its
  `FeeComponentInput` (facts, earlier-stage totals, the event's currency) and
  reads its own current settings and the seat facts (`Registrations\Api\Seats`),
  never lines.
- Lines of components not registered now are never touched. A line of a
  registered component that the composition no longer names (a withdrawal)
  owes zero, so the materializer reverses it. A component that owes per item
  writes one line per item (source key `<component>:<item>`, at most 64
  characters).
- A fee component can also be a `Api\ConfigurableFeeComponent`: it writes its settings as plain data and reads
  them back (`settingsToArray()`, `settingsFromArray()` into `ProposedComponentSettings`), so
  `wp kalaka money-settings show|preview|apply` (`Cli/`) carries every component's settings as JSON by component
  ID without Pricing knowing any of them; Pricing itself reads and writes only the fixed price. The command is a
  thin entry point over `Api\MoneySettings`: it runs as the command line, or as WP-CLI's `--user`, and the apply
  asks first. Every verb, `show` and `preview` too, is checked as the fixed price's save (`SetFixedPrice`, full
  access only) before it reads.
- A component ID matches `[a-z][a-z0-9_]*` (no colon, which would be another component's item).
- The `manual` component composes only the Ledger's manual fee facts, one
  item per entry; nobody writes its `charge` lines but the materializer.
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
- The money phase runs first in the evaluation, before the decision rules,
  with the registration's row lock held.
- A money settings change goes preview → apply: the preview writes nothing;
  the apply saves the settings in one use case, then re-evaluates each
  `registered` and `waitlisted` registration in its own, through Foundation's
  `Evaluations` (Pricing never marks a registration touched itself); one
  failure never stops the others, a failed one is flagged "evaluation failed",
  and it converges on its next evaluation. The preview refuses what the apply
  refuses (a negative price).
- Once confirmed, a registered registration never becomes unconfirmed because
  amounts change later, an apply included (owner rule, 2026-11-14), nor by a
  refund (the owner's ruling 2026-11-19): only staff's unconfirm
  (`kalaka/unconfirm`, newer than the current `confirmed` record) unconfirms
  it; it is allowed on any confirmed registration. Confirmation is checked
  after the materializer in every pass; after staff's unconfirm it returns
  `unconfirmed` and, if it still qualifies, `confirmed` in the same answer,
  effective no earlier than the unconfirm.
  `Api\ManualConfirmations::reconfirmsOnUnconfirm()` is the one dry run of
  that decision, for the warning.
- There is one fee composition, `Api\FeeComposition`: the materializer records
  its difference, and confirmation reads each payer's target from it
  read-only; it reads no ledger line and writes nothing.
  Confirmation reads payment facts (`Ledger::paymentFactsOf()`) and the
  manual flag, never charge lines.
- Confirmation (`Api\ConfirmationRule`, kind `confirmation`) is a core rule
  that reads every payer's target: every payer with something owed must reach its own
  threshold (core: the full target; `Api\ConfirmationThreshold` may lower it,
  held between zero and the target). It decides only for a `registered`
  registration and writes no first record: its declared base state
  (`defaultState()`) makes no record read `unconfirmed` for a `registered`
  registration and no state for any other. Leaving `registered` closes a
  confirmed sequence with an `unconfirmed` record, so a restore writes a new
  record.
- Whether a registration is confirmed is read only through `Api\Confirmations` (`isConfirmed()`, or `confirmedOf()`
  for a batch of one event); nobody else names the decision's kind or state.
- The manual flag is a fact in `kalaka_manual_confirmations`, set only by
  `kalaka/set-manual-confirmation` and revoked only by `kalaka/unconfirm`.
  Staff's unconfirm is a fact in `kalaka_unconfirmations` (the latest one per
  registration), written only by `kalaka/unconfirm`. Both use cases take the
  per-event action `kalaka_confirm_manually` and a required reason. Setting a
  set flag writes nothing; unconfirming a registration that is not confirmed
  writes nothing; unconfirming a confirmed one is a new act every time. A
  revoke keeps the flag's row with its revoke time. The flag is set on a
  `registered` registration only (confirmation applies only to `registered`);
  any other status is `InvalidInput`.
- No mail comes from Pricing; its UI texts are the decision's labels and the
  abilities' labels.
- Only Pricing writes its tables.
