---
title: Money
---

# Money

## Core money scope

- **Core:** a fixed price per registration and full payment, with its
  **payment due** date type. Core ships no money field types.
- **Plugins:** deposits, payment rungs (the price ladder, with its tier
  quotas), late fees, financial aid, sponsor or church support, the
  congregation share and the wallet. Core keeps full support for extending it
  in these ways through typed extension points
  ([Modules](modules-and-extensions.md#the-extension-surface)).
- **Financial aid is two separate extensions:**
  1. adjudicated aid (the organizations call it a scholarship): the
     participant states what they can pay, organizers decide whether and how
     much to grant, and payment is blocked until decided. It reduces only the
     participant's own part. Every request with a positive amount waits for a
     decision; automatic evaluation may come later;
  2. sponsor or church support: offers on an organization directory
     ([Sponsor and church support](#sponsor-and-church-support)).
- Price-ladder tier quotas are separate from the event's waitlist. Each
  tier's quota is configured once, and texts render from it.
- **Quota positions are derived,** for tiers and support offers alike: a
  requester's position is their rank among the current `registered`
  requesters in registration order, and nothing is stored.
  - A withdrawal or removal frees the place; the next requester moves in at
    the next evaluation.
  - Waitlisted requesters hold no place and get no decision until admitted.
  - A late recipient may end up overpaid, and staff decide on a refund;
    restoring a withdrawn registration can push the last holder out, and staff
    adjust.
- **WooCommerce stays the payment engine** for online payment, through our
  own extension plugin ([Payments](#payments)). Without a payment plugin,
  online payment is unavailable, and organizer-recorded payments still work.

## Amounts

- **WooCommerce-compatible shapes:** every monetary record carries a currency
  code (ISO 4217); decimals and rounding follow WooCommerce conventions.
  Multi-currency is possible but not built.
- **Stored as `DECIMAL(26,8)`,** like WooCommerce's order tables.
- **Arithmetic uses brick/math, never floats** (it uses bcmath or GMP when
  present, pure PHP otherwise).
- SQLite stores `DECIMAL` inexactly; that is accepted. Tests that fail on
  SQLite for this reason are fixed in the tests, not by changing the type, and
  no SQLite number formats are normalized.

## Ledger

- **One ledger per registration,** so per participant and event. Budgets and
  accounting are separate per event by construction.
- **Every line has a payer.** The default is the participant; extensions
  register other payer kinds (the congregation share registers
  "congregation"). Each payer has its own balance, and the registration's
  total is the sum of all lines.
- **Sign convention:** a positive amount increases what the payer owes; a
  negative amount decreases it. A balance is the sum of lines; above zero is
  due, below zero is overpaid.
- **Lines are append-only.** Every change is a new, explained line with a
  source reference and an idempotency key.

| Kind | Sign | Written by |
|---|---|---|
| `charge`, per fee component (the fixed price; plugin components such as a late fee or a staff discount) | ± | the materializer |
| `payment` (online or another automatic method) | − | Payments, from payment-method events |
| `refund` | + | Payments, from payment-method events |
| `manual_payment` (bank transfer, cash, card terminal) | − | the "record payment" use case |
| `manual_refund` (money returned by transfer or cash) | + | the "record refund" use case |
| `transfer` (a payment moved to another registration of the same event) | ± | the "move payment" use case |
| `adjustment` (correction with a reason) | ± | an admin use case |
| feature lines (aid award, sponsor support, wallet, …) | ± | feature plugins, through an extension point |

## Recording payments by hand

- **One "record payment" use case** serves the bookkeeper and the
  registration desk. It takes the method, date, amount, an optional bank
  reference or payer note, the payer, and **one or more registrations, each
  with its share**. It writes one `manual_payment` line per registration, each
  in its own ledger, all sharing one payment reference, so one transfer for
  several people stays traceable.
- **"Record refund"** writes a `manual_refund` line for money returned by
  transfer or cash. WooCommerce refunds keep their own path
  ([Refunds](#refunds)).
- **A short transfer** (e.g. a foreign bank's deduction) is recorded as it
  arrived; the rest stays due until waived with an `adjustment` and a reason.
- **An unnamed transfer** is found through a view (name, code, amount) and
  recorded there. Core has no holding area for unassigned money.
- **Invoicing is by hand.** Kaláka offers views that show the bookkeeper what
  to copy into the invoicing service. Automatic invoicing, if ever, is our own
  integration, not WooCommerce's.

## Confirmation

- **Confirmation is defined only for `registered` registrations.** It is
  confirmed when payments reach the event's confirmation threshold, when
  nothing is due, or when staff set the manual flag.
- **The threshold** is the full amount in core; the deposit extension lowers
  it to the deposit through a typed point. Confirmation reads payment facts,
  never charge lines. A refund that brings payments below the threshold makes
  the registration unconfirmed again.
- **The manual flag** ("may come without paying") is the only stored input:
  set with a reason, audited, carrying its own set time, and kept until staff
  revoke it.
- **The result is a derived fact** stored by the evaluation
  ([Foundation](foundation.md#derived-facts)), so views filter on it; nobody
  sets it directly. It is not a status
  ([Registrations](registrations-and-identity.md#status-capacity-and-waitlist)).
- **Deposit payment request:** max(0, threshold − payments so far), capped at
  the remaining balance. Waitlisted registrations get none.
- **Late fee** (extension): charged when the payment deadline passes while the
  registration is unconfirmed. A manual flag set before the deadline prevents
  it; one set later does not touch the fee, and admins who want to drop it
  revert the charge with a line.

## Withdrawal, removal and substitution

- **Retention:** on withdrawal or removal (treated the same), the fee target
  becomes min(paid, retained amount), reading payment facts only, so nobody
  owes more after leaving. Core offers "retain nothing" and "retain everything
  paid"; the deposit extension adds "retain up to the deposit". A surplus
  shows as an overpayment; refunds are never automatic.
- **Move payment** (a substitute takes someone's place): one organizer use
  case, **within one event only**, writes a pair of `transfer` lines with a
  shared reference (+amount on A, −amount on B), at most what A's payer paid.
  Only payments move; B's charges and support come from B's own facts. Staff
  choose the target payer, and a remaining difference is an ordinary balance.
- Money moves between events only through the wallet's carry-over
  ([Deferred](deferred.md)).

## Congregation share

An extension: the registrant's congregation pays part or all of the **base
price**, never extras such as lunch or a donation.

- The share is the amount the registrant declares. A fee component writes a
  zero-sum pair, participant −X′ and congregation +X′, where
  X′ = max(0, min(declared share, base price − support)). Support thus reduces
  the participant's part first, and the congregation's only below zero.
- It reads facts only: the declared share, the support amount and the base
  price from the tariff snapshot.
- The congregation's payments carry the congregation payer, so its
  overpayment never settles the participant's extras.
- Confirmation needs both parts paid; a manual confirmation covers a late
  payer.

## Sponsor and church support

- **An organization directory,** site-wide: a tree of typed nodes whose root
  is the encompassing body (e.g. denomination → church district → deanery →
  congregation; a body without levels is a root on its own). Nodes have an
  external ID, aliases and an active flag; they are imported from CSV,
  deactivated and never deleted. No data is shipped.
- **A congregation field type** stores a node ID. The registrant searches for
  the congregation directly, which fills in the levels above it, or goes down
  from the top through filtered options, each level with a search. "Not in the
  list" is free text that staff map later.
- **A node's ancestors are derived facts,** so views filter on any level.
- **Support offers per event on any node,** the root included, apply to the
  node and everything below it: amount, quota (people and/or budget),
  conditions on facts, "must be requested", and "must be confirmed by the
  body" (in its view or through a form link).
- **Stacking:** one flag per offer, "withdrawn if a lower-level support
  applies", on by default (the most specific wins); off, it adds to the lower
  one. Offers are evaluated from the congregation upwards, and a higher offer
  takes a quota place only if it ends up applying.
- **Each applying offer is one participant-side feature line,** labelled with
  its granting node.
- **Bodies pay in lump sums after the event,** against a report that groups
  support lines by granting node. It is a view, not a payer, and is separate
  from the congregation share.

## Tariff snapshot and re-price

- **Each registration keeps the tariff snapshot** it registered under.
- **Personal facts still recompute automatically:** e.g. a price-ladder
  choice, aid, staff status (a fact from an additional form), a passed
  deadline, quota position.
- **Event configuration edits reach existing registrations only through an
  explicit re-price** action with a preview. It replaces the snapshot, and the
  materializer writes the difference as `charge` lines; `adjustment` lines
  stay for manual corrections with a reason. Saving event settings never
  touches existing registrations.

## Materializer

```mermaid
flowchart LR
  S["Registration snapshot<br/>tariff, choices"] --> R
  F["Registration facts<br/>status, answers, additional forms"] --> R
  X["Extension inputs<br/>aid award, …"] --> R
  PF["Payment facts<br/>read from payment lines only"] --> R
  R["Fee components<br/>target amount per component and payer"] --> MZ["Materializer<br/>target − recorded ≠ 0<br/>→ one new line"]
  MZ --> L[("Ledger<br/>append-only lines")]
  PM["Payment-method plugin<br/>e.g. WooCommerce"] -->|"paid / refunded"| L
  ST["Organizers<br/>cash, bank transfer"] --> L
  L -. "payment facts" .-> PF
```

- **It converges:** it writes only the difference between target and
  recorded, per component and payer, so it can run any number of times, with
  line idempotency keys.
- **Changes caused by an action** are materialized inside that action's
  transaction. **Changes caused by time passing** (e.g. a late fee) are
  applied by the periodic run, one transaction per registration, under a
  per-registration lock. Opening a registration in the portal or admin also
  re-evaluates it.
- **Rules never read charge lines,** only payment facts. That avoids a
  feedback loop.

## Payments

- **Payments is a port in core.** Core defines what it needs from a payment
  method:
  - create a payment request for N registration lines, each for one payer's
    balance;
  - return the pay URL;
  - reuse an open request;
  - report paid, refunded and failed.
- **Payment methods are pluggable,** so more automatic methods (another
  gateway, bank-statement import, …) can be added without changing core.
  Cash and bank transfers are recorded by organizers in core
  ([Recording payments by hand](#recording-payments-by-hand)); WooCommerce
  uses no offline gateway.
- **Online payment covers the participant's lines only,** including an
  account's "pay all outstanding".
- **The WooCommerce extension** implements the port:
  - only through WooCommerce's own API, so both of its order-storage modes
    work;
  - one hidden product line per order, with its name and amount set per
    order (coupons keep working);
  - WooCommerce payment and refund events write ledger lines **exactly
    once**, keyed by WooCommerce order and refund IDs;
  - order-line metadata identifies the registration as `12:47K3`
    ([Registrations](registrations-and-identity.md#registrations));
  - a billing country is a valid ISO code or left out.
- Wallet top-ups are their own payment requests and never mix with
  registration payments. The wallet is designed later
  ([Deferred](deferred.md)).

## Paying several registrations at once

An account holder pays everything outstanding, e.g. two children in two
camps, in one payment. Each event's money still lands in its own ledger.

```mermaid
sequenceDiagram
  actor H as Account holder
  participant P as Portal
  participant C as Core · Payments
  participant W as WooCommerce plugin
  participant L as Ledger
  H->>P: Pay all outstanding
  P->>C: payment request, one line per registration
  C->>W: create order
  W-->>H: hosted pay page
  H->>W: pays (card, Apple / Google Pay)
  W->>C: paid (order ID)
  C->>L: one payment line per registration
  Note over L: each event's budget stays separate
  C-->>H: payment mail, sent by the sender run
```

## Refunds

An admin-started WooCommerce refund:

1. writes a **refund-request record** inside the use case's transaction (its
   uniqueness guards against starting the same refund twice);
2. calls WooCommerce **after commit, in the same request,** so the admin sees
   the result right away;
3. on a **definite failure**, keeps the status and error on the record, with a
   retry button;
4. on a **timeout or unknown outcome**, leaves the record "in progress" for
   the admin to check in WooCommerce. It is **never retried automatically**,
   which rules out a double refund.

The refund line itself arrives through WooCommerce's refund event, exactly
once.
