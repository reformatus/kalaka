# Mail

Mails, deliveries and the sender. So far: the delivery queue, the sender, pausing, the mail counts, the mail bodies, the renderer and the action-mail catalogue.

At the module root: `Delivery` (one row of the send log) with `DeliveryStatus`, `DeliveryRepository` (the only writer of `kalaka_deliveries`; `queue()` is an `INSERT IGNORE`) and `DeliveryQuery` (the sender's candidates, the hour's claims and the counts); the system use cases `ClaimDelivery` (`kalaka/claim-delivery`), `RecordDelivery` (`kalaka/record-delivery`, which also pauses after two errors in a row) and `FailDelivery` (`kalaka/fail-delivery`, no valid address), and the admin use cases `ClearMailQueue` (`kalaka/clear-mail-queue`) and `ClearEventMail` (`kalaka/clear-event-mail`, one unpublished event's held deliveries); `Sender` (the one sender, a `PeriodicStep`), `Throttle` (each run's share of the hourly budget), `Stopwatch` (the 20-second cap), `DeliveryError` (the stored error, as reported), `MailComposer` and `MailMessage` (the seam to the mail bodies) and `SendReport`.

`Pause/` holds the state row: `MailState`, `MailStateRepository`, `MailStateQuery` (the fresh read), `PauseCause`, the use cases `PauseMail` (`kalaka/pause-mail`) and `ResumeMail` (`kalaka/resume-mail`), and `PauseWarning` (the two site warnings, `mail_paused_after_errors` and `mail_paused`) with `RequestState`, the one read per request they share.

`Catalogue/` holds the per-event settings: `ActionMailEntry` and `ActionMailRepository` (the only writer of `kalaka_action_mails`), `MailLayout` and `MailLayoutRepository` (the only writer of `kalaka_mail_layouts`), and the use cases `UpdateActionMail` (`kalaka/update-action-mail`) and `SetMailLayout` (`kalaka/set-mail-layout`).

`Body/` holds the mail documents and their rendering: `MailPosts` (reads the `kalaka_mail` posts; `MailDocument`), `MailRestController` (REST for full access only), `MailBlockGate` (Kaláka's blocks in the mail editor), `LayoutSlot` (the `kalaka/mail-content` block and the splice of a body into a layout), `MailValues` (a mail's value context and its recipient), `MailRenderer` with `RenderedMail`, and `ActionMailComposer`, the composer the module binds into the sender. `Blocks/mail-content/` holds the slot block.

`Api/` holds `MailQueue` (`add()` and the nudge), `MailKind`, `ActionMail` (the catalogue), `ActionMails` (`due()`: deciding an action mail), `ActionMailSettings` (`ofEvent()`) with `EventMailSettings` and `ActionMailSetting`, and `MailStats` (`site()`, `ofEvents()`) with `SiteMailStats`, `EventMailStats` and `PauseInfo`.

Tables, created by the schema in `Module.php`: `kalaka_deliveries`, `kalaka_mail_states`, `kalaka_action_mails` and `kalaka_mail_layouts`. Post type: `kalaka_mail`, with the meta `kalaka_event_id`.

Entry points: the WP-Cron hook `kalaka_send_mail` (the nudge; the plugin's deactivation clears it) and the periodic run's step `mail_sender`. The use cases have no REST route or screen yet.
