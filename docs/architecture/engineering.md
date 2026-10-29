---
title: Engineering
---

# Engineering

## Platform

| | Minimum | Why |
|---|---|---|
| WordPress | 7.0 | |
| PHP | 8.3 | PHP 8.2's security support ends on 31 December 2026 |
| Database | MariaDB 10.3 | The ceiling of IVK's current host |

- **The database target is MariaDB 10.3 and later.** No features newer than
  10.3 are used, e.g. `JSON_TABLE`, `INSERT … RETURNING` or `SKIP LOCKED`.
- **Production today:** REFISZ runs on Hostinger with MariaDB 11.8 and PHP
  8.2, switchable to 8.3 at any time. IVK runs on shared hosting with MariaDB
  5.5 and PHP 8.1. Before go-live there, the IVK site moves to a new MariaDB
  10.3 database, and the hosting account switches to PHP 8.3; the version
  applies to all seven sites on the account, so they are checked first. IVK's
  host is not ours to choose, so its limits are fixed for now.
- **SQLite (WordPress Playground) is never built for.** It is used only for
  Playground previews; anything that fails on SQLite is tested on MariaDB
  only. Known differences: SQLite stores `DECIMAL` as a float, lacks
  `LAST_INSERT_ID(expr)` and MariaDB's collations, and aborts the whole
  transaction on a failed statement.

## Database

- **Hungarian sorting by collation:** `utf8mb4_hungarian_ci` on our text
  columns; no PHP-side collation or stored sort keys. WordPress's default
  collation sorts Hungarian wrongly (Öröm before Ottó).
- **Every table and text column declares `CHARACTER SET utf8mb4` and its
  collation explicitly.** Defaults differ: 10.3's server default is latin1, and
  a table without `COLLATE` gets the character set's default collation, not
  the database's.
- **The database does not validate values.** WordPress turns off strict mode,
  so an overflowing `DECIMAL` is clamped and a bad number becomes 0 with only a
  warning, and MariaDB 10.3 accepts invalid JSON. Values are validated in PHP
  before they are written.
- **Indexes are checked after creation:** an over-long index silently becomes
  a prefix index (or, on 11.8, a hash index for a unique key), so a schema
  test compares the created indexes with the declared ones.
- **Not available on 10.3** besides the features named above:
  `JSON_ARRAYAGG`, `JSON_OBJECTAGG`.
- **Locking:** the capacity check's `FOR UPDATE` on the event row is the
  first read of the submit transaction. A plain read before it would let a
  later count reuse an old snapshot and overbook the event.
- **Retryable database errors:** deadlock (1213), lock-wait timeout (1205),
  "record has changed since last read" (1020, 11.8) and a duplicate key on a
  reference-code collision (1062). The runner always issues an explicit
  rollback, because some of these leave the transaction open.
- **Per-event cost is tested:** an integration test seeds many events and
  checks each per-event query's plan with `EXPLAIN`; a query that examines
  rows outside its own event fails the build
  ([Foundation](foundation.md#entities-repositories-and-queries)).
- **Schema:** each module owns and migrates its own tables; no migrations
  until production data exists
  ([Modules](modules-and-extensions.md#schema-ownership)).

## WordPress surfaces

- **REST follows WordPress core conventions** (paging, search, sorting,
  totals, schemas), so the admin screens use WordPress's own data layer and
  admin components (DataViews, DataForm) over core-data entities.
- **Admin actions are also WordPress abilities** (create a payment link,
  award aid, re-price, admit from the waitlist, …): schema-checked input,
  permission checks and REST access come from WordPress. Admin table data
  stays on our own REST API.
- **Block editor** for forms, field definitions and mail bodies; the
  **Interactivity API** for public forms.
- **WordPress primitives are preferred** wherever they fit: `WP_Error`, Site
  Health, the `Requires Plugins` header, the privacy export and erase tools,
  post passwords.

## Repository layout

```
plugins/
  kalaka/                 core plugin
    kalaka.php
    composer.json         Kalaka\ → src/
    src/<Module>/…        Kalaka\<Module>\…, public API in Kalaka\<Module>\Api\…
    tests/unit/
    tests/integration/
  kalaka-woocommerce/     an extension plugin, same shape
composer.json             development tools only
package.json              development tools only
docs/                     these pages (GitHub Pages)
internal/                 git-ignored: drafts, spikes, source material
```

- Each plugin has its own `composer.json`; the root `composer.json` and
  `package.json` hold development tools only.
- **JavaScript:** `@wordpress/scripts` in each plugin, for blocks and the
  admin app.
- Bundled PHP libraries are prefixed per plugin with Strauss
  ([Modules](modules-and-extensions.md#bundled-libraries)).

## Code style

- **PER Coding Style** (the successor of PSR-12). WordPress's formatting rules
  (Yoda conditions, spaces inside parentheses, snake_case methods) do not
  apply.
- **From the WordPress coding standard, only the rules that catch real bugs or
  security problems:**
  - escaping output;
  - sanitizing input;
  - nonce checks;
  - prepared SQL;
  - no direct database queries outside our repositories and query classes;
  - WordPress functions preferred over PHP ones where WordPress has its own
    (e.g. `wp_json_encode`, `wp_remote_get`).
- PHPCS runs this mixed ruleset.

## Static checks

- **PHPStan** with WordPress stubs.
- **deptrac** enforces module and plugin boundaries: a module uses another
  only through its `Kalaka\<Module>\Api\…` namespace.
- **Backward-compatibility check** of the public API against the last release,
  from the end of the alpha phase
  ([Modules](modules-and-extensions.md#alpha-phase)); the tool is chosen then
  (e.g. `roave/backward-compatibility-check`).

## Tests

- **`tests/unit`** runs without WordPress, where code is naturally free of it
  (e.g. money arithmetic).
- **`tests/integration`** runs with WordPress loaded, on MariaDB, in wp-env.
  Most tests are integration tests.
- **Integration tests run on MariaDB 11.8 and 10.3,** with PHP 8.3 and the
  latest PHP 8.x.
- **SQLite is only a smoke test:** Playground starts and loads the seed.
- **Time** is controlled through a fixed PSR-20 clock.

## Fixtures

- **One seed: our own `wp kalaka seed` command.** It creates WordPress users
  per role with WordPress functions, and our own data (realistic anonymized
  registrations) through our use cases.
- The Playground blueprint runs the same command.
- During development the schema is dropped and recreated freely.

## Build and CI

- **One build script produces a zip per plugin:** Composer installs without
  development dependencies, Strauss prefixes the libraries, the JavaScript
  build runs, development files are left out, and the one version is written
  in one place and copied into every plugin header.
- **CI on every pull request:** static checks, unit tests, integration tests
  (the database and PHP matrix above), the Playground smoke test, the zips and
  a Playground preview link; the compatibility check joins when alpha ends.
