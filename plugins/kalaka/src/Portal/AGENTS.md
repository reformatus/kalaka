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
  money table is read for every status. The read also carries one table per
  other payer with lines, built by the same code, in Ledger's payer order,
  for every status but waitlisted; the blocks do not show them yet. A balance below zero shows as an
  overpayment, never as a negative amount to pay. Only the last row is bold:
  „Még fizetendő”, or „Túlfizetés” when overpaid, whether or not anything is payable now.
- The money table is built from `ParticipantMoney` alone (`StatusMarkup`; the mail look is `MailMarkup`, reached
  through `Api\MailMoney`), with the owner's words in `MoneyWords`; the owed lines are the read's, signed, the base
  price included. The page's movement details (type, method, day, the whole note) are the native `title` tooltip of the info
  mark and of a shortened note, never a popover. Whether a money table shows is one rule, `RegistrationStatusView::showsMoney()`, read by the page
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
  through `Payments\Api\CardPayments::start()` and nothing else. It needs a WordPress nonce, `kalaka_card_payment`
  (the server-rendered form's hidden `_wpnonce`, or the `X-WP-Nonce` header or `_wpnonce` field of a script), checked
  before anything else: a missing or stale one is `CardNonceInvalid` (`kalaka_card_nonce_invalid`, the generic text)
  through the same refusal cookie, so a cached page or a forged cross-site post starts nothing. JSON gets `{url}`, a
  plain form post a 303 to it; a refused plain form post gets a 303 back to the same-site
  return address (else the referrer, else home), clean of any query, with the refusal's code in the flash cookie
  `kalaka_card_refusal` (`CardRefusalCookie`: the code only, a known one or the generic; 120 seconds, `HttpOnly`,
  `SameSite=Lax`, `Secure` on https). On `template_redirect`, after canonical redirection, Portal reads it once
  and expires it; every status read of that request shows it as `cardRefusal`; a reload shows nothing. A JSON refusal is the error's
  code and generic text (anything not a Kaláka error is `kalaka_system_failure`), never a raw message.
  The bare button's class is `Forms\Api\ThemeStyle::buttonClass()`.
  It is open to the holder of the active registration (the signed cookie) and answers `no-store`.
  Its input is `{return_url?, amount?, popup?}`: `amount` goes to `start()` as it is (absent is the whole payable amount;
  its refusals are the use case's), `popup=1` says the page's script opened the popup window. The order's return URL is
  always `GET kalaka-portal/v1/card-payment/return?to=<status page>` (`popup=1` added with the flag), the status page being
  the same-site return address, `rawurlencode`d so its own query string stays one `to`; a refused post from a popup
  goes to that address with the flag, the flash cookie set. The
  return endpoint is open, `no-store` and behind the error boundary: without the flag a 303 to `to` (re-validated
  same-site, else home), with it the close page (`Rest/CardReturnPage`, HTML sent by `rest_pre_serve_request`: inside
  a classic theme, in the content frame the theme adapter chooses from core's closed list `Forms\Api\ContentFrame`
  (`neutral`, a plain `div`, by default; `page`, the classic page frame) and rendered by core, with the adapter's
  `page_body` classes on the page's `body` and its dropped body classes off; a block theme has its own frame; or bare when the filter
  `kalaka_card_return_bare` is true; every answer is noindex), which posts `{type: 'kalaka-card-return'}` to
  its same-origin opener and tries `window.close()`, and shows a line, a close button and a link to the status page.
- `RegistrationStatusView::cardAmounts` is non-null only while `can.payCard`: `full` is the payable now, `max` the same,
  `min` is `Payments\Api\CardAmountLimits::of($full)->min` (one rule for the page and the endpoint), and `advance` stays
  null until the deposit extension fills it with money.md's deposit payment request.
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
  approved, except the registrant's withdrawal (T765 to T775, T992 and T1303
  to T1306, marked `[UNAPPROVED]` in `StatusMarkup`), a prototype until the owner
  approves it.
- The leave block `kalaka/leave` (`[kalaka_leave]`) is page content only and renders nothing unless there is
  an active registration or a logged-in account, or while the site is closed (the transfer block's rule). It
  is one form with one button that posts the page's own address as `return_url` to
  `POST kalaka-portal/v1/leave`: one press, no confirmation step.
- The leave route, in this order: the same-site check first (a post from another site gets the plain 403
  page, never JSON); the `logged_in` cookie read and validated by the route itself, and its user set, before
  `wp_logout()` (a REST request without a nonce has no user by then); `wp_logout()` for an account; the
  active-registration cookie cleared; a 303 to a same-site `return_url`, else the home page, with no body.
  No nonce (the registrant may be a guest), no state, no audit.
- The registrant's withdrawal is presentation only: `StatusMarkup` shows the
  button on the server's `can.withdraw` and a confirmation, in a WordPress Modal opened by script or, without
  script, as a section on the page's own confirm address, whose post is the confirmed act; once the registration
  has closed the option is simply gone, with no hint; the page decides nothing and holds no rule.
  [UNAPPROVED N765 to N779, N1333 to N1349]
- Interactive confirmations open in a WordPress components Modal, and the page is not re-rendered in place
  (owner, 2026-11-28). The Modal's script (`Blocks/status/view.js`) holds no text and no rule: every string and
  the post's address ride on the start form as data (`data-kalaka-withdraw`), written by `StatusMarkup`. The start
  form stays a plain GET to the confirm address, which is the no-script way. [UNAPPROVED R370]
- A block and its shortcode render the same markup (`ParticipantBlocks`),
  each inside its own error boundary.
- The participant's own withdrawal (`POST kalaka-portal/v1/withdraw`, `Rest\WithdrawRoute`; `Api\Withdrawal`)
  acts only for the active registration and only with that registration's own `code`; anything else (no active
  registration, another code) is `Api\WithdrawStale` (`kalaka_withdraw_stale`), also in a preview; a status that
  cannot withdraw is `Api\WithdrawUnavailable` (`kalaka_withdraw_unavailable`; a preview answers `allowed: false`). It is open to whoever holds the active registration (the secret link and the
  signed cookie), and a real withdrawal carries its page's nonce (`Api\Withdrawal::nonceField()`, the field
  `kalaka_withdraw_nonce`), an HMAC of the action, the nonce tick and that registration's own code under
  `wp_salt('nonce')`, alive for two nonce ticks like WordPress's, so it holds for a guest too, whose logged-out
  WordPress nonce every guest shares; a missing or stale one is `Api\WithdrawBadNonce` (`kalaka_withdraw_bad_nonce`,
  known to the flash cookie). `LoadState::requireOpen()` comes first, every answer is
  `no-store`, and a plain form post is answered with a 303 to the same-site return address (else the referrer, else
  home), clean, with a refusal's code in the flash cookie `WithdrawRefusalCookie` that the next page request reads
  once (`WithdrawRefusalState`, read through `StatusRead`; it becomes one portal flash cookie with the card
  payment's when both exist). A plain form post is never a preview. The act, its rule and its audit are
  Registrations'; Portal adds no rule and shows no seat, no other payer's money and no reason. `can.withdraw`
  (`Api\RegistrationCan`) is decided in `StatusRead` from `StatusAct::Withdraw`'s allowed statuses, `LoadState` and
  the event's registration close (the participant may withdraw only until it closes; after that the use case refuses
  as `Registrations\Api\WithdrawClosed`, `kalaka_withdraw_closed`, also known to the flash cookie), never in the page;
  `can.withdrawUnavailable` is `registration_closed` when only the close makes `withdraw` false, else null. [UNAPPROVED N660, N662, N664-N666]
- `kalaka_push_subscriptions` (the parked PWA scope) is the module's only
  table: one row per registration and device, keyed by (event, code,
  endpoint hash); `key_p256dh` and `key_auth` are secret columns. Nothing
  writes it yet. A row is deleted on unsubscribe, on the registration's
  withdrawal, when the event ends and when the push service rejects its
  endpoint; there is no last-seen column. Portal's "never stores a secret"
  above is about a registration's secret, not these keys.
