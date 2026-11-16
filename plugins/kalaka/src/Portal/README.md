# Portal

The active registration (set by a secret link or an account's pick), the participant blocks admins place on any page, and the account area.

`Api/` holds `ActiveRegistration` (`current()`: the key the verified cookie names; `followsBadLink()`: the one-shot flag of a link that did not match), `StatusRead` (`of()` and `forActive()`: one registration's `RegistrationStatusView`, composed from the module Apis and the clock; it writes and evaluates nothing) and the view's parts: `Phase`, `Availability`, `ConfirmationView`, `PaymentView`, `PayerAmounts`, `PaymentLine`, `TransferDetails`.

At the module root: `LinkHandler` (at `init`: follows a secret link with a 302 that drops the token and sets the signed session cookie `kalaka_active`, or the bad-link flag `kalaka_badlink`; verifies the cookie and clears a bad one; never cached when either cookie is sent; clears the cookie on logout), `Response` and `HttpResponse` (what the handler sends), `ActiveRegistrationState` (the request's resolved state), `ParticipantBlocks` (the renders the blocks and their shortcodes share, never cached, with `portal.css`), `StatusMarkup` (the markup and texts of the two blocks), `MoneyText` (an amount as `48 000 Ft`), and `LineLabels` (the label of an itemised payment line: none yet, so the table shows its totals). `portal.css` is the blocks' structural stylesheet, served from `src/`.

Entry points: the blocks `Blocks/status/` (`kalaka/status`, "Jelentkezésem állapota", shortcode `[kalaka_status]`) and `Blocks/transfer/` (`kalaka/transfer`, "Átutalási adatok", shortcode `[kalaka_transfer]`), each a server render with an editor placeholder; the `init` handler of the secret link.
