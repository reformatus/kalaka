# Foundation

Runner, audit, clock, Money, evaluation trigger and module registry.

`Api/` holds, by kind: `Module/` (the `Module` interface and `AbstractModule`, `Modules`, `Schema`, `Contributions`), `UseCase/` (`UseCase`, `AdminUseCase`, `PortalUseCase`, `Context`, `Runner`, `LockLevel`, and the runner's internal `CoarsePermission` and `RunState`), `Database/` (`DatabaseError`: the database's last error as a `KalakaException`), `Actor/` (`Actor`, `Side` and the actor classes), `Error/` (the `KalakaException` family), `Money/` (`Money`, `Currency`), and at its root `Clock`, `LoadState` and `LoadFailure`.

Entry points: `plugins_loaded` boot (`Boot.php`), `kalaka_register_modules`.
