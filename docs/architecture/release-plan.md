---
title: Release plan
---

# Release plan

What each release must contain, and what gives way first when time runs
short. The architecture pages say how things are built; this page says when.

## IVK: registration opens 2026-11-30

### Must exist on opening day

1. **Registering:** an event with its dates and capacity; IVK's registration
   form, authored by hand as block markup; submit with capacity, the "full"
   latch and the waitlist; reference codes; the secret-link status page;
   protection against double submits; spam protection.
2. **Money core:** the ledger with payers, the tariff snapshot and the
   materializer; IVK's price tiers, including Nógrád's fixed tier; donation,
   lunch, the invoice request and billing fields, with an "invoiceable" flag
   on components; the payment-due date with its anchors; confirmation per
   payer; recording a payment by hand.
3. **Congregation share** ([NR-1](money.md#congregation-share)): the
   organization directory, the congregation picker and the share pair.
4. **Support and decisions:** support offers with quota places (Baptist,
   Dunamellék); pending decisions with the payment hold (discount or aid
   requests, partner confirmation). Until partners have their own views, IVK
   staff record a partner's confirmation on its behalf, audited with a
   reason.
5. **Mail:** IVK's mails 1, 1O, 1V and 2 and the other action mails; the
   sender with its throttle, sending window and automatic stop after repeated
   failures.
6. **Organizer side:** full access; the master view with the grid, the detail
   page, field editing, and exports that write an audit entry.
7. **Form links:** locked and editable values, single use and expiry. Partner
   links and the recommender depend on them.
8. **Module recommender** ([NR-2](modules-and-extensions.md#extensions-in-view)):
   the page; the scorer behind one interface; the form link created by a
   portal or system use case; a rate limit and the consent notice. Before
   go-live: the data-processing agreement and the API key, placed by the
   owner.
9. **Operations:** deployment to the IVK site and to a staging copy; a real
   system cron verified on IVK's host; a backup and a restore drill.

### Order of sacrifice

If the date is at risk, these give way, in this order:

1. **Card payment through WooCommerce:** bank transfer only on opening day,
   card payment in early December.
2. **Partner self-service views:** staff record confirmations meanwhile
   (item 4).
3. **The Evangélikus and Akadémia support offers,** which also wait on
   stakeholder question SQ-5.

### Not in this release

Accounts and presets; the visual form builder; the allocation proposer (needed
before IVK's registration closes); the module letter; late fees and
reminders; starting a WooCommerce refund from Kaláka (refunds made in
WooCommerce are recorded from day one); substitution; the wallet; attendance
sheets.

### Gated outside the code

- **SQ-3** (support versus congregation share order) fixes the share formula.
- **SQ-4** (which survey questions the recommender replaces) fixes the form.
- **The scorer:** its evaluation, the data-processing agreement and the API
  key.
- **SQ-2** (what the bookkeeper's views contain) may follow opening by a few
  days, while payments are still few.

## REFISZ: before Christmas 2026

To be agreed.
