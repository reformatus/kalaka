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
  columns; no PHP-side collation or stored sort keys. It sorts ö/ő after o and
  ü/ű after u, which WordPress's default collation gets wrong (Öröm before
  Ottó). **Known deviations, accepted:** it has no digraphs, so cs, dz, dzs,
  gy, ly, ny, sz, ty and zs sort as two letters (Csaba before Cukor and
  Czakó, Zsolt before Zuhany), doubled forms are not expanded (Asszony before
  Aszalós), and Adam and Ádám tie. Gy, ny, sz and the like mostly come out
  right because y and z sort late. Every MariaDB collation has these
  deviations; sorting stays indexable and free.
- **Accent-insensitive search:** under `utf8mb4_hungarian_ci`, o ≠ ö and
  u ≠ ü, so search conditions use an accent-blind collation
  (`utf8mb4_unicode_520_ci`) in the predicate. Searches stay within one event,
  so their cost is bounded.
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
    uninstall.php
    README.md             layout rules, WordPress concept → file map, entry points
    composer.json         kalaka/core, Kalaka\ → src/
    build/                git-ignored, shipped in the zip
    src/<Module>/…        Kalaka\<Module>\…
    tests/unit/
    tests/integration/
  kalaka-woocommerce/     an extension plugin, same shape
VERSION                   the one version number
composer.json             development tools only
package.json              development tools only
phpstan.neon.dist, phpcs.xml.dist, deptrac.yaml   for all plugins
docs/                     these pages (GitHub Pages)
internal/                 git-ignored: drafts, spikes, source material
```

- Each plugin has its own `composer.json`; the root `composer.json` and
  `package.json` hold development tools only.
- Bundled PHP libraries are prefixed per plugin with Strauss
  ([Modules](modules-and-extensions.md#bundled-libraries)).
- No `languages/`, Text Domain or `readme.txt` (Hungarian only, not listed on
  wordpress.org); no Loader class and no `index.php` placeholder files.

### Inside a module: grouped by domain

Modules are grouped by domain, not by layer (close to GiveWP's domain-first
layout, without its folders sorted by kind and without a DI container).

```
src/Registrations/
  Module.php                    entry point
  README.md
  Registration.php              the main noun and its use cases at the root
  RegistrationList.php
  RegistrationRepository.php
  SubmitRegistration.php
  Api/
    RegistrationKey.php
  Preset/                       another noun: its own folder
    Preset.php
    PresetRepository.php
    SavePreset.php
    Rest/                       an entry point that belongs only to presets
      PresetsController.php
  Waitlist/
    AdmitFromWaitlist.php
  Rest/
    RegistrationsController.php
  Blocks/
    registration-status/
```

- **Every module's entry point is `Module.php`** (`final class Module extends
  AbstractModule`), in core and in extensions alike, and it is the only place
  a module hooks into WordPress. `Module.php` and `README.md` are the two files
  to open first in any module.
- **The module's main noun and its use cases sit at the module root:** the
  record, its repository, its list query, and the use cases that change it.
- **Every other noun gets its own folder** with its own record, repository,
  list and use cases. A use case lives with the noun it changes; if it changes
  several, it goes with the main one.
- **The same rules apply inside a noun folder:** it can have its own sub-noun
  folders, and its own WordPress entry-point folders when those belong only to
  that noun.
- **The only folders that are not nouns:**
  - `Api/`: the PHP contract for other modules and extensions (not the REST
    API);
  - WordPress entry points, named as WordPress names them and created only
    when needed: `Rest/`, `Cli/`, `Blocks/<block-name>/` (`block.json`,
    `render.php`, `edit.js`, `view.js`, laid out as `create-block` does).
- Repositories are named `<Entity>Repository`.
- Extension plugins have the same shape.
- **Layers are not folders.** Today deptrac checks only the boundary between
  modules: a module uses another only through its `Api` namespace. As use
  cases and repositories appear, layers by interface, base class and name join
  (use cases implement `UseCase`, repositories extend the repository base), so
  it can then enforce, for example, that `Rest/`, `Cli/` and `Blocks/` never
  touch repositories ([Static checks](#static-checks)).

### Builds

- **PHP is buildless:** PSR-4 autoloading straight from `src/`. The PHP build
  only packages, and Strauss touches only bundled libraries.
- **One JavaScript build for everything:** a single `@wordpress/scripts`
  build covers all JavaScript, including the Interactivity API `view.js`
  files (built as script modules). There are no buildless modules alongside
  built scripts. During development `npm start` will run it in watch mode
  (planned: the build is added with the first JavaScript).
- **Source maps** are part of the build and ship in the zip, so the shipped
  JavaScript stays readable.
- **`@wordpress/scripts`** scans `src/` for `block.json` (with
  `--webpack-copy-php`); blocks are registered from `build/`.
- **The admin app** lives in `src/Admin/app/`, with its own entry in a small
  custom webpack config. If it grows large it can move to a top-level
  `client/admin/`.

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
  only through its `Kalaka\<Module>\Api\…` namespace. Layer rules by
  interface, base class and name join as those classes appear.
- **Direct database access** (`$wpdb`) outside repositories and query classes
  is caught by PHPCS's direct-query rule ([Code style](#code-style)); deptrac
  cannot see a `global $wpdb` cheaply. The rule gets `exclude-pattern`s for
  repositories and query classes once they exist.
- **Admin reads registration data only through the view query**
  ([Foundation](foundation.md#views-and-grants)). deptrac enforces it: Admin
  and the admin-side entry points (`Rest/`, `Cli/`, `Blocks/`) may use
  registration data only through the view-query class, and full-access code
  paths are a named layer of their own. The rule joins when the view-query
  class exists; until then PHPCS's direct-query rule keeps reads inside
  repositories and query classes.
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
- **Time** is controlled through a fixed clock
  ([Foundation](foundation.md#cross-cutting-rules)).

## Fixtures

- **One seed: our own `wp kalaka seed` command.** It creates WordPress users
  per role with WordPress functions, and our own data (realistic anonymized
  registrations) through our use cases.
- The Playground blueprint runs the same command.
- During development the schema is dropped and recreated freely.

## Build and CI

- **One build script produces a zip per plugin:** Composer installs without
  development dependencies, Strauss prefixes the libraries, the JavaScript
  build runs, development files are left out, and the one version, kept in
  the root `VERSION` file, is copied into every plugin header and into
  `KALAKA_VERSION`. A check fails when they differ.
- **CI on every pull request:** static checks, unit tests, integration tests
  (the database and PHP matrix above), the Playground smoke test, the zips and
  a Playground preview link; the compatibility check joins when alpha ends.
