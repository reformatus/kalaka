# Events

Events, states, timeline points, capacity and the "full" latch. Date types belong to Events. Later: the participant phase tracker.

Table, created by the schema in `Module.php`: `kalaka_events` (the core points are `date` columns, the custom points are JSON in `points`); only `EventRepository` writes it.

At the root: `EventRecord` (the stored event), `EventRepository`, `EventQuery` (the published events, for the periodic run's sweep), and the admin use cases `CreateEvent`, `UpdateEvent`, `PublishEvent`, `UnpublishEvent`, `ArchiveEvent` and `ReopenEvent` (`kalaka/create-event`, `kalaka/update-event`, `kalaka/publish-event`, `kalaka/unpublish-event`, `kalaka/archive-event`, `kalaka/reopen-event`; all need full access).

`Api/` holds `Events` (`find()`, `published()`), `Event` (a read of an event, with `state()` as seen and `registrationWindowContains()`), `EventPoint`, `EventState`, and `EventCapacity` (`lockForRegistration()`, the first lock of a submit, and `markFull()`, the only way to set the latch).

`Cli/EventSeeder` contributes five sample events to `wp kalaka seed`, written through the use cases above.

Entry points: the use cases above and the seeder; no REST route or screen yet.
