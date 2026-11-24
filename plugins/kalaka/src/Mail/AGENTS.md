# Mail

Mail is mails, deliveries and the sender
([Core modules](../../../../docs/architecture/modules-and-extensions.md#core-modules)).
So far: the delivery queue, the sender, pausing, the mail counts, the mail
bodies, the renderer, the per-event action-mail catalogue and the manual mail.

## Sections it implements

- mail.md: [Words](../../../../docs/architecture/mail.md#words) (deliveries),
  the queue of [Five kinds of mail](../../../../docs/architecture/mail.md#five-kinds-of-mail),
  [Sending](../../../../docs/architecture/mail.md#sending),
  [Failures](../../../../docs/architecture/mail.md#failures),
  [Pausing](../../../../docs/architecture/mail.md#pausing),
  the action mails of [Configuration](../../../../docs/architecture/mail.md#configuration),
  [Content](../../../../docs/architecture/mail.md#content), and the Manual row
  of [Five kinds of mail](../../../../docs/architecture/mail.md#five-kinds-of-mail).
- foundation.md: the mail line of [Failures](../../../../docs/architecture/foundation.md#failures),
  the two mail warnings of [Site warnings](../../../../docs/architecture/foundation.md#site-warnings),
  the mail part of [Deferred work](../../../../docs/architecture/foundation.md#deferred-work).

## Invariants

- Deciding that a mail is due inserts a pending delivery through
  `Api\MailQueue::add()` inside the deciding use case; the key (event, mail,
  registration, cause) makes it once-only, and a repeat inserts nothing and is
  no error (`INSERT IGNORE`).
- Nothing reads `kalaka_deliveries` but the sender (`DeliveryQuery`), the
  counts of `Api\MailStats` and the staff listing of `wp kalaka mail failed`
  (`DeliveryQuery::troubled()`, the registration code, never the address); no
  decision, condition or view depends on it.
- `wp_mail` is called only by `Sender`, which runs from the nudge hook
  `kalaka_send_mail`, from itself and as the periodic run's step, never in an
  interactive request, and only under the named lock `mail_sender`.
- The sender does not run without a bound `MailComposer`: deliveries stay
  pending. The module binds `Body\KindComposer`, which composes a manual
  delivery from its send batch (`Body\ManualMailComposer`) and every other as
  an action mail (`Body\ActionMailComposer`).
- A mail body is a `kalaka_mail` post of one event (`kalaka_event_id`),
  edited in the block editor by full access only; its blocks are content and
  the condition blocks, never a field block or a form-only block (refused
  at save, `field_in_document`, `form_block_in_document`). An event's layout is raw HTML
  with `{{content}}` exactly once and no other `{{…}}` (exactly `{{content}}`
  until set; any other save is refused), stored verbatim (full access is trusted
  like `unfiltered_html`) and never escaped. The per-event settings and the
  layout live in Mail's tables (`kalaka_action_mails`, `kalaka_mail_layouts`),
  never in post meta. A body serves one action mail only (mail.md, "Words":
  `UpdateActionMail` refuses another mail's body, and `body_id` is unique),
  and only a published body is assigned or switched on. A body a switched-on
  action mail uses is neither trashed nor deleted, never leaves `publish` and
  never changes its event (`Body\BodyInUse`, on every path; REST refuses with
  `kalaka_mail_in_use`).
  A mail's version is the latest
  non-autosave revision ID; revisions of mail documents are never pruned.
- An action mail is decided only through `Api\ActionMails::due()` inside the
  acting use case, which queues it only when the event has it switched on and
  looks at nothing else (not the event's state).
- A mail is rendered at send time by `Body\MailRenderer`, through
  `Forms\Api\Values::render()` with the mail's context and no evaluator of
  its own; the rendered body then replaces the layout's `{{content}}`, and
  nothing else in the layout is replaced.
- The set-password link (S15) has no mail of its own: it is the value
  `account.password_link` of a body, from
  `Registrations\Api\Accounts::passwordLink()`, made only when the body
  being rendered reads it, and only while `account.unverified` is `yes`: the
  registration's owner is unverified and the recipient is that account's own
  address. It is `no` for a verified owner and none for any other case,
  including an event whose account mode is `off` (no account step, no account
  facts); the three are never conflated. Each one replaces the account's last link.
- While an event's account mode is not `off`, its "registered" mail must
  read `account.password_link` and cannot be switched off
  (`Body\AccountLinkRule`): at the body's save (`Body\BodyCheck`) and in
  `UpdateActionMail`.
- `MailComposer::recipient()` returns `null` for a registration without a
  contact email (failed as `invalid_address`) and throws only
  `RecipientUnavailable` when the address cannot be determined (no
  registration: `no_registration`; slot form unpublished, or the version the
  registration was submitted with broken: `no_recipient_form`); the sender
  fails the delivery with that reason, with no attempt and no count toward
  pausing. The recipient and the display name are the marked fields of that
  submitted version, never the current one.
- The sender composes before the claim. `compose()` throws `BodyUnusable`
  when the body cannot render for its own reason: none assigned (`no_body`),
  gone (`missing`), unpublished (`unpublished`) or of another event
  (`other_event`), or, by `Values::check()` against `MailValues::types()` (and `paths()`), an
  undeclared key, an invalid condition, a misplaced branch; the delivery is failed with `body <problem>`, no attempt and no
  count toward pausing. Any other throw is an error, recorded after the claim.
- A body saved as published or scheduled is checked first (`Body\BodyCheck`):
  REST refuses it with 400 `kalaka_mail_body`; any other path vetoes it
  gracefully (a published body keeps its content, another stays unpublished),
  tells the saving user in an admin notice, writes a log line and, on
  WP-CLI, warns and fails the command.
- The catalogue has five action mails; admission from the waitlist sends
  "registered", and a body tells it apart with `registration.from_waitlist`.
  A stored row whose key is no longer an action (the former `admitted`) is
  skipped and logged on read; no schema change removes it.
- No Mail use case reads WordPress data in its transaction. `UpdateActionMail`
  gets the body's facts (`Catalogue\MailBody`: its event, whether it is
  published, whether it reads `account.password_link`) read before the
  run by its entry, `Api\ActionMailSettings::update()`;
  run without them it refuses a body assignment or a switch-on. It does not
  check the keys: a body is checked at its save, and one that goes stale
  later fails per delivery without pausing.
- The secret link reaches a mail only as the value `registration.link` or a
  page-link value `page_<ID>.link`, both from `Registrations\Api\SecretLinks::url()`
  (the second on the published page with that ID: following it makes the
  registration active there, which signing in alone does not). `page_<ID>.link`
  is declared only for a page the body places and that is published now, and
  made only for a body that places it; a page unpublished or deleted after the
  mail was queued is an undeclared key, `BodyUnusable`. The link is never stored
  on a delivery, logged or audited; render failures name a problem code and a
  key, never a value.
- Before each claim the sender reads the pause fresh (`Pause\MailStateQuery`,
  past every cache) and checks the address with `is_email()`; a missing or
  invalid address marks the delivery failed with no attempt.
- A delivery is claimed (`ClaimDelivery`) before `wp_mail` and recorded
  (`RecordDelivery`) after it, each in its own committed use case run as the
  system. On an error it goes back to pending with its error and the run stops;
  the next run tries it first; two errors in a row pause sending. Only a
  successful attempt resets the errors in a row.
- A stored error is the reported text as it is (`DeliveryError`): on the
  delivery, on the pause, in the audit diff and in the banner; the error log
  gets the same failure under the same correlation ID.
- The pause lives in `kalaka_mail_states` (one row), written only by
  `PauseMail`, `ResumeMail` and `RecordDelivery`; only a person resumes. The
  warnings read it, never the deliveries table.
- Every Mail use case is site-level: only the system, WP-CLI and full access
  run it, except `kalaka/send-manual-mail`, an event use case carried by the
  grant action `kalaka_manual_mail` for codes inside the grant's view.
- A manual mail is one send batch (`Manual\MailBatch`, `kalaka_mail_batches`)
  and its members (`kalaka_mail_batch_members`: a code and an outcome, queued
  or skipped because withdrawn or removed; never an address, a name or rendered
  content). The batch is the cause of each of its deliveries (`mail` =
  `manual`, kind `manual`, cause `mail_batch` and the batch ID), so a
  registration gets a batch's mail once. `SendManualMail` only inserts the
  batch, the members and the pending deliveries, in one transaction, and
  writes no registration and runs no evaluation; it reads no WordPress data in
  its transaction (the body's facts come from `Api\ManualMails::send()`). The
  optional `request_key` makes a repeat in the same event return the first
  batch and queue nothing; the same key for another body or other codes is
  refused. Any published body of the event may be sent, an action mail's
  included. A paused site or an unpublished event still queues: the deliveries
  wait. The batch's own record is read through `Manual\MailBatchQuery`, never
  through the deliveries.
- A manual delivery is composed from its batch at send time
  (`Body\ManualMailComposer`, through `MailRenderer::renderBody()`); a batch
  that is gone is `BodyUnusable` (`no_batch <id>`), failed with no attempt and
  never counted toward pausing.
- `wp kalaka mail send|batches` (`Cli/ManualMailCommand`, `Cli/ManualMailBatchesCommand`) is a thin entry
  point beside `MailCommand`: `send` checks the actor's grants on the codes
  (`AdminAccess::check()`) before it prints anything about them, shows what it
  would do, asks first (`--yes` skips; `--dry-run` only shows) and changes only
  through `SendManualMail`; `batches` reads the batch's own record for full
  access only.
- `wp kalaka action-mail list|show|on|off|body|layout <event_id> …` (`Cli/`) is a
  thin entry point: it changes only through `UpdateActionMail` and
  `SetEventMailLayout`, as the command line or as WP-CLI's `--user`, and prints
  the use case's own refusal; `list` and `show` read for full access only. `body`
  is the one write outside a use case: the `kalaka_mail` post, saved published
  under the editor's check (`BodyCheck::refusal()`, the one function the editor's
  filters call too, checked ahead so its text is printed) and only after the
  actor passed the use case's access check; it assigns a first body through
  `UpdateActionMail` and never switches a mail on. The mail commands share
  `Cli\CliInput` (event ID, format, the read guard) and `CliActor::current()`.
- `wp kalaka mail status|pause|resume|clear-queue|clear-event|failed` (`Cli/MailCommand`) is a
  thin entry point: it changes only through `PauseMail`, `ResumeMail`, `ClearMailQueue` and
  `ClearEventMail`, as the command line or as WP-CLI's `--user`; `status` and `failed` read for
  full access only. Only a person resumes: `ResumeMail` refuses any actor that is no person
  (`ResumeNeedsPerson`), and `resume` adds the hint to give `--user`. The clears ask first
  (`--yes` skips), after the access check; `clear-event` runs the use case's own `ClearEventMail::refuse()`
  ahead of the question. Whether an event's mail is held is read once, in `Api\MailHold`, by the sender,
  the clear and the manual send's warning.
- Every stored instant goes through `Instant`; the current time comes from the
  runner's `Clock`. The one other duration is `Stopwatch`'s wall time, which
  caps a run at 20 seconds.
