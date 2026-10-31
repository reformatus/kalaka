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
| Participant phase tracker (core default phases, configurable, extensions add phases); participants see one sequence: waitlisted → awaiting payment → confirmed, or withdrawn / removed | designing the portal's status page |
| Inline value syntax in mails | building the mail editor |
| Registration flow when an account is involved (inline or before the form); minimum age for holding an account | building the portal and registration UI |
| Retention of guest data and of the audit, including failed entries | before the first production deployment (privacy policy) |
| The list of per-event actions and their default bundles | before the admin UI |
| Which money plugins come first for REFISZ's summer camps (IVK's and Téli Tali's are in the [release plan](release-plan.md)) | before the summer camps' registration opens |
| The payment deadline of a registration admitted from the waitlist | with the payment-due date types |
| The wallet: separate from the fee (a top-up never pays it, a fee debt never enters the wallet); one per registration; no spending below the balance; top-ups by card or cash at the buffet; carry-over between events of the organization for every payer; moving overpayments into wallets in one click; settlement thresholds per event; what the participant sees | designed in December 2026, built before Téli Tali (2027-02-03, [Release plan](release-plan.md)) |
| Reserved places per partner, e.g. as a per-link quota | after IVK registration opens |
| IVK's staff and volunteer data form, for staff who are not participants (REFISZ's staff additional form stays as described in Events and forms) | after IVK registration opens |
| One mailbox per responsibility (finance, invoicing, contact, leadership) | the mail topic |
| Automatic evaluation of adjudicated-aid requests | later |
| Talk-group and room proposers | later |
| Attendance-sheet extension | later (REFISZ) |
| Portable event definitions: an event serializable with everything that belongs to it | later |
| AI help for building an event's configuration (e.g. an MCP server) | later |
| Where extensions register REST routes | the first extension that needs one |
| Year-to-year tracking add-on, built from preset links | later |
| Public after-save actions for third parties | when a third party needs them; at the earliest when alpha ends |
| Deprecation period for versioned extension points; the compatibility-check tool | when the alpha phase ends |
| The license and its `LICENSE` file | when the alpha phase ends (wide release to other organizations) |
| Spam protection, including a rate limit on the module recommender | before IVK registration opens (2026-11-30) |
| File upload | building forms |
| Rounding payment requests to the currency's payable unit (whole forints for HUF, below one fillér counts as zero): follow WooCommerce's own method if it has one | at implementation |
| Rate limit for self-sent mail | building the portal |
| Post-camp settlement extension | later |
| A view of the frozen submission | later, low priority |

## UI text approved when built

Nothing reaches the UI without the owner's approval. These texts and
presentations are known to be needed and are approved when their UI is built:

- the preset info boxes (saving a new preset, saving changes back);
- the soft notice for a second guest registration with the same email;
- the admin warning when an event's reference codes run out;
- the privacy policy text (IP address and user agent in the audit);
- the admin notice for an extension whose version is out of range;
- the admin notice for a failed schema upgrade;
- how prefill is presented on a form;
- the activation message when late registrants are excluded from a
  scheduled mail;
- view and grant editing: defining columns, filters and parameters, granting
  per event, and seeing who has access (this needs a good UX);
- form-link management;
- the congregation picker;
- the module recommender page and its consent notice, which names the AI
  provider and asks people not to include personal data in open answers;
- attendance-sheet templates.
