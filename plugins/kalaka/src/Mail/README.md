# Mail

Mails, deliveries and the sender. So far: the delivery queue, the sender, pausing and the mail counts.

At the module root: `Delivery` (one row of the send log) with `DeliveryStatus`, `DeliveryRepository` (the only writer of `kalaka_deliveries`; `queue()` is an `INSERT IGNORE`) and `DeliveryQuery` (the sender's candidates, the hour's claims and the counts); the system use cases `ClaimDelivery` (`kalaka/claim-delivery`), `RecordDelivery` (`kalaka/record-delivery`, which also pauses after two errors in a row) and `FailDelivery` (`kalaka/fail-delivery`, no valid address), and the admin use cases `ClearMailQueue` (`kalaka/clear-mail-queue`) and `ClearEventMail` (`kalaka/clear-event-mail`, one unpublished event's held deliveries); `Sender` (the one sender, a `PeriodicStep`), `Throttle` (each run's share of the hourly budget), `Stopwatch` (the 20-second cap), `DeliveryError` (the masked, stored error), `MailComposer` and `MailMessage` (the seam to the mail bodies; no composer is bound yet, so the sender idles) and `SendReport`.

`Pause/` holds the state row: `MailState`, `MailStateRepository`, `MailStateQuery` (the fresh read), `PauseCause`, the use cases `PauseMail` (`kalaka/pause-mail`) and `ResumeMail` (`kalaka/resume-mail`), and `PauseWarning` (the two site warnings, `mail_paused_after_errors` and `mail_paused`) with `RequestState`, the one read per request they share.

`Api/` holds `MailQueue` (`add()` and the nudge), `MailKind`, and `MailStats` (`site()`, `ofEvents()`) with `SiteMailStats`, `EventMailStats` and `PauseInfo`.

Tables, created by the schema in `Module.php`: `kalaka_deliveries` and `kalaka_mail_states`.

Entry points: the WP-Cron hook `kalaka_send_mail` (the nudge; the plugin's deactivation clears it) and the periodic run's step `mail_sender`. The use cases have no REST route or screen yet.
