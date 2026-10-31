---
title: Foundation
---

# Foundation

How every write happens, how failures are kept, and the rules that cut across
all modules.

## Layers

Every plugin we ship has the same layers. Dependencies point downward only.

```mermaid
flowchart TB
  D["<b>Delivery</b><br/>REST · abilities · blocks · admin app · portal · WP-CLI · cron<br/><i>resolves the actor, no business rules</i>"]
  A["<b>Application</b><br/>one use case per action, run by the runner<br/>transaction · permission check · audit"]
  M["<b>Domain</b><br/>entities · Money · rules · materializer · conditions"]
  I["<b>Infrastructure</b><br/>repositories and query classes over our tables · payment methods · wp_mail"]
  D --> A
  A --> M
  A --> I
```

1. **Domain code may use WordPress** where that makes sense. There is no
   "pure domain" rule: this is a WordPress plugin and need not be portable.
2. **An interface exists only where there really are several
   implementations** (payment methods, the clock). There is no general
   "infrastructure is swappable" layer.
3. **Use cases are the only entry point for writes.** REST controllers,
   abilities, WP-CLI commands, the periodic run and form submissions all call
   a use case. Nothing writes a table directly.
4. **Module and plugin boundaries are checked in CI**
   ([Engineering](engineering.md#static-checks)), not left to discipline.

## Two sides, one machinery

- **Admin side:** the actor is a WordPress user, WP-CLI or the system. A
  user reaches registration data through the views granted to them
  ([Views and grants](#views-and-grants)).
- **Portal side:** the actor is access to a registration. A secret link
  grants that one registration; an account grants every registration it
  owns, plus account-level actions (the combined list, paying all at once,
  presets). See [Registrations](registrations-and-identity.md#access).

Both sides use the same use-case runner (transaction, audit, post-commit
evaluation, errors) but have **separate action catalogues, authorization and
REST namespaces** (`kalaka-admin/v1`, `kalaka-portal/v1`). Use cases never
read the current user themselves: the delivery layer resolves the actor once
and passes it in.

## Views and grants

Organizers, module leaders and outside partners (e.g. a church body confirming
its own people) are all admin-side users. **The view defines which data can be
reached; the grant defines who holds the view.**

- **A view** belongs to one event: a row filter plus a column set, each column
  read or read-write. It is the only place where permissions on data are
  defined.
  - The master grid is a view with every column and no filter. A read-only
    variant is simply another view.
  - Admins define subset views; extensions define special-case views and
    contribute columns and filterable facts.
  - Filters use only stored facts and field values, so they stay per-event
    indexed queries under the `EXPLAIN` test.
  - A filter may take **one parameter bound at grant time** (e.g. "program
    roster, program = ?"), so twelve module leaders need one view.
  - View definitions are copied to the next event, optionally with their
    grants.
- **A grant** is (WordPress user, event, view, optional bound value). There
  are no grants across all events. Grants and changes to a view's columns or
  filter are audited.
- **Full access** is one separate site-wide capability: every view, every
  column read-write, every action, every event including future ones. It is
  for the people running the system.
- **Actions that are not a column** (withdraw, remove, admit, record payment,
  refund, regenerate link, notes, audit history) are per-event capabilities,
  usable only on registrations inside a view the user holds.
- Core ships a **minimal WordPress role** that only opens the Kaláka admin
  app, for outside grantees.
- **Enforced in one place:** on the admin side, registration data is read
  only through the view query (grids, detail page, export, search) or through
  full access, checked in CI like module boundaries. A use case changes a
  field only if it is a read-write column of a view the user holds, for a
  registration inside that view's filter.

## The use-case runner

```mermaid
flowchart LR
  R["Resolve actor"] --> S["Check input<br/>against schema"] --> P["Coarse<br/>permission"] --> B["Begin"]
  B --> X["Execute<br/>fine permission checks,<br/>changes"] --> AU["Audit entry"] --> C["Commit"] --> E["Post-commit<br/>evaluation"]
  X -. "any exception" .-> RB["Rollback<br/>+ clear object cache"] --> FA["Failed audit entry<br/>(own transaction)"]
```

- **One transaction per use case.** The audit entry is written in the same
  transaction as the changes, so neither exists without the other.
- **Any database error rolls back the whole use case;** code never continues
  after catching one. (This also matches how SQLite aborts a transaction on a
  failed statement.) A rollback also clears the per-request object cache.
- **Retries:** only errors marked retryable (a reference-code collision, a
  deadlock, a version conflict hit by a system run) are retried, about three
  times, each attempt from scratch.
- **Nested use cases** join the outer transaction and write their own audit
  entry, linked to the parent. Effects are released after the outermost
  commit.
- **Inside a transaction only our own tables are written:** no WordPress
  functions that fire hooks or fill WordPress caches.
- **WordPress-owned data is changed before or after our transaction, never
  inside it,** ordered so that a failure leaves a harmless state:
  - Creating a WordPress account runs *before* the transaction. If the
    registration then fails, the account simply exists, as after a plain
    sign-up.
  - Steps *after* commit run in the same request and must be safe to repeat.
    A failure is kept on the record it concerns (see [Failures](#failures)).
  - The admin-started WooCommerce refund follows this pattern
    ([Money](money.md#refunds)).
- **The shapes** (in `Kalaka\Foundation\Api`):

  ```php
  interface UseCase {
      public static function name(): string;   // 'kalaka/withdraw-registration'
      public function inputSchema(): array;    // JSON Schema, WordPress REST/abilities dialect
      public function execute(array $input, Context $context): mixed;
  }
  interface AdminUseCase extends UseCase  { public function capability(): string; }
  interface PortalUseCase extends UseCase { public function registrations(array $input): array; }

  Runner::run(UseCase $useCase, array $input, Actor $actor): mixed
  ```

  - Input is an array checked with WordPress's
    `rest_validate_value_from_schema`, so one schema serves REST, abilities
    and the runner.
  - The coarse permission check is the capability on the admin side, and on
    the portal side access to the registrations the input names.
  - `Context` is what a running use case gets: `actor()`, `now()`, `run()`
    for a nested use case, and `afterCommit()` for WordPress-side steps.
  - **Actors:** admin side `UserActor`, `CliActor` (optional `--user`),
    `SystemActor`; portal side `SecretLinkActor` (the registration key, never
    the secret) and `AccountActor`.
- **No domain events in the initial shape.** Everything our own plugins do in
  response to a change must be consistent with it, so it happens inside the
  use case. Public after-save actions for third parties come later
  ([Modules and extensions](modules-and-extensions.md#after-save-actions)).

## Errors

- Use cases throw exceptions; the throw triggers the rollback.
- An exception carries a `WP_Error` (code, Hungarian message, HTTP status), so
  REST, abilities and WP-CLI show it correctly.
- Each exception class has its own default Hungarian text, and the place that
  throws can add Hungarian context. There is no result type and no separate
  code-to-text mapping.
- **The classes:** an abstract `KalakaException` (with `toWpError()`,
  `status()`, `isRetryable()`, `isAudited()`; the constructor takes optional
  Hungarian context) and, to start with:

  | Class | Status | Default text |
  |---|---|---|
  | `InvalidInput` (not audited) | 400 | Hibás vagy hiányzó adat. |
  | `PermissionDenied` | 403 | Ehhez nincs jogosultságod. |
  | `NotFound` | 404 | A keresett elem nem található. |
  | `VersionConflict` | 409 | Valaki időközben módosította ezeket az adatokat. Töltsd újra az oldalt, és próbáld újra. |
  | `RuleViolation` | 422 | A művelet nem hajtható végre. |
  | `SystemFailure` | 500 | Váratlan hiba történt. Próbáld újra később. |
  | `RetryableFailure` (a `SystemFailure`, retried) | 500 | as `SystemFailure` |

- **The UI addresses everyone with tegezés,** in the admin and the portal.

## Entities, repositories and queries

- **Entities** are plain objects that carry their own key. A reference to
  another record holds that record's key; a helper method loads it through a
  per-request object cache when needed. The loaded object lives only in the
  caller's variable: it is never stored on the entity or serialized.
- **Repositories** offer:
  - `find(key)`, which returns null when missing (the caller decides whether
    that is an error; there is no get/find split);
  - `upsert()` for entities;
  - `insert()` for append-only records (ledger lines, submissions).
- **Concurrent edits:** mutable records (registrations, events) carry a
  `version` column, and `upsert()` writes only if the version has not changed.
  An edit screen sends back the version it showed; if the record changed in
  the meantime, the user gets a conflict error (audited). A system run that
  hits a conflict retries from scratch. One mechanism covers both stale edit
  screens and races inside a request.
- **Change tracking for the audit:** the repository keeps a copy of each
  record as loaded and, on save, compares the record with it to build the
  audit diff. Entities stay plain, and a forgotten setter cannot drop a
  change from the audit.
- **Query classes** serve lists and the evaluation runs; they are built on
  indexes, not on repositories. **No N+1:** a list loads each related record
  type in one query, enforced by the query classes.
- **Per-event cost:** every table holding per-event data has indexes that
  start with the event ID, and every per-event list, sort and search query
  filters by event first. A database integration test seeds many events and
  checks each per-event query's plan (`EXPLAIN`); a query that examines rows
  outside its own event fails the build.

## Audit

- **One row per use case** in `kalaka_audit_entries`:
  - time (UTC);
  - correlation ID (per request or WP-CLI command);
  - parent ID (nested use cases);
  - side and actor;
  - action name;
  - IP address and user agent;
  - optional reason;
  - outcome (succeeded or failed) and, when failed, the error;
  - the changes as JSON: changed fields only, before and after, secrets
    masked.
- **`kalaka_audit_subjects`** lists every record a use case touched, so a
  record's history is an indexed query. A subject is identified by **record
  type, event ID and key**: e.g. (registration, 12, `47K3`) or (preset, empty,
  381). The event column is empty for records that do not belong to an event;
  it keeps "everything touched in event 12" a per-event indexed query.
- **Failed use cases are audited too.** After the rollback, the runner writes
  the entry in its own transaction (the main one is gone) with the outcome
  "failed", the action, actor, error and any known record keys, but **no
  changes and no input values**: input may hold personal data that a privacy
  erase could not later find. If even that write fails, PHP's error log is
  the fallback.
- **What is audited:** permission refusals, edit conflicts, rule violations
  and system errors. Plain validation failures (a missing or malformed answer,
  shown to the person at once) are not.
- **Privacy:** IP address and user agent are declared through WordPress's
  privacy tools; a privacy erase also scrubs audit diffs. Our own audit trail
  and notes replace WordPress revisions and comments for our data.

## Failures

**Failures are kept on the record they concern; there is no log table.**

- A mail delivery keeps its status, attempt count and latest error.
- A refund request keeps its status and error.
- A registration the periodic run cannot evaluate gets an **"evaluation
  failed" flag** with the error, shown to admins as a broken record and
  cleared on the next success.
- A failing extension contribution is kept on its extension
  ([Modules and extensions](modules-and-extensions.md#failure-isolation)).
- Interactive failures are shown to the person at once. PHP's error log is
  the last fallback.

## Deferred work

**There is no job queue** (no Action Scheduler, no general queue). Each kind
of deferred work gets exactly the guarantee it needs:

- **Money converges.** The materializer writes only the difference between
  target and recorded, so it can run any number of times
  ([Money](money.md#materializer)). Changes caused by an action are
  materialized inside that action's transaction; changes caused by time
  passing (e.g. a late fee from an extension) are applied by the periodic
  run, one transaction per registration, under a per-registration lock.
- **Mail is sent at most once.** Deciding a mail is due inserts a delivery
  with a unique key; only the sender run sends ([Mail](mail.md)).
- **Derived facts converge** the same way ([Derived facts](#derived-facts)).
- **One shared trigger** runs the evaluation (registered evaluation
  participants, such as the materializer and mail decisions):
  - right after commit, for the touched registrations (after the response is
    sent, where the host allows);
  - **every 5 minutes** (the periodic run, matching today's Google Sheets
    system), for time-caused work: late fees, scheduled mails, reminders,
    retries;
  - when a registration is opened in the portal or admin;
  - manually, by an admin or WP-CLI, at any time; this is safe because every
    effect is claim-guarded.
- **Keeping the 5-minute run cheap:** it sweeps only published, non-archived
  events, and time rules query only what became due since the last run.
- **No wake-ups per due time.** WP-Cron fires only when `wp-cron.php` runs,
  so with a real cron every 5 minutes, or visitor-driven WP-Cron, per-time
  wake-ups would gain nothing over the 5-minute run; they would need a "next
  due" method on every date-bearing extension and a recompute on every date
  change. Precision is set by what drives WP-Cron.
- **A Site Health check** recommends a real system cron. WordPress core has
  only WP-Cron.

## Derived facts

**A derived fact is computed by the evaluation and stored on the
registration, so views can filter and sort on it.** It is a general mechanism
that core and extensions use, not a one-off.

- Examples: confirmation ([Money](money.md#confirmation)), a congregation's
  ancestors in the organization directory, an assignment derived from another
  set ([Modules](modules-and-extensions.md#assignment-sets)).
- **Nobody sets a derived fact directly.** Its stored inputs (e.g. the manual
  confirmation flag) are ordinary audited facts.
- The evaluation recomputes it whenever it evaluates the registration and
  writes it only when it changed, so it converges like money.

## Bulk changes

**A bulk change is never blocked by one broken record:**

- one transaction per item;
- a result report linking to each failed record, with options to retry or
  skip it;
- a preview that surfaces problems first, where one exists (e.g. re-price).

Because registrations keep their tariff snapshot, saving event settings never
touches existing registrations ([Money](money.md#tariff-snapshot-and-re-price)).

## Cross-cutting rules

| Concern | Rule |
|---|---|
| **Storage** | Our own database tables for the domain (events, registrations, values, submissions, presets, ledger, deliveries, audit, notes, …). WordPress posts only for documents edited in the block editor: forms, field definitions, mail bodies |
| **Time** | Stored in UTC; displayed and computed in the site's WordPress time zone, never a fixed zone. Our own `Clock` interface (`now(): DateTimeImmutable`, the shape of PSR-20, which a bundled and prefixed `psr/clock` would no longer share anyway) wraps `current_datetime()` in production and is fixed in tests; WordPress has no clock abstraction of its own. Dates are set through date references ([Events](events-and-forms.md#date-references)) |
| **Permissions** | Data access through views and grants per event; one site-wide full-access capability; other actions are per-event capabilities, usable inside a held view ([Views and grants](#views-and-grants)). Core ships a minimal role for outside grantees; the per-event action capabilities and their default bundles are defined with the owner later ([Deferred](deferred.md)). Admin actions are also WordPress abilities and use the same checks |
| **Personal data** | Kept only in our tables. WordPress privacy export and erase cover registrations, presets and audit diffs. Retention for guest data and the audit is decided later ([Deferred](deferred.md)); archiving an event starts its retention clock |
| **Secret links** | Treated as secrets: never logged, masked in exports ([Registrations](registrations-and-identity.md#access)) |
| **Money** | `DECIMAL(26,8)`, a currency code on every record, brick/math arithmetic, never floats ([Money](money.md#amounts)) |
| **Sorting** | `utf8mb4_hungarian_ci` on our text columns ([Engineering](engineering.md#database)) |
| **Language** | Hungarian UI strings directly in code; English identifiers and comments |
