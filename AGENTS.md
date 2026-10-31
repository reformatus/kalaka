# AGENTS.md

Kaláka is an event and camp registration plugin for WordPress and WooCommerce.
It replaces a Google Sheets, Forms and Apps Script system used by two separate
organizations, REFISZ and IVK, with one organization-agnostic codebase and one
WordPress install per organization. Current phase: core implementation ahead of
IVK go-live; IVK registration opens on 2026-11-30.

## Binding rule

`docs/architecture/` is binding. Before changing anything, read the sections
the topic map lists for your topic. If the code would need to deviate from
them, or they are silent or contradict each other on something that matters,
stop and ask the owner. Never resolve it silently and never work around it.

## Topic map

Links are relative to the repository root. Read these sections, not whole pages.

| Topic | Read |
|---|---|
| Purpose, fixed inputs, guiding principles | [Fixed inputs](docs/architecture/overview.md#fixed-inputs), [Guiding principles](docs/architecture/overview.md#guiding-principles) |
| Layers, two sides one machinery | [Layers](docs/architecture/foundation.md#layers), [Two sides, one machinery](docs/architecture/foundation.md#two-sides-one-machinery) |
| Views and grants (access to data) | [Views and grants](docs/architecture/foundation.md#views-and-grants) |
| Use-case runner, errors | [The use-case runner](docs/architecture/foundation.md#the-use-case-runner), [Errors](docs/architecture/foundation.md#errors) |
| Entities, repositories, queries | [Entities, repositories and queries](docs/architecture/foundation.md#entities-repositories-and-queries) |
| Audit, failures | [Audit](docs/architecture/foundation.md#audit), [Failures](docs/architecture/foundation.md#failures) |
| Deferred work (no job queue), bulk changes | [Deferred work](docs/architecture/foundation.md#deferred-work), [Bulk changes](docs/architecture/foundation.md#bulk-changes) |
| Derived facts | [Derived facts](docs/architecture/foundation.md#derived-facts) |
| Cross-cutting rules (storage, time, permissions, personal data, secret links, money type) | [Cross-cutting rules](docs/architecture/foundation.md#cross-cutting-rules) |
| Module boundaries, packaging, core modules | [Packaging](docs/architecture/modules-and-extensions.md#packaging), [Core modules](docs/architecture/modules-and-extensions.md#core-modules), [The Module interface](docs/architecture/modules-and-extensions.md#the-module-interface) |
| Schema ownership, identifiers, bundled libraries | [Schema ownership](docs/architecture/modules-and-extensions.md#schema-ownership), [Identifiers](docs/architecture/modules-and-extensions.md#identifiers), [Bundled libraries](docs/architecture/modules-and-extensions.md#bundled-libraries) |
| Extensions and the extension surface | [Extensions in view](docs/architecture/modules-and-extensions.md#extensions-in-view), [The extension surface](docs/architecture/modules-and-extensions.md#the-extension-surface), [Contracts](docs/architecture/modules-and-extensions.md#contracts), [Failure isolation](docs/architecture/modules-and-extensions.md#failure-isolation), [After-save actions](docs/architecture/modules-and-extensions.md#after-save-actions) |
| Assignment sets | [Assignment sets](docs/architecture/modules-and-extensions.md#assignment-sets) |
| Events, lifecycle, date references | [Events](docs/architecture/events-and-forms.md#events), [Event lifecycle](docs/architecture/events-and-forms.md#event-lifecycle), [Date references](docs/architecture/events-and-forms.md#date-references) |
| Forms and the registration slot | [Forms and the registration slot](docs/architecture/events-and-forms.md#forms-and-the-registration-slot), [Forms implementation](docs/architecture/events-and-forms.md#forms-implementation) |
| Form links, prefill, admin submissions | [Prefill from a link](docs/architecture/events-and-forms.md#prefill-from-a-link), [Form links and admin submissions](docs/architecture/events-and-forms.md#form-links-and-admin-submissions) |
| Fields, editability, conditions | [Fields](docs/architecture/events-and-forms.md#fields), [Editability after submit](docs/architecture/events-and-forms.md#editability-after-submit), [Conditions](docs/architecture/events-and-forms.md#conditions) |
| Registrations, identity (no person records), presets | [Registrations](docs/architecture/registrations-and-identity.md#registrations), [Identity: no person records](docs/architecture/registrations-and-identity.md#identity-no-person-records), [Presets](docs/architecture/registrations-and-identity.md#presets) |
| Submissions and values | [Submissions and values](docs/architecture/registrations-and-identity.md#submissions-and-values) |
| Status, capacity and waitlist | [Status, capacity and waitlist](docs/architecture/registrations-and-identity.md#status-capacity-and-waitlist) |
| Reference codes | [Reference codes](docs/architecture/registrations-and-identity.md#reference-codes) |
| Registrant access (secret links, accounts) | [Access](docs/architecture/registrations-and-identity.md#access) |
| Attribution and erasure | [Attribution and erasure](docs/architecture/registrations-and-identity.md#attribution-and-erasure) |
| Money scope, amounts | [Core money scope](docs/architecture/money.md#core-money-scope), [Amounts](docs/architecture/money.md#amounts) |
| Ledger | [Ledger](docs/architecture/money.md#ledger), [Recording payments by hand](docs/architecture/money.md#recording-payments-by-hand) |
| Confirmation | [Confirmation](docs/architecture/money.md#confirmation) |
| Withdrawal, removal and substitution | [Withdrawal, removal and substitution](docs/architecture/money.md#withdrawal-removal-and-substitution) |
| Tariff snapshot, re-price, materializer | [Tariff snapshot and re-price](docs/architecture/money.md#tariff-snapshot-and-re-price), [Materializer](docs/architecture/money.md#materializer) |
| Payments, paying several registrations, refunds | [Payments](docs/architecture/money.md#payments), [Paying several registrations at once](docs/architecture/money.md#paying-several-registrations-at-once), [Refunds](docs/architecture/money.md#refunds) |
| Sponsor and church support | [Sponsor and church support](docs/architecture/money.md#sponsor-and-church-support) |
| Congregation share | [Congregation share](docs/architecture/money.md#congregation-share) |
| Wallet (deferred) | [Deferred: design decisions](docs/architecture/deferred.md#design-decisions) |
| Mail | [Words](docs/architecture/mail.md#words), [Five kinds of mail](docs/architecture/mail.md#five-kinds-of-mail), [Configuration](docs/architecture/mail.md#configuration), [Content](docs/architecture/mail.md#content), [Sending](docs/architecture/mail.md#sending), [Failures](docs/architecture/mail.md#failures), [Pausing](docs/architecture/mail.md#pausing) |
| Time (UTC storage, Clock, site time zone) | [Cross-cutting rules](docs/architecture/foundation.md#cross-cutting-rules), [Date references](docs/architecture/events-and-forms.md#date-references) |
| Platform, database | [Platform](docs/architecture/engineering.md#platform), [Database](docs/architecture/engineering.md#database), [WordPress surfaces](docs/architecture/engineering.md#wordpress-surfaces) |
| Repository layout, module layout, builds | [Repository layout](docs/architecture/engineering.md#repository-layout), [Inside a module: grouped by domain](docs/architecture/engineering.md#inside-a-module-grouped-by-domain), [Builds](docs/architecture/engineering.md#builds) |
| Code style, static checks | [Code style](docs/architecture/engineering.md#code-style), [Static checks](docs/architecture/engineering.md#static-checks) |
| Tests, fixtures, build and CI | [Tests](docs/architecture/engineering.md#tests), [Fixtures](docs/architecture/engineering.md#fixtures), [Build and CI](docs/architecture/engineering.md#build-and-ci) |
| Deferred items, UI text approved when built | [Design decisions](docs/architecture/deferred.md#design-decisions), [UI text approved when built](docs/architecture/deferred.md#ui-text-approved-when-built) |

## Module AGENTS.md files

Each module directory gets a short `AGENTS.md` when it is created. It names
the architecture sections the module implements and its invariants, in the
docs' terms. Examples: the ledger is append-only; rules never read charge
lines; nobody sets a derived fact directly. Before working in a module, read
its `AGENTS.md`. Whoever adds or changes a module keeps it current in the same
change.

## Ground rules

- UI text is Hungarian, hard-coded, with no translation layer. Code
  identifiers and comments are in English.
- Nothing reaches the UI that the owner has not approved: no extra fields,
  labels, settings, styling or polish.
- Minimums: WordPress 7.0, PHP 8.3, MariaDB 10.3 and later (no features newer
  than 10.3). Never build for SQLite.
- No personal data and nothing from `internal/` in commits, fixtures, issues
  or PRs.
- Ask before deviating from the architecture pages.
- A change to `AGENTS.md` or to an architecture page is always called out in
  the PR description.

## Local evidence

`internal/docs/08-nuance-ledger-refisz.md` and
`internal/docs/10-nuance-ledger-ivk.md` are git-ignored, local-only evidence
about the 2026 systems. They are not authority. When replacing a 2026
behavior, grep them for that topic's `Keep` and `Change` entries and test
cases. When a spec is written, copy the relevant test cases into its
acceptance criteria. Anything binding must be in `docs/architecture/` or the
approved spec. Never copy from `internal/source-material/`: it holds personal
data of minors.

## Pipeline

The AI pipeline reads this file and `.pipeline/` from `main`. Pipeline agents
cannot see `internal/`.
