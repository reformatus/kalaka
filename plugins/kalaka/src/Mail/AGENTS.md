# Mail

Mail is mails, deliveries and the sender
([Core modules](../../../../docs/architecture/modules-and-extensions.md#core-modules)).
So far: the delivery queue, the sender, pausing and the mail counts. The mail
bodies, the composer and the mail settings come later.

## Sections it implements

- mail.md: [Words](../../../../docs/architecture/mail.md#words) (deliveries),
  the queue of [Five kinds of mail](../../../../docs/architecture/mail.md#five-kinds-of-mail),
  [Sending](../../../../docs/architecture/mail.md#sending),
  [Failures](../../../../docs/architecture/mail.md#failures),
  [Pausing](../../../../docs/architecture/mail.md#pausing).
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
  pending.
- Before each claim the sender reads the pause fresh (`Pause\MailStateQuery`,
  past every cache) and checks the address with `is_email()`; a missing or
  invalid address marks the delivery failed with no attempt.
- A delivery is claimed (`ClaimDelivery`) before `wp_mail` and recorded
  (`RecordDelivery`) after it, each in its own committed use case run as the
  system. On an error it goes back to pending with its error and the run stops;
  the next run tries it first; two errors in a row pause sending. Only a
  successful attempt resets the errors in a row.
- A stored error is masked (`DeliveryError`): no e-mail-shaped text on a
  delivery, on the pause or in an audit diff; the full error goes only to the
  error log, under the same correlation ID.
- The pause lives in `kalaka_mail_states` (one row), written only by
  `PauseMail`, `ResumeMail` and `RecordDelivery`; only a person resumes. The
  warnings read it, never the deliveries table.
- Every Mail use case is site-level: only the system, WP-CLI and full access
  run it.
- Every stored instant goes through `Instant`; the current time comes from the
  runner's `Clock`. The one other duration is `Stopwatch`'s wall time, which
  caps a run at 20 seconds.
