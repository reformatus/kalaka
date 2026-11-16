# Portal

Portal is the active registration, the participant blocks admins place on any
page, and the account area
([Core modules](../../../../docs/architecture/modules-and-extensions.md#core-modules)).

## Sections it implements

- registrations-and-identity.md: the active registration and the participant
  blocks of [Access](../../../../docs/architecture/registrations-and-identity.md#access);
  the display of the phase sequence of [Status, capacity and waitlist](../../../../docs/architecture/registrations-and-identity.md#status-capacity-and-waitlist).
- money.md: the payment due of [Core money scope](../../../../docs/architecture/money.md#core-money-scope)
  (read, never stored), the display of [Confirmation](../../../../docs/architecture/money.md#confirmation)
  and of the per-payer balances of [Ledger](../../../../docs/architecture/money.md#ledger).
- engineering.md: the participant pages of [WordPress surfaces](../../../../docs/architecture/engineering.md#wordpress-surfaces).

## Invariants

- The active registration is never "logged in". It is set only by following
  a secret link and remembered only in the signed cookie that
  `Registrations\Api\SecretLinks` issues and verifies; Portal never reads or
  stores a secret. Being logged in without the cookie gives no active
  registration.
- A secret link is handled at `init`, before any output, and always answered
  with a 302 to the same URL without the token, never cached. A link that
  does not match clears the cookie and sets the one-shot bad-link flag.
- A cookie that does not verify (expired after 24 hours, revoked by a new
  secret, tampered) is cleared silently: no bad-link text.
- A request that carries the cookie or the bad-link flag, and a page that
  places a participant block, are never cached.
- Showing a registration writes nothing and evaluates nothing: `StatusRead`
  reads the module Apis and the clock only.
- The participant blocks show only the participant payer's side: totals,
  lines and the transfer amount. A balance below zero shows as an overpayment,
  never as a negative amount to pay. Only the amount still due is bold, and
  only while it is payable now (above zero, not held).
- The transfer block renders nothing unless the registration is `registered`,
  its event is published, payment is not held, the participant's balance is
  above zero after rounding up to the payable unit, and the event's bank
  details are set.
- Payment due and overdue are computed on every read, never stored. Payment
  due is Events' date type (`Events\Api\Event::paymentDue()`); Portal passes
  it `registered_at`, the time the registration last became `registered`.
- The blocks have no look of their own: core preset classes and `portal.css`,
  which is structural only; the texts read correctly without either. Every
  text is the frozen spec's or approved.
- A block and its shortcode render the same markup (`ParticipantBlocks`),
  each inside its own error boundary.
