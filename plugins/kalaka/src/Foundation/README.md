# Foundation

Runner, audit, clock, evaluation trigger and module registry.

`Api/` holds: the `Module` interface and `AbstractModule`, `Modules`, `Schema`, `Contributions`, the use-case interfaces (`UseCase`, `AdminUseCase`, `PortalUseCase`), `Context`, `Runner`, the actors (`Actor`, `Side` and the actor classes), `Clock`, and the `KalakaException` family.

Entry points: `plugins_loaded` boot (`Boot.php`), `kalaka_register_modules`.
