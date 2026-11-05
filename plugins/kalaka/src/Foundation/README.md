# Foundation

Runner, audit, clock, Money, evaluation trigger and module registry.

`Api/` holds, by kind: `Module/` (the `Module` interface and `AbstractModule`, `Modules`, `Schema`, `Contributions`), `UseCase/` (`UseCase`, `AdminUseCase`, `PortalUseCase`, `Context`, `Runner`, `LockLevel`, and the runner's internal `CoarsePermission`, `RunState` and `RunTrace`), `Audit/` (`Subject` and `Correlation`, and the runner's internal `AuditEntry`, `EntryHeader`, `AuditWriter` and `ErrorRecord`), `Database/` (`DatabaseError`: the database's last error as a `KalakaException`), `Repository/` (the bases `Repository`, `EntityRepository` and `AppendOnlyRepository`), `Query/` (the base `Query`), `Actor/` (`Actor`, `Side` and the actor classes), `Error/` (the `KalakaException` family), `Money/` (`Money`, `Currency`), and at its root `Clock`, `LoadState` and `LoadFailure`.

At its root, beside `Boot` and `SchemaUpgrader`, `ObjectCache` holds the rows the repositories read; the runner clears it.

Tables, created by the schema in `Module.php`: `kalaka_audit_entries` and `kalaka_audit_subjects`; only the runner writes them.

Entry points: `plugins_loaded` boot (`Boot.php`), `kalaka_register_modules`.
