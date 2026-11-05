# Foundation

Runner, audit, clock, Money, evaluation trigger and module registry.

`Api/` holds, by kind: `Module/` (the `Module` interface and `AbstractModule`, `Modules`, `Schema`, `Contributions`: the seeders so far), `UseCase/` (`UseCase`, `AdminUseCase`, `PortalUseCase`, `Context`, `Runner`, `LockLevel`, and the runner's internal `CoarsePermission`, `RunState` and `RunTrace`), `Audit/` (`Subject` and `Correlation`, and the runner's internal `AuditEntry`, `EntryHeader`, `AuditWriter` and `ErrorRecord`), `Database/` (`DatabaseError`: the database's last error as a `KalakaException`), `Repository/` (the bases `Repository`, `EntityRepository` and `AppendOnlyRepository`), `Query/` (the base `Query`), `Seed/` (`Seeder`, the contribution point of sample data, and `Seeding`, what a seeder runs use cases with), `Actor/` (`Actor`, `Side` and the actor classes), `Error/` (the `KalakaException` family), `Money/` (`Money`, `Currency`), and at its root `Clock`, `LoadState` and `LoadFailure`.

At its root, beside `Boot` and `SchemaUpgrader`, `ObjectCache` holds the rows the repositories read; the runner clears it.

`Decision/` holds the decision rule shapes (`DecisionRule`, `DecisionInput`, `Decision`, `Transition`), the registry `DecisionRules`, and the `DecisionRecord` entity with its `DecisionRepository` and `DecisionQuery`. `Evaluation/` holds `Evaluation`, the procedure the runner calls after `execute`, with `RuleFailed`, the use cases `EvaluateRegistration` and `FlagEvaluationFailure`, the `EvaluationFailure` entity with its `EvaluationFailureRepository`, and `PeriodicEvaluation`.

Tables, created by the schema in `Module.php`: `kalaka_audit_entries` and `kalaka_audit_subjects`, which only the runner writes; `kalaka_decisions`, which only the evaluation writes; `kalaka_evaluation_failures`, which only `FlagEvaluationFailure` writes and every successful evaluation of the registration clears.

`Seed/` holds the seed: `Seed` (the users, then the contributed seeders), `SeedUsers` (one WordPress user per default role, with WordPress functions), `SchemaDrop` (the development drop: drops the tables each module declares in its `Schema`, then recreates every schema through the upgrader), `SeedGuard` (refuses unless the environment type is `local` or `development`) and `SeedFailure`. `Cli/SeedCommand` is the WP-CLI command, registered by `Module::boot()` when WP-CLI is loaded; Boot hands `Module` the modules that passed the version check and the contributions through `Module::collect()`.

Entry points: `plugins_loaded` boot (`Boot.php`), `kalaka_register_modules`, `wp kalaka seed [--drop]`.
