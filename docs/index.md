---
title: Overview
---

# Kaláka

*Product name decided in D-040; the repository is still called wp-campmanager.
Other plugin, module and code names are placeholders.*

Event and camp registration for REFISZ and Ifjúságépítők (IVK), replacing
today's Google Sheets, Forms and Apps Script setup with WordPress plugins.

**Status (2026-10-25):** core architecture and foundation decided;
implementation not started.

## Pages

- [Core architecture](architecture.md): layers and the use-case runner,
  packaging, events and forms, registrations, money, mail, extension surface,
  cross-cutting rules.
- [Decision log](decisions.md): every decision with its date and reasoning.

## Context

```mermaid
flowchart TB
  P["Participants and guardians<br/>personal link or optional account"]
  O["Organizers<br/>WordPress users with roles"]
  subgraph R["REFISZ website"]
    R2["Core plugin + extensions"]
    R1["WordPress + WooCommerce"]
    R2 --- R1
  end
  subgraph I["IVK website"]
    I2["Core plugin + extensions"]
    I1["WordPress + WooCommerce"]
    I2 --- I1
  end
  P --> R2
  P --> I2
  O --> R2
  O --> I2
```

Both websites run the same plugin code; they share no data.

## Registering

```mermaid
flowchart LR
  A["Open the event's form"] --> B{"Logged in?"}
  B -- "no" --> C["Register as guest"] --> D["Personal link by email"]
  B -- "yes" --> E["Prefill from a saved preset,<br/>or fill in fresh"] --> F["Register"] --> G["Account lists all<br/>registrations"]
  D --> H["Status page<br/>progress, balance, payment"]
  G --> H
```

## At a glance

- **One codebase, separate installs.** Each organization runs its own
  WordPress site; no data is shared between them.
- **Events are the top-level unit.** A registration is one participant for
  one event, with its own status, link and balance. Each event's budget is
  separate.
- **No account needed to register.** Every registration has a personal link
  to its status page. A WordPress account is optional: it collects
  registrations, keeps saved data sets (presets) that prefill new
  registrations, and can pay everything at once.
- **Money is an append-only ledger.** Every change is a new, explained line.
  Price changes after registration apply only through an explicit,
  previewed re-pricing.
- **Core plus extensions.** The core plugin covers events, forms,
  registrations with capacity and waitlist, a fixed price per registration
  with full payment, the ledger, mail, status pages and administration.
  Payments through WooCommerce, deposits, the tier ladder, late fees,
  financial aid, sponsor support, programs, lodging, check-in, buffet wallet
  and donations are separate extension plugins.
- **Built on WordPress itself:** its admin components, REST API,
  permissions, block editor (for forms, field definitions and mails) and interactive
  front end, rather than third-party form or admin plugins.
