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
| Participant phase tracker (core default phases, configurable, extensions add phases) | designing the portal's status page |
| Inline value syntax in mails | building the mail editor |
| Registration flow when an account is involved (inline or before the form); minimum age for holding an account | building the portal and registration UI |
| Retention of guest data and of the audit, including failed entries | before the first production deployment (privacy policy) |
| The role set: which roles core ships and their capabilities | before the admin UI |
| Which money plugins come first (the price ladder is needed for IVK) | product scope, before the first season on Kaláka |
| The coupon-covered part of a WooCommerce order as a wallet ledger line | designing the WooCommerce and wallet extensions |
| Where extensions register REST routes | the first extension that needs one |
| Manual requeue of action mails that should have been sent | after the first version |
| Year-to-year tracking add-on, built from preset links | later |
| Public after-save actions for third parties | when a third party needs them; at the earliest when alpha ends |
| Deprecation period for versioned extension points; the compatibility-check tool | when the alpha phase ends |
| Spam protection; file upload | building forms |
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
- the admin notice for an extension that is out of range or failed;
- how prefill is presented on a form;
- the activation message when late registrants are excluded from a
  scheduled mail.
