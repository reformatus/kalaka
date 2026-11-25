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
  owed lines, money movements with their notes, and the transfer amount; the
  money table is read for every status. A balance below zero shows as an
  overpayment, never as a negative amount to pay. Only the amount still due is bold, and
  only while it is payable now (above zero, not awaiting an answer).
- The transfer block renders nothing unless the registration is `registered`,
  its event is published, no request awaits an answer, the participant's balance is
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
- The participant's own withdrawal (`POST kalaka-portal/v1/withdraw`, `Rest\WithdrawRoute`; `Api\Withdrawal`)
  acts only for the active registration and only with that registration's own `code`; anything else (no active
  registration, another code) is `Api\WithdrawStale` (`kalaka_withdraw_stale`), also in a preview; a status that
  cannot withdraw is `Api\WithdrawUnavailable` (`kalaka_withdraw_unavailable`; a preview answers `allowed: false`). It is open to whoever holds the active registration (the secret link and the
  signed cookie), with no nonce, as the card route is; `LoadState::requireOpen()` comes first, every answer is
  `no-store`, and a plain form post is answered with a 303 to the same-site return address (else the referrer, else
  home), clean, with a refusal's code in the flash cookie `WithdrawRefusalCookie` that the next page request reads
  once (`WithdrawRefusalState`, read through `StatusRead`; it becomes one portal flash cookie with the card
  payment's when both exist). A plain form post is never a preview. The act, its rule and its audit are
  Registrations'; Portal adds no rule and shows no seat, no other payer's money and no reason. `can.withdraw`
  (`Api\RegistrationCan`) is decided in `StatusRead` from `StatusAct::Withdraw`'s allowed statuses and
  `LoadState`, never in the page. [UNAPPROVED N660-N666]
