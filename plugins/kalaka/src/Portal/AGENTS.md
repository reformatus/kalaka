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
  overpayment, never as a negative amount to pay. Only the last row is bold:
  „Még fizetendő”, or „Túlfizetés” when overpaid, whether or not anything is payable now.
- The money table is built from `ParticipantMoney` alone (`StatusMarkup`; the mail look is `MailMarkup`, reached
  through `Api\MailMoney`), with the owner's words in `MoneyWords`; the owed lines are the read's, signed, the base
  price included. The page's popovers are native (`popover`, `popovertarget`), with an ID of their own per table
  on a page. Whether a money table shows is one rule, `RegistrationStatusView::showsMoney()`, read by the page
  and by the mail's money block: none for a waitlisted registration.
- The transfer block renders nothing unless the registration is `registered`,
  its event is published, no request awaits an answer, the participant's balance is
  above zero after rounding up to the payable unit, and the event's bank
  details are set. The first four are one rule, `Api\PayableNow`, which starting a
  card payment (Payments) reads too, through `StatusRead::payableNow()`: from the
  participant's balance in `Ledger::balancesOf()`, never from the display summary.
- What the registrant can do is decided on the server and carried by the status read: `can.payCard` is `PayableNow`
  and a payment provider that accepts the event's currency, and no request in flight or paid and not yet
  recorded; `cardPayment` is the provider's latest request
  (`latestOf()`: state and since). A provider is an outside point: one that throws is reported and read as absent,
  so the page never breaks on it. The status block links to the card payment route only while `can.payCard`
  (a bare form, no look; the button is the owner's to approve).
- `POST kalaka-portal/v1/card-payment` (`Rest\CardPaymentRoute`) starts a card payment for the active registration
  through `Payments\Api\CardPayments::start()` and nothing else: JSON gets `{url}`, a plain form post a 303 to it; a refused plain form post gets a 303 back to the same-site
  return address (else the referrer, else home), clean of any query, with the refusal's code in the flash cookie
  `kalaka_card_refusal` (`CardRefusalCookie`: the code only, a known one or the generic; 120 seconds, `HttpOnly`,
  `SameSite=Lax`, `Secure` on https). On `template_redirect`, after canonical redirection, Portal reads it once
  and expires it; every status read of that request shows it as `cardRefusal`; a reload shows nothing. A JSON refusal is the error's
  code and generic text (anything not a Kaláka error is `kalaka_system_failure`), never a raw message.
  The bare button's class is `Forms\Api\ThemeStyle::buttonClass()`.
  It is open to the holder of the active registration (the signed cookie) and answers `no-store`.
- Payment due and overdue are computed on every read, never stored. Payment
  due is Events' date type (`Events\Api\Event::paymentDue()`), read through the
  registration's fact source (`RegistrationFacts::paymentDue()`), which holds
  `registered_at`, the time the registration last became `registered`, the
  last changed answer (`Requests::lastAnsweredAt()`, not a note alone; the
  later of the two counts) and whether a request awaits an answer (then there
  is no due date). Portal assembles none of these itself.
- The blocks have no look of their own: core preset classes and `portal.css`,
  which is structural only (the money table's emphasis is `em` and `strong` in
  its markup; a theme's row lines and colours are the theme adapter's); the
  texts read correctly without either. Every text is the frozen spec's or
  approved.
- A block and its shortcode render the same markup (`ParticipantBlocks`),
  each inside its own error boundary.
