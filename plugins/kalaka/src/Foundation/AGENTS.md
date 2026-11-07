# Foundation

Foundation is the runner, audit, clock, Money, evaluation and module registry
([Core modules](../../../../docs/architecture/modules-and-extensions.md#core-modules)).

## Sections it implements

- foundation.md: [Layers](../../../../docs/architecture/foundation.md#layers),
  [Two sides, one machinery](../../../../docs/architecture/foundation.md#two-sides-one-machinery),
  [The use-case runner](../../../../docs/architecture/foundation.md#the-use-case-runner) with
  [Lock order](../../../../docs/architecture/foundation.md#lock-order),
  [Errors](../../../../docs/architecture/foundation.md#errors),
  [Entities, repositories and queries](../../../../docs/architecture/foundation.md#entities-repositories-and-queries),
  [Audit](../../../../docs/architecture/foundation.md#audit),
  [Failures](../../../../docs/architecture/foundation.md#failures),
  [Deferred work](../../../../docs/architecture/foundation.md#deferred-work),
  [Evaluation](../../../../docs/architecture/foundation.md#evaluation),
  [Facts, decisions and computed values](../../../../docs/architecture/foundation.md#facts-decisions-and-computed-values),
  the Time and Money rows of [Cross-cutting rules](../../../../docs/architecture/foundation.md#cross-cutting-rules).
- mail.md: the periodic run's part of [Sending](../../../../docs/architecture/mail.md#sending) (no sender run in an interactive request).
- engineering.md: [Static checks](../../../../docs/architecture/engineering.md#static-checks), [Fixtures](../../../../docs/architecture/engineering.md#fixtures).
- money.md: [Amounts](../../../../docs/architecture/money.md#amounts).
- modules-and-extensions.md: [The Module interface](../../../../docs/architecture/modules-and-extensions.md#the-module-interface),
  [Bundled libraries](../../../../docs/architecture/modules-and-extensions.md#bundled-libraries),
  [Schema ownership](../../../../docs/architecture/modules-and-extensions.md#schema-ownership),
  [Version check](../../../../docs/architecture/modules-and-extensions.md#version-check),
  [Failure isolation](../../../../docs/architecture/modules-and-extensions.md#failure-isolation).

## Invariants

- Use cases are the only entry point for writes, and the runner is the only
  code that begins, commits or rolls back a transaction.
- One transaction per use case; nested use cases join it. Any exception rolls
  back the whole use case with an explicit rollback; code never continues
  after catching a database error.
- Retryable errors are retried from scratch at the outermost run only; a
  nested run never commits, retries or rolls back on its own, and the first
  error in a nested run ends the whole use case.
- A database error inside a run is recorded as the run's failure, as a nested
  run's error is. After it, or after a caught nested error, the context
  refuses writes, locks and nested runs, and the recorded error decides the
  outcome.
- A reconnect inside a run fails the attempt as retryable.
- Locks are taken in the lock order; the guard refuses a lower level after a
  higher one, and a lower key within the event, quota or registration level,
  for every repository read `FOR UPDATE` and write.
- The admin-side coarse permission check is Admin's (`Admin\Api\AdminAccess`),
  called by the runner before the transaction; Foundation holds no interface
  or default for it and decides only the side match and the portal side itself.
- Inside the transaction only our own tables are written: no WordPress
  function that fires hooks or fills WordPress caches. WordPress-side steps
  run after commit and are safe to repeat.
- Use cases never read the current user; the delivery layer resolves the
  actor and passes it in.
- Errors are `KalakaException`s carrying their Hungarian default text; no
  result type, no code-to-text mapping.
- Only the runner writes `kalaka_audit_entries` and `kalaka_audit_subjects`:
  one entry per audited use case, in its transaction before the commit, or,
  when it failed, after the rollback in a transaction of its own; PHP's error
  log only when that write fails.
- A failed entry holds no changes, no input values and no reason; the context
  a thrower adds to a `KalakaException` names rules and record keys, never a
  submitted field value, a person's data or a secret; a secret never reaches
  an entry (masked in the changes) or the error log.
- The current time comes from the `Clock` (`Context::now()` inside a use case),
  never from `time()`, `current_time()` or `new DateTimeImmutable('now')`.
- `Api` is the contract: Foundation's own classes sit at the module root and
  `Foundation\Api` may use them (deptrac lets each module's `Api` use its own
  module and never another module's root); a class in an `Api` marked
  `@internal` is not contract, and no new one is added.
- Any load failure closes the site (`LoadState`); nothing in Foundation
  reopens it.
- Amounts are `Money`, never floats or raw strings in arithmetic, and every
  amount carries its `Currency`.
- Only `Foundation\Api\Money` uses `Kalaka\Vendor\Brick\Math` (deptrac);
  `Money`'s public methods never take or return a brick/math type.
- Every outstanding check (confirmation threshold, "nothing due", fully paid)
  uses `countsAsZero()`; payment requests use `roundedUpToPayableUnit()`.
- `$wpdb` runs statements only in `Foundation\Database\Statements`; the
  repository and query bases, the runner, the audit writer and the
  development drop go through it. PHPCS exempts that one file, and a test
  refuses any file that silences the direct-query rule.
- The seed writes our tables only through use cases run as the `CliActor`
  without a user, and WordPress data only with WordPress functions outside
  any use case; the development drop drops only the tables a module declares
  in its `Schema`; both refuse unless the environment type is `local` or
  `development`.
- Repositories write only through `upsert()`, `insert()` and `delete()` with
  the running use case's `Context`; the audit diff and the touched
  registrations come from the base, never from the caller, except that
  `EvaluateRegistration` marks the registration it is asked to evaluate.
  Append-only repositories never update or delete.
- `upsert()` returns the object to keep: the given object keeps its old
  version, and upserting it again conflicts.
- The object cache holds rows and is cleared after every rollback and when an
  outermost attempt begins, so two loaded objects of one row never share
  changes. A use case that runs a nested use case which writes a record it
  holds loads that record again before writing it.
- A query class runs one statement per record type per list (no N+1), and
  every per-event query has a per-event cost test.
- Only the evaluation writes `kalaka_decisions`; its records are append-only
  and nobody sets a decision directly. The current state of a kind is its
  latest record.
- A decision rule returns a transition or nothing and never writes; core
  writes the record, and only when the state changes. A core rule reads its
  own kind and the core kinds decided before it; an extension rule reads its
  own kind and every core kind, never another extension's.
- The runner evaluates each touched registration once, at the outermost use
  case, after `execute` and before the audit entry; a rule failure rolls back
  the whole use case.
- Only `FlagEvaluationFailure`, run by `PeriodicEvaluation` in a transaction of
  its own, inserts or replaces a row of `kalaka_evaluation_failures`; every
  successful evaluation of the registration, interactive or periodic, deletes
  its row.
- The periodic run runs only while `LoadState` is open and only under its lock;
  it sweeps only published events, evaluates each registration in its own
  transaction through `PeriodicEvaluation`, and writes its last-run time only
  after a completed run. Its WP-Cron handler lets no exception escape: it logs
  and returns.
- WP-Cron timestamps come from the `Clock`, never `time()`; the upgrader lock's
  own stamp is WordPress's and is not ours to set.
- The periodic run's health check (the admin notice and the Site Health test)
  only reads `kalaka_periodic_run_last` and compares it with the `Clock`; it writes nothing
  and never schedules, starts or unlocks a run.
