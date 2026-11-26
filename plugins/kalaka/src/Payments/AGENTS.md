# Payments

Payments is the payments port: what core needs from a payment method that collects money online, and the system
use cases that record what it reports
([Core modules](../../../../docs/architecture/modules-and-extensions.md#core-modules)).

## Sections it implements

- money.md: [Payments](../../../../docs/architecture/money.md#payments) (the port: create a request, return the
  pay URL, reuse an open request, report paid and refunded), and the payment lines of
  [Ledger](../../../../docs/architecture/money.md#ledger) it writes.
- modules-and-extensions.md: the payment method of
  [The extension surface](../../../../docs/architecture/modules-and-extensions.md#the-extension-surface) (an
  outside point), [Contracts](../../../../docs/architecture/modules-and-extensions.md#contracts) (immutable values)
  and [Failure isolation](../../../../docs/architecture/modules-and-extensions.md#failure-isolation).
- foundation.md: [The use-case runner](../../../../docs/architecture/foundation.md#the-use-case-runner) (the
  system actor, the lock order) and [Errors](../../../../docs/architecture/foundation.md#errors) (typed
  refusals with their own codes).

## Invariants

- Recording is idempotent per provider item: one ledger line per item, keyed
  `online:<provider>:order:<request>:<item>` or `online:<provider>:refund:<refund>:<item>`; a repeated report writes
  nothing and queues no second mail, and a repeat with other content is a `SystemFailure`, never a second line.
- Recording is never gated: the registration's status, the event's state and the amount against the balance are not
  looked at; a surplus is an overpayment. Only a registration that no longer exists is skipped, and reported.
- Online payment is the participant payer's only; no other payer is paid online.
- A provider is an outside point: core calls it only outside a transaction (`Api\CardPayments`), and a provider
  reports back only through `Api\OnlinePayments`, which runs a system use case under the registrations' locks, taken
  in key order before any line is written.
- Starting a card payment writes nothing and re-checks what is payable now with the one rule of the transfer block
  (`Portal\Api\PayableNow`); its refusals are typed with their own codes, and their texts are the portal's.
  Every call to a provider (`latestOf()`, `accepts()`, `payUrl()`) is made in `Api\CardPayments`, outside the
  transaction, never in the use case; a start holds a named lock per registration from the read of the latest
  request until the pay URL is made, and is refused as `CardPaymentInFlight` while the latest request is in flight
  at the provider (processing, or paid and not yet recorded) or another start of the registration holds the lock.
  `Paid` means paid and not yet reported as recorded: a provider answers null or a newer request once its report
  is recorded, and core also checks the ledger for the request's lines (key prefix
  `online:<provider>:order:<request>:`) before it treats a paid request as in flight.
- A card payment asks for the amount the registrant enters (input `amount`; absent is the whole payable amount):
  at most the participant's payable amount (no overpaying) and at least the effective minimum, both from
  `Api\CardAmountLimits`, the one place of the limits (a page that shows them reads them there too); an amount
  outside them is a `CardAmountRefused`, a malformed one (no whole multiple of the payable unit) an `InvalidInput`. The ledger records
  what was paid, whatever the amount asked.
- The use cases take everything they act on in their schema-checked input (the registration of a start; the whole
  report of a recording), and name their registrations from it; the ref patterns of the schemas come from
  `Api\OnlineRefs`. A report names each item ref once.
- An item or request ref is `[a-z0-9_.-]`, so the idempotency key is never lowercased and two refs never share one.
- The registration as `12:47K3` (event ID, a colon, the reference code) is composed and parsed only here
  (`Api\RegistrationRef`).
- One provider is used per site, the first contributed. Every operation of the port takes one immutable
  `Api\PaymentProviderInput` (built with `forStart()` or `forLookup()`), and providers extend
  `Api\AbstractPaymentProvider` (modules-and-extensions.md, "Contracts"), so a new field or method breaks no provider.
