---
title: Deferred
---

# Deferred

Items deliberately left for later, each with the moment it is settled. When
one is decided, its answer moves into the page it belongs to and its row is
removed here.

## Design decisions

| Item | Settle when |
|---|---|
| Participant phase tracker: configurable phases, and phases extensions add. The core default phases participants see (one sequence: waitlisted → awaiting payment → confirmed, or withdrawn / removed) are built with the participant status block | when an extension needs a phase of its own |
| Unverified accounts (the set-password link never used, or no registration ever submitted): not deleted automatically; whether and how they are removed | the privacy work, together with the privacy tools |
| Retention of guest data and of the audit, including failed entries (ledger lines are kept at least 8 years, [Registrations and identity](registrations-and-identity.md#attribution-and-erasure)) | before a wide release (privacy policy) |
| The privacy tools: WordPress's privacy export and eraser for registrations and presets, the audit-diff scrub, IP address and user agent declared to WordPress's privacy tools, self-service erasure and the other GDPR features. Not needed at launch: the 2026 systems had none | before a wide release |
| Purging stored mail errors (on deliveries, on the mail pause and in audit diffs; stored as reported, which may name an address) and their retention | the compliance work, together with the privacy tools |
| Default bundles of per-event actions | when outside partners get grants |
| Per-event confirmation mode. Automatic is the default and today's behaviour: payments reach the threshold, or nothing is due (0 = 0 included). Manual only: only the manual flag confirms, payments included; paid-in-full unconfirmed registrations are found with a view filter. Off: no confirmation column or filter values, no confirm or unconfirm actions, no confirmation state on the registrant page, no confirmation mail. A mode change reaches existing registrations through a preview and an apply, like money settings ([Money](money.md#money-settings-apply)) | when an event needs a mode other than Automatic |
| The late fee's place in the pass (money, then confirmation, then mail): its charge is written by the materializer, which runs before the decisions | with REFISZ's late-fee slice |
| Which money plugins come first for REFISZ's summer camps (IVK's and Téli Tali's are in the [release plan](release-plan.md)) | before the summer camps' registration opens |
| The wallet: separate from the fee (a top-up never pays it, a fee debt never enters the wallet); one per registration; no spending below the balance; top-ups by card or cash at the buffet; carry-over between events of the organization for every payer; moving overpayments into wallets in one click; settlement thresholds per event; what the participant sees | designed in December 2026, built before Téli Tali (2027-02-03, [Release plan](release-plan.md)) |
| Reserved places per partner, e.g. as a per-link quota | after IVK registration opens |
| IVK's staff and volunteer data form, for staff who are not participants (REFISZ's staff additional form stays as described in Events and forms) | after IVK registration opens |
| One mailbox per responsibility (finance, invoicing, contact, leadership) | the mail topic |
| Automatic evaluation of adjudicated-aid requests | later |
| Talk-group and room proposers | later |
| Attendance-sheet extension | later (REFISZ) |
| Portable event definitions: an event serializable with everything that belongs to it | later |
| AI help for building an event's configuration (e.g. an MCP server) | later |
| Year-to-year tracking add-on, built from preset links | later |
| Public after-save actions for third parties | when a third party needs them; at the earliest when alpha ends |
| Deprecation period for versioned extension points; the compatibility-check tool | when the alpha phase ends |
| The license and its `LICENSE` file | when the alpha phase ends (wide release to other organizations) |
| File upload | building forms |
| Absolute payment deadlines (removed 2026-11-05, [Money](money.md#core-money-scope)): how they interact with late call-ups from the waitlist | if an organization needs one |
| Fast entry of many manual payments, and how to make it safe | after IVK registration opens |
| Rate limit for self-sent mail | building the portal |
| Post-camp settlement extension | later |
| A view of the frozen submission | later, low priority |
| How the form editor edits conditions with pickers and value chips (conditions are written as JSON until then) | in the form-editor changes |
| Which key a form field copied from a field definition takes | building field definitions |
| Copying answers to another event's registration (e.g. "register for the other camp too") by matching keys | building that link |
| View columns taken from the participant-field list rather than from forms | building views |
| „Nincs válasz” in the grid's filters of text, number and date columns (only list-type columns that can be empty have it, as one more value of their own filter) | when DataViews supports an empty filter, or in a redesign of filtering |

## UI text approved when built

Nothing reaches the UI without the owner's approval. These texts and
presentations are known to be needed and are approved when their UI is built:

- the preset info boxes (saving a new preset, saving changes back);
- the soft notice for a second guest registration with the same email;
- the admin warning when an event's reference codes run out;
- the privacy policy text (IP address and user agent in the audit);
- how prefill is presented on a form;
- the form's validation messages and its messages when it is not open;
- the activation message when late registrants are excluded from a
  scheduled mail;
- view and grant editing: defining columns, filters and parameters, granting
  per event, and seeing who has access (this needs a good UX);
- form-link management;
- the congregation picker;
- attendance-sheet templates.
