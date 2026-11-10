# Mail

Mail is mails, deliveries and the sender
([Core modules](../../../../docs/architecture/modules-and-extensions.md#core-modules)).
So far: the delivery queue, the sender, pausing, the mail counts, the mail
bodies, the renderer and the per-event action-mail catalogue.

## Sections it implements

- mail.md: [Words](../../../../docs/architecture/mail.md#words) (deliveries),
  the queue of [Five kinds of mail](../../../../docs/architecture/mail.md#five-kinds-of-mail),
  [Sending](../../../../docs/architecture/mail.md#sending),
  [Failures](../../../../docs/architecture/mail.md#failures),
  [Pausing](../../../../docs/architecture/mail.md#pausing),
  the action mails of [Configuration](../../../../docs/architecture/mail.md#configuration),
  [Content](../../../../docs/architecture/mail.md#content).
- foundation.md: the mail line of [Failures](../../../../docs/architecture/foundation.md#failures),
  the two mail warnings of [Site warnings](../../../../docs/architecture/foundation.md#site-warnings),
  the mail part of [Deferred work](../../../../docs/architecture/foundation.md#deferred-work).

## Invariants

- Deciding that a mail is due inserts a pending delivery through
  `Api\MailQueue::add()` inside the deciding use case; the key (event, mail,
  registration, cause) makes it once-only, and a repeat inserts nothing and is
  no error (`INSERT IGNORE`).
- Nothing reads `kalaka_deliveries` but the sender (`DeliveryQuery`) and the
  counts of `Api\MailStats`; no decision, condition or view depends on it.
- `wp_mail` is called only by `Sender`, which runs from the nudge hook
  `kalaka_send_mail`, from itself and as the periodic run's step, never in an
  interactive request, and only under the named lock `mail_sender`.
- The sender does not run without a bound `MailComposer`: deliveries stay
  pending. The module binds `Body\ActionMailComposer`.
- A mail body or layout is a `kalaka_mail` post of one event
  (`kalaka_event_id`), edited in the block editor by full access only; the
  per-event settings live in Mail's tables (`kalaka_action_mails`,
  `kalaka_mail_layouts`), never in post meta. A mail's version is the latest
  non-autosave revision ID; revisions of mail documents are never pruned.
- An action mail is decided only through `Api\ActionMails::due()` inside the
  acting use case, which queues it only when the event has it switched on and
  looks at nothing else (not the event's state).
- A mail is rendered at send time by `Body\MailRenderer`, through
  `Forms\Api\Values::render()` with the mail's context and no evaluator of
  its own; a layout wraps a body by having the body's blocks put in the place
  of its one `kalaka/mail-content` block, and the whole renders once.
- `MailComposer::recipient()` never throws on data (no registration, form or
  contact email is `null`); only `compose()` throws.
- The secret link reaches a mail only as the value `registration.link` from
  `Registrations\Api\RegistrationRead::linkUrl()`. It is never stored on a
  delivery, logged or audited; render failures name a problem code and a
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
  run it.
- Every stored instant goes through `Instant`; the current time comes from the
  runner's `Clock`. The one other duration is `Stopwatch`'s wall time, which
  caps a run at 20 seconds.
