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
   protection against double submits; spam protection; an optional account
   created on the form, offered to people aged 16 or over and activated
   through a set-password mail sent by the queue (the portal itself may only
   say "Coming soon").
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
   sender with its throttle and sending window; staff see which deliveries
   failed and can send a chosen mail to chosen registrations. Mail is never
   sent during an interactive request.
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
   system cron verified on IVK's host, with WP-Cron as the fallback, a
   warning when the last run is older than about 15 minutes, and a lock
   against overlapping runs; a backup and a restore drill.

### Order of sacrifice

If the date is at risk, these give way, in this order:

1. **Card payment through WooCommerce:** bank transfer only on opening day,
   card payment in early December.
2. **Partner self-service views:** staff record confirmations meanwhile
   (item 4).
3. **The Evangélikus and Akadémia support offers,** which also wait on
   stakeholder question SQ-5.

### Not in this release

Presets; the portal beyond "Coming soon", including its editable fields;
erasure and the other GDPR self-service features (nice to have, may follow);
the visual form builder; the allocation proposer (needed before IVK's
registration closes; it runs once, on module choices frozen beforehand); the
module letter; late fees and
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

## REFISZ: Téli Tali, opening before Christmas 2026

REFISZ's winter conference, 2027-02-03 to 02-05, about 200–250 people. It
works like a summer camp with smaller amounts. Registration opens as early as
feasible; the date is not fixed. It builds on everything in IVK's release.

### Must exist on opening day

1. **One registration form** for one event.
2. **Card payment through WooCommerce** on REFISZ's store; it is not
   sacrificed for REFISZ.
3. **Money as at the summer camps, with smaller amounts:** the price ladder
   with its tiers and any tier quotas (places held at registration, reassigned
   only by staff); the deposit as the confirmation threshold, and a deposit
   payment request of the threshold minus what has been paid, capped at the
   remaining balance; a donation and an invoice request.
4. **Mails as at the summer camps:** application received, waitlisted,
   confirmed and fully paid. Someone who pays everything at once gets only the
   fully-paid mail; if that needs real extra logic, both are sent, as today.
5. **Operations on REFISZ's host:** as for IVK (item 9).

### Must exist within about ten days of opening

Payment is due 14 days after registering, and the deposit reminder goes out
after 11 days, so these must exist before the first registrants reach them:

6. **The deposit reminder,** 11 days after registering while unconfirmed.
7. **The late fee** when the payment deadline passes while unconfirmed, with
   the value-date rule. It is charged only on the normal and discounted
   tiers: people on a higher tier have paid it in advance, and staff never
   pay it. Which tiers these are is confirmed with the amounts.
8. **The staff form** with its discount bands, before the first deadline,
   because it decides who is staff.
9. **Retention on withdrawal:** keep up to the deposit.

### Must exist in January

10. **Extra sign-ups** (choices inside the event) and their mails.

### Must exist before the conference (2027-02-03)

11. **The wallet:** buffet top-ups and spending at the event.
12. **The desk:** check-in, the arrival mail on any check-in route, cash
    recorded at the desk.
13. **Practical-information and post-event mails,** as at the summer camps.

### Nice to have

- **The capacity exemption** for children under 13 who come with a parent.
- **Support requests decided by staff,** reusing IVK's pending decisions.

### Order of sacrifice for opening day

1. **The invoice request:** requests go to the bookkeeper by e-mail.
2. **The donation field.**

## REFISZ: summer camps (FF and K)

To be agreed before their registration opens. The starting point: two events,
each with its own registration form; "register for the other camp too" from
the thank-you page as a single-use link copying the first registration's
answers, each camp paid separately; the capacity exemption for children under
13; the Támogatott tier quotas (FF 13, K 80); a deposit per camp (FF 10 000 Ft,
K 20 000 Ft); K's extra-support request as a pending decision with the payment
hold; late fees (FF 5 000 Ft, K 7 000 Ft); direct-pick program sets, groups and
lodging; file upload and the moral-certificate status; partner views;
attendance sheets.
