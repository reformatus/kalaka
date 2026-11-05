# Foundation

Foundation is the runner, audit, clock, Money, evaluation and module registry
([Core modules](../../../../docs/architecture/modules-and-extensions.md#core-modules)).

## Sections it implements

- foundation.md: [Layers](../../../../docs/architecture/foundation.md#layers),
  [Two sides, one machinery](../../../../docs/architecture/foundation.md#two-sides-one-machinery),
  [The use-case runner](../../../../docs/architecture/foundation.md#the-use-case-runner) with
  [Lock order](../../../../docs/architecture/foundation.md#lock-order),
  [Errors](../../../../docs/architecture/foundation.md#errors),
  [Audit](../../../../docs/architecture/foundation.md#audit),
  [Failures](../../../../docs/architecture/foundation.md#failures),
  [Evaluation](../../../../docs/architecture/foundation.md#evaluation),
  the Time and Money rows of [Cross-cutting rules](../../../../docs/architecture/foundation.md#cross-cutting-rules).
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
- Locks are taken in the lock order; `Context::lock()` refuses a lower level
  after a higher one.
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
- The current time comes from the `Clock` (`Context::now()` inside a use case),
  never from `time()`, `current_time()` or `new DateTimeImmutable('now')`.
- `Foundation\Api` depends on nothing else in Foundation (deptrac).
- Any load failure closes the site (`LoadState`); nothing in Foundation
  reopens it.
- Amounts are `Money`, never floats or raw strings in arithmetic, and every
  amount carries its `Currency`.
- Only `Foundation\Api\Money` uses `Kalaka\Vendor\Brick\Math` (deptrac);
  `Money`'s public methods never take or return a brick/math type.
- Every outstanding check (confirmation threshold, "nothing due", fully paid)
  uses `countsAsZero()`; payment requests use `roundedUpToPayableUnit()`.
