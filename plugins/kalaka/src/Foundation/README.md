# Foundation

Runner, audit, clock, evaluation trigger and module registry.

`Api/` holds, by kind: `Module/` (the `Module` interface and `AbstractModule`, `Modules`, `Schema`, `Contributions`), `UseCase/` (`UseCase`, `AdminUseCase`, `PortalUseCase`, `Context`, `Runner`), `Actor/` (`Actor`, `Side` and the actor classes), `Error/` (the `KalakaException` family), and at its root `Clock`, `LoadState` and `LoadFailure`.

Entry points: `plugins_loaded` boot (`Boot.php`), `kalaka_register_modules`.
