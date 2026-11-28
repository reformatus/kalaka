# Events

Events, states, timeline points, capacity and the "full" latch. Date types belong to Events. Later: the participant phase tracker.

Table, created by the schema in `Module.php`: `kalaka_events` (the core points `registration_opens_at`, `registration_closes_at`, `starts_at` and `ends_at` are UTC `datetime(6)` columns, the custom points are JSON in `points` with `at` in UTC, `currency` is the event's currency code; `CreateEvent` stores `HUF`); only `EventRepository` writes it.

At the root: `EventRecord` (the stored event), `EventRepository`, `EventQuery` (the published events, for the periodic run's sweep; the events not archived, for the admin menu; and all events, for the event list), and the admin use cases `CreateEvent`, `UpdateEvent`, `PublishEvent`, `UnpublishEvent`, `ArchiveEvent` and `ReopenEvent` (`kalaka/create-event`, `kalaka/update-event`, `kalaka/publish-event`, `kalaka/unpublish-event`, `kalaka/archive-event`, `kalaka/reopen-event`; all need full access).

`Api/` holds `Events` (`find()`, `published()`, `notArchived()` for the admin menu, by start, `all()`, and `update()`, which reads the event's published registration forms before `kalaka/update-event` runs and refreshes the broken-form list after it), `Event` (a read of an event, with its `currency`, its payment settings (bank-transfer beneficiary and account, transfer-reference prefix, payment-due N; null until set), `paymentDue()` (the deadline, counted from when the registration last became registered), `state()` as seen and `registrationWindowContains()`), `EventPoint`, `EventState`, `Phase` (a registration's phase on the status page), `EventCapacity` (`lockForRegistration()`, the first lock of a submit, and `markFull()`, the only way to set the latch) and `EventTimes` (`save()`: runs `UpdateEvent` as the current user to set an event's start and end, for the admin times page; adds no permission check of its own).

`UpdateEvent` also writes the payment and account settings (`account_mode`, `payment_due_days`, `transfer_beneficiary`, `transfer_account`, `transfer_reference_prefix`) as optional keys: one that is absent stays as it is, `null` or an empty text clears it. A change of the account mode to `mandatory` or `optional` is refused while a published registration form of the event lacks the account block, or while the event's "registered" mail is off or its template lacks the set-password link (`Api\AccountMailNotReady`).

`Cli/` holds `EventSeeder`, which contributes five sample events to `wp kalaka seed`, written through the use cases above, and `EventCommand` with its `EventDocument`: `wp kalaka event create|update|show|list|publish|unpublish|archive`. `update --file=<json>` takes what `show --format=json` prints (local times in the site zone), so the whole setup of an event is one command.

Entry points: the use cases above, the seeder and `wp kalaka event`; no REST route or screen yet.
