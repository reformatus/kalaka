---
title: Modules and extensions
---

# Modules and extensions

## Packaging

- **A core plugin plus one extension plugin per feature,** in one repository
  with one version number and one CI pipeline that builds a zip per plugin.
  Extensions use only core's public API.
- **Core itself is internally modular** with strict boundaries.
- **Core money is minimal:** a fixed price per registration, full payment,
  manual charges and discounts, an event's extra charges and its donation.
  Deposits, the price ladder, financial aid, sponsor and church support, the
  congregation share, late fees and the wallet are plugins; core keeps full
  support for extending it in these ways
  ([Money](money.md#core-money-scope)).
- **Core keeps the base concepts that organizations extend,** such as
  assignment sets ([Assignment sets](#assignment-sets)), not a general system
  that could build either organization's logic.
- **The extension plugins on `main`:**

  | Plugin | What it adds |
  |---|---|
  | `kalaka-woocommerce` | card payments on core's payments port ([Money](money.md#payments)) |
  | `kalaka-price-ladder` | price tiers and their quotas ([Money](money.md#core-money-scope)) |
  | `kalaka-deposits` | the per-event deposit amount (the threshold and retention that read it: not built yet) |
  | `kalaka-aid` | adjudicated aid ([Money](money.md#core-money-scope)) |
  | `kalaka-support` | the organization directory, the congregation picker and the support offers ([Money](money.md#sponsor-and-church-support)) |
  | `kalaka-share` | the congregation share ([Money](money.md#congregation-share)) |
  | `kalaka-ranking` | the module ranking field type ([Extensions in view](#extensions-in-view)) |
  | `kalaka-divi4` | the Divi 4 theme adapter ([Extensions in view](#extensions-in-view)) |

- **Core has no WooCommerce dependency at all.** On a WooCommerce site the
  WooCommerce extension also lets anyone who may reach Kaláka's admin (full
  access or any grant) into wp-admin, and sends logged-out visitors of My
  Account and of a customer's pay page to WordPress's own login and password
  pages, so there is one login screen (owner ruling 2026-11-26).
- **Core holds no organization data;** the directory is `kalaka-support`'s.

**What follows:** a feature one organization needs goes into an extension
unless both need it as a base concept. Turning a plugin off must leave core
working; a plugin that core would have to know about is a sign the point is
missing, not that core should reach into the plugin.

```mermaid
flowchart LR
  subgraph EXT["Extension plugins"]
    direction TB
    WC["WooCommerce payments"]
    TI["Price ladder"]
    DE["Deposits"]
    LF["Late fees"]
    AID["Adjudicated aid"]
    SP["Sponsor / church support"]
    CS["Congregation share"]
    REC["Module ranking"]
    MORE["Wallet · attendance sheets · check-in · settlement"]
  end
  subgraph CORE["Core plugin"]
    direction TB
    FD["Foundation"]
    EV["Events"]
    FO["Forms"]
    PF["ParticipantFields"]
    AS["Assignments"]
    RE["Registrations"]
    RU["Pricing"]
    EX["Extras"]
    LE["Ledger"]
    PA["Payments"]
    MA["Mail"]
    PO["Portal"]
    AD["Admin"]
  end
  WOO[("WooCommerce")]
  EXT -->|"public API only"| CORE
  WC -.->|"implements"| PA
  WOO --- WC
```

## Core modules

| Module | Responsibility |
|---|---|
| Foundation | runner, audit, clock, Money, evaluation, decisions, the periodic run, site warnings, module registry |
| Events | events, states, timeline points, the payment-due date type, capacity and the "full" latch; later the participant phase tracker |
| Forms | forms, the registration slot, field types, field definitions, values and conditions, prefill, form links |
| ParticipantFields | the event's participant fields: the list, its sources, titles and option labels, retired fields |
| Assignments | assignment sets, options, assignment facts, tallies, roster views, the proposer point |
| Registrations | registrations, field values, submissions, drafts, presets, statuses, waitlist, seats, requests and answers, reference codes, accounts |
| Pricing | fixed price, fee-component framework, confirmation, retention, materializer, money settings apply |
| Extras | extra charges and the donation, each declaring the participant field with its key for forms to join |
| Ledger | lines, payers, balances, organizer-recorded payments, refunds and corrections, manual charges and discounts |
| Payments | the payments port, online recording, starting a card payment |
| Mail | action mails, templates, deliveries, the sender, manual mail, the pause |
| Portal | the active registration (by secret link or account), the participant blocks admins place on any page, the registrant's own acts, the account area |
| Admin | views and grants, data grid, detail views, field corrections, notes, audit, settings, the mail screens |

Each module's `AGENTS.md` names the sections it implements and its
invariants.

## Assignment sets

Modules, workshops, talk groups, rooms and meal turns share one core concept.

- **A set** belongs to an event: a name, an optional time slot, required or
  optional, an access mode and a **published switch** (unpublished, it is
  hidden from participants). **A set has no choice window of its own** (owner
  ruling 2026-11-25): the form that holds the choice, with its own open
  period and conditions, decides when it can be answered.
- **An option** has a capacity, leaders (display text) and a place. **It
  holds no condition:** who may pick it is decided in the form, by the form
  field that names the set, with a condition per option
  ([Conditions](events-and-forms.md#conditions)).
- **A registration holds at most one option per set,** stored as an
  assignment fact with its source and state (proposed or confirmed), audited.
  Only staff, and later the proposer's confirmation, change it.
- **A `registered` registration holds a seat in each option it is confirmed
  into,** under the one seat rule
  ([Foundation](foundation.md#facts-decisions-and-computed-values)). The
  confirmed assignment stays as the record when it leaves, and holds no seat
  while the registration is not `registered`. A proposed assignment holds
  none. The option's capacity is the pool's limit.
- **Access modes:**
  - **request** (e.g. IVK's modules): the participant writes a request, a
    field value of a request field (the ranking field type comes from
    `kalaka-ranking`), which an additional form holding it can change while
    that form is open. A request field's block either names its set, and its
    options are the set's, or names none and holds its own options. **A set
    has one request field,** tied once and never released. Staff run the
    proposer with a preview, then confirm in bulk (one transaction per item,
    capacity re-checked; items that no longer fit go into the report); the
    participant sees the result once confirmed and the set is published;
  - **direct** (e.g. REFISZ's morning and afternoon programs): the choice
    field writes a confirmed assignment; a full option cannot be chosen; the
    choice can be changed through an additional form holding it while that
    form is open, then only by staff;
  - **none** (talk groups, rooms, meal turns): staff place people, or a
    proposer proposes and staff confirm; shown once published.
  - **Staff can place or override in every mode,** warned first when the
    option is full; the warning never stops the placing.
- **Meal turns** (IVK's lunch turn, REFISZ's dining shift) are an access-none
  set **computed from another set through an option map**, never stored,
  unless staff placed someone by hand, which is stored as a fact.
- **Tallies** for every set (count per option, unassigned, missing a required
  choice) are view columns.
- **Rosters are views, not generated documents:** each set has one roster
  view filtered by "assigned option = ?", bound per grant
  ([Foundation](foundation.md#views-and-grants)). Giving each option's
  leaders their roster means granting it to them.
- **The assignment proposer** is an extension point. The IVK ranking engine
  is the first: first choices in registration order, then second choices
  into what is left, then third; staff confirm. **It runs once, on module
  choices frozen beforehand,** and need not handle later changes.
- Extensions read confirmed assignments through point inputs and keep their
  own per-option data (e.g. a module's level).
- Sets and options are copied to the next event.
- **Not in core:** ranking capture, placement algorithms, per-option
  waitlists.
- **Built on `main`:** sets, options, assignment facts, the request mode's
  tie to its request field, and staff placing with seats. **Not built yet:**
  direct mode, tallies, rosters, the proposer and its bulk confirmation, meal
  turns, copying to the next event.

**What follows:** placement logic of any kind lives in a proposer extension,
never in core; a new kind of grouping (rooms, buses) is a set with access
mode none, not a new concept.

## Extensions in view

Besides the money plugins:

- **Module ranking** (IVK, `kalaka-ranking`): the ranking field type (built)
  and the ranking proposer (not built yet). The module recommender is not
  part of Kaláka: it is IVK's own quiz on another site, which hands its order
  to the form as a prefill. **A form link created on a public page** (e.g. a
  "register for the other camp too" link on a thank-you page) **is created by
  a portal or system use case with its own rate limit, never an admin use
  case.**
- **Attendance sheets** (REFISZ, later): printable sheets participants sign on
  site. Templates per event are bound to a view, which picks the rows and
  caps the columns; each print is audited as a personal-data export. Not
  built yet.
- **The Divi 4 theme adapter** (both organizations, `kalaka-divi4`): Divi's
  contact-form classes on Kaláka's form markup, `p` wrappers where Divi's form
  rules need them, Divi's form red as the error colour, Divi's form styles
  loaded inline on pages that hold a form or the status block, and the card
  return page in Divi's page frame. It keeps visible labels and the
  browser's own radio buttons and checkboxes
  ([Engineering](engineering.md#wordpress-surfaces)). Divi 5 and other themes
  come later, each as its own adapter.
- **The wallet** is designed later ([Deferred](deferred.md)).

## The Module interface

- **One `Module` interface for core modules and extension plugins.** A module
  declares its tables, per-event actions and extension-point contributions,
  and wires itself into WordPress in `boot()`:

  ```php
  interface Module {
      public function id(): string;                        // [a-z][a-z0-9_]*
      public function requiresApi(): string;               // e.g. '>=0.1 <0.2'
      public function schema(): Schema;                    // Schema::none() without tables
      public function capabilities(): array;               // list<string>, per-event actions
      public function contribute(Contributions $to): void; // typed extension points
      public function boot(): void;                        // add_action etc.
  }
  ```

- **`capabilities()` names the per-event actions** the module offers (e.g.
  `kalaka_withdraw_registration`). Core collects them into one catalogue that
  grants pick from ([Foundation](foundation.md#views-and-grants)); **they are
  not WordPress capabilities and are never added to roles.** Only the
  minimal role for outside grantees touches WordPress roles; the full-access
  capability is given per user. Both belong to Admin.
- **`Schema`** is a value object: a version, the `CREATE TABLE` statements and
  upgrade steps. **`Contributions`** has one typed method per extension
  point, added with the first module that needs it.
- **Boot:** core boots on `plugins_loaded`, adds the core modules in a fixed
  order, then fires `kalaka_register_modules`, where extensions add theirs, so
  plugin load order does not matter. Then for each module: the version check,
  the schema upgrade (under WordPress's upgrader lock), `contribute()` and
  `boot()`. A module whose upgrade another request is running is not booted
  in this one, which closes this request without logging a failure.
- An extension plugin is a module that lives in another plugin, so a core
  module can later move into its own plugin unchanged.
- **Other modules and plugins use only a module's `Api` namespace**
  (`Kalaka\<Module>\Api\…`), checked in CI. It is the PHP contract, not the
  REST API.
- **No DI container.** Each module builds its own objects in plain PHP with
  constructor injection.

## Schema ownership

- **Each module owns its schema.** A module creates and migrates only its own
  tables, under its own schema version. No module, core included, migrates
  or writes another module's tables; others go through its `Api`. Modules
  upgrade independently.
- **Tools:** WordPress's `dbDelta` creates tables and adds columns; ordered
  upgrade steps handle drops, renames and data moves.
- **Upgrade steps are safe to rerun,** because MariaDB commits after every
  schema change; the version advances one step at a time.
- **An upgrade fails rather than advance the version** when the tables still
  do not match their definitions after it (checked by running `dbDelta` again
  as a dry run), so definitions are written in `dbDelta`'s own format.
- **A failed upgrade** leaves its module unbooted, which is a failed load
  ([Failure isolation](#failure-isolation)). The next request tries again.
- **Until production data exists there are no migrations:** during
  development everything is dropped and recreated freely, with test fixtures
  ([Engineering](engineering.md#fixtures)). Migrations start at the first
  production deployment.

## Identifiers

The product name is **Kaláka**. `wp_` is reserved for WordPress core and is
never our prefix.

| What | Form |
|---|---|
| Core plugin | `kalaka` (`kalaka/kalaka.php`) |
| Extension plugins | `kalaka-<name>`, e.g. `kalaka-woocommerce` |
| PHP namespace | `Kalaka\<Module>\…` for core and extensions alike, so a module moves between plugins unchanged |
| Composer packages | vendor `kalaka`: `kalaka/core`, `kalaka/woocommerce`, … |
| Tables, options, capabilities, hooks | `kalaka_` prefix; hooks with underscores like WordPress core |
| Table names | plural like WordPress core, e.g. `kalaka_registrations`; extension tables `kalaka_<module id>_<plural>`, e.g. `kalaka_woocommerce_refund_requests` |
| Module IDs | `[a-z][a-z0-9_]*`, e.g. `events`, `price_ladder`; schema version option `kalaka_schema_<id>` |
| Use-case classes | verb + noun: `SubmitRegistration`, `WithdrawRegistration` |
| Action names (audit, abilities) | admin `kalaka/<verb-noun>` (also the ability name), portal `kalaka-portal/<verb-noun>` |
| Blocks, abilities | `kalaka/<name>` |
| Post types | `kalaka_form`, `kalaka_field`, `kalaka_mail` |
| REST namespaces | `kalaka-admin/v1`, `kalaka-portal/v1`; an extension's own `kalaka-<module id>/v1`, e.g. `kalaka-support/v1`, where every public route calls `LoadState::requireOpen()` |
| Repository | `reformatus/kalaka` |
| Plugin headers | `Plugin Name: Kaláka` (extensions `Kaláka – <Feature>`), `Plugin URI: https://kalaka.dev`, `Author: Benedek Fodor`, `Update URI: https://kalaka.dev` so WordPress never offers a wordpress.org plugin of the same slug as an update; no Text Domain; extensions add `Requires Plugins: kalaka[, …]` |

## Bundled libraries

- **Any plugin that bundles a library prefixes it into its own namespace:**
  core, our extensions and third-party ones alike. Core uses Strauss and
  produces, for example, `Kalaka\Vendor\Brick\Math`, so no clash is possible.
- **Libraries that appear in core's public API are core's.** If an extension
  point hands over a Money built on brick/math, the extension uses core's
  prefixed copy; a second copy would be a different class. Core's `Money`
  takes and returns no brick/math type.
- **Our own extensions reuse core's copy when they can.** This is a
  convenience for us, not a rule for third parties.

## The extension surface

```mermaid
flowchart LR
  EP["Extension plugin<br/>Requires Plugins: kalaka"] -->|"registers its Module"| REG["Core registry"]
  REG --> IN["Inside the transaction<br/>fee component · retention rule ·<br/>request source · decision ·<br/>confirmation threshold · date type ·<br/>condition · ledger line source ·<br/>action-mail act · assignment proposer"]
  REG --> OUT["Outside the transaction<br/>field type · payment provider ·<br/>theme adapter · slot requirement ·<br/>seat claim source · periodic step ·<br/>site warning · seeder ·<br/>portal / admin section · view · view column · filterable fact"]
  REG --> DECL["Declarations<br/>payer kind · block host"]
```

- **Extensions declare core in their `Requires Plugins` header,** so WordPress
  refuses to activate them without it. (The header checks only that core is
  active, not its version.)
- **They contribute through a typed registry** with a closed list of extension
  points. Anything that shapes behavior or money goes through these typed PHP
  interfaces.
- **Each point runs either inside or outside the transaction.** An inside
  point fires no WordPress hooks and writes nothing; it is pure enough to run
  while rows are locked. An outside point may use WordPress, but is never
  called while a use case holds locks.
- **The points on `main`:**
  - **fee component** (inside): returns what a registration owes at its
    stage of the fee sequence ([Money](money.md#materializer));
  - **retention rule** (inside): what a payer keeps owing when a registration
    leaves ([Money](money.md#withdrawal-removal-and-substitution));
  - **request source** (inside): a module's requests on a registration that
    need an answer (a support offer that needs approval, an aid request),
    read from its field values; the writer of its own answer field when the
    one "answer a request" act answers one; and a declarative rule for when
    its request awaits an answer, which core compiles to SQL for the grid
    ([Money](money.md#sponsor-and-church-support));
  - **field type** (outside, except that reading a stored answer as a value
    is pure and runs inside): a block type with its data shape, validation,
    editor and front-end UI ([Fields](events-and-forms.md#fields));
  - **payment provider** (outside): implements the payments port; one per
    site ([Money](money.md#payments));
  - **theme adapter** (outside), below;
  - **slot requirement** (outside): participant fields the event's
    registration form must hold, required and outside any condition (the
    price ladder requires `price_tier`); a form without one is closed until
    fixed;
  - **seat claim source** (outside): a submit's answers in, the seat claims
    of the limited choices made in them out, and the list of its pools for
    the form's places-left values. It locks, counts and writes nothing; the
    act takes the seats
    ([Foundation](foundation.md#facts-decisions-and-computed-values));
  - **periodic step** (outside): work the periodic run does at its end (the
    sender, the WooCommerce reconcile);
  - **site warning** and **seeder** (outside);
  - **payer kind** (a declaration): a payer other than the participant (the
    share's "congregation"), with a plain per-event read of whether it
    applies;
  - **block host** (a declaration): which Kaláka blocks an editor other than
    a form's may insert, and its own blocks no other editor offers (the mail
    template's money blocks).
- **Foreseen points, not built yet:** decision (an extension's transition
  rule, first the late fee; core writes the records), confirmation threshold
  (the deposit lowers the target), date type (a date per registration, e.g.
  "14 days after registering"; today payment due is core's own), condition
  (an extension's test beside the comparisons), ledger line source (only the
  wallet's), action-mail act (an extension act such as a check-in that causes
  an action mail, keyed by its record), assignment proposer, and the UI slots
  (portal or admin section, view, view column, filterable fact).
- **A theme adapter** fits participant-side markup to a theme that styles its
  own components instead of WordPress's
  ([Engineering](engineering.md#wordpress-surfaces)). It says whether it
  applies to the active theme and returns data only: extra classes for each
  element role core names, the wrapper element of a text-like field (`div`
  or `p`), the content frame of a page core renders whole (the card return
  page) from core's closed list, and the error colour. Core applies these and
  still produces the markup itself. The adapter loads the theme's own styles
  through the theme's mechanisms and may add a stylesheet that maps core's
  neutral base onto the theme's look; it never changes markup. At most one
  adapter applies at a time.

### Contracts

- **A point models a domain concept** (a fee component, a payment method), not
  a place in the code.
- **A point is added only when one of our own extensions needs it,** and that
  extension uses it first.
- **Inputs are immutable value objects:** one input object per point, named
  `<Point>Input`, so adding a field breaks no one. A point never receives
  repositories, entities, the use-case runner or database access, and returns
  a defined value.
- **Extensions contribute; they never modify.** No WordPress filter or other
  open hook changes a money value or a money decision: extensions contribute
  lines or decisions through typed points, and core composes and records the
  result. **UI points are slots:** a section renders into a region it owns;
  no point lets an extension change markup that core produced. The theme
  adapter is no exception: it returns values from closed lists, and core
  applies them. (Moodle's renderer overrides and WordPress's HTML filters are
  the most fragile parts of those ecosystems.)
- **After the alpha phase, a released point is permanent.** It is never
  changed incompatibly or removed. Changes are additive only (an abstract base
  class gives new methods a default, or a new capability is a separate
  optional interface); a breaking change is a new versioned point (`…V2`)
  that runs alongside the old one for a deprecation period. CI then compares
  the public API with the last release on every pull request.

**What follows:** an extension that needs a new behavior asks for a new
point, shaped as a domain concept, rather than a hook into core's code. An
extension never writes core's tables, even through a point: it returns a
value and core writes it.

### Alpha phase

While every extension lives in this repository and serves only REFISZ and
IVK, and no plugin has been released publicly as open source, the public API
may change in breaking ways: a change updates every user in the repository in
the same pull request. During alpha, permanence, additive-only changes and the
CI compatibility check do not apply; the contract *shape* above (domain
concepts, immutable input objects, our own extension using a point first)
does. **Alpha ends with the first public release of any plugin.** The
deprecation period and the compatibility-check tool are chosen then.

### Version check

- Core's public API carries a version: **the shared release version, under
  semantic versioning.** Each extension module declares the range it
  supports through `requiresApi()`, e.g. `>=1.4 <2.0`, using only `>=` and
  `<`. During 0.x any release may break.
- **Core boots only extensions in range.** One out of range is not booted,
  which is a failed load ([Failure isolation](#failure-isolation)). This
  holds for our own extensions too: WordPress updates plugins one by one, so a
  site can run mismatched versions despite the one repository version.

### Failure isolation

- **A contribution outside the transaction that throws is caught and
  skipped,** so core keeps working (a failing theme adapter: the form renders
  without it; a failing payment provider: the page reads it as absent). Its
  failure is logged. The design keeps it on its extension too, as a site
  warning with a system audit entry when it starts, changes or ends (after 24
  hours without a failure, so it does not flap); that stored failure record
  is not built yet for any contribution.
- **A contribution inside the transaction that throws rolls back the use
  case** like any error. One exception: when a fee component throws while a
  withdrawal or removal composes the fee for its retention rule, the rule
  gets no fee ("cannot say") and the leaving goes through.
- **No point writes to our tables:** a point returns a value, and core writes
  it.
- **If anything fails to load** (an extension out of range or unbooted, a
  missing field type, a failed upgrade, an exception while extensions
  register), **every event is treated as unpublished**: all public access is
  refused and mail decisions and sending stop
  ([Events](events-and-forms.md#event-lifecycle)). There is no per-event
  dependency analysis: at our scale stopping everything is simple and clear.
  The stored event states do not change, so events come back once the
  problem is fixed. A check that can run only once WordPress has loaded may
  add a failure later in the request; nothing reopens the site within it.
  - **One outcome:** every public entry point refuses with "site closed"
    (503); background work skips; the runner refuses every use case before
    its transaction, so nothing writes to tables that may not match the code.
    Admins can still read and see the failed-load warning.
  - **Logged once,** when the failure starts or changes, not on every
    request.

**What follows:** deactivating an extension whose field type a published
form uses closes the whole site, not just that form. That is deliberate: a
half-working registration that drops answers is worse than a closed one.

### After-save actions

Public after-save WordPress actions for third-party plugins are deferred.
When they are added, they come **first** among points offered to third
parties: stable documented names, an immutable payload, fired after the data
is saved, with no effect on core behavior. They are the cheapest promise to
keep and what most integrations need (syncing to a CRM, posting to a chat);
points that shape behavior or money are the costly ones to keep stable.
