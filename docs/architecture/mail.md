---
title: Mail
---

# Mail

Mail is decided inside the act that causes it, queued as a delivery, and sent
later by one sender. Nothing a person does waits for SMTP, and nothing is
ever sent twice by accident.

## Words

- A **mail** is one configured message (a **sending point** on the screen):
  its trigger settings plus at most one **template**. The template is a
  `kalaka_mail` post of the event; the screen calls it a sablon. A point holds
  at most one template and a template serves at most one point; an event may
  also hold templates no point uses. A template that is not published may be
  held, but only a published one lets the point send.
- A **delivery** is one row in the send log: one mail to one registration (or
  address) on one occurrence: pending, sending, sent or failed. A pending
  delivery may carry the error of an earlier attempt. **Only the sender
  decides on the deliveries table:** no decision, condition or view depends on
  whether a mail was sent. Staff reads of it, such as the counts and the list
  of failed deliveries, only show it.

## Five kinds of mail

Mail is grouped by what causes it. **Deciding that a mail is due always means
inserting a pending delivery** whose unique key (event, mail, registration,
cause) makes it once-only. **Every once-only mail is keyed by the record that
caused it:** the registration, the payment line, the confirmation record, the
answer record, the send batch, and so on.

| Kind | Caused by | Decided by |
|---|---|---|
| **Action** | an act on one registration (below) | the act's use case, inside its own transaction; "confirmed" by the evaluation's mail step ([Foundation](foundation.md#evaluation)) |
| **Scheduled** | a point on the event's calendar, for an audience (e.g. everyone `registered`) | the periodic run, one delivery per registration. Not built yet |
| **Reminder** | time passing while a condition holds; repeats | the periodic run, for each due repeat, keyed by the repeat number. Not built yet |
| **Manual** | staff send a chosen template to chosen registrations | the staff use case, one send batch; keyed by the batch |
| **Self-sent** | the registrant asks for it through a participant block (e.g. resend my link), addressed to an email address | the portal use case, rate-limited. Not built yet |

**The action mails on `main`:**

- **registered:** at submit; an admission from the waitlist sends it too (a
  template can tell the two apart); a restore sends the mail of the status it
  returns to, once per restore;
- **waitlisted:** at submit, or a restore to the waitlist;
- **payment recorded:** the participant's money in and out, a payment or a
  refund, by hand or online, keyed by the line. Never a correction, never a
  fee itself, never another payer's line; a „Díj” or „Kedvezmény” recorded as
  paid at once mails for its paid entry like any payment;
- **confirmed:** every `confirmed` record mails once, a re-confirmation right
  after staff's unconfirm included;
- **answered:** every answering act mails once, a changed answer included.

An extension's act (a check-in, a wallet top-up) is foreseen to cause an
action mail through its own point. **While a registration is waitlisted, only
the waitlist mail is queued for it;** a mail held so is not sent later.
Withdrawal and removal send none. An additional form's submit sends none.

**Manual mail:**

- **A manual mail is one send batch:** a published template of the event and
  the registrations it goes to, listed by code or chosen as "every row the
  grid shows" in a view the sender holds (resolved again at each send, so it
  follows the data). It is a grant action, so a grant holder may send it to
  the registrations in their view.
- **Only `registered` registrations get it;** the others are listed as
  skipped in the result. One batch reaches a registration once.
- **Queuing is one act,** with a preview that shows what would be queued and
  skipped. A paused site or an unpublished event still queues: the deliveries
  wait, and the result says so.

**What follows:** to add a mail, add a cause (an act or a record) and key the
delivery by it; never check the deliveries table to see whether a mail went.
A repeat of the same cause sends nothing, so an act that may run twice is
safe.

## Configuration

- **Mails belong to one event.** They are meant to be reused by copying a
  template from another mail; anything the new trigger does not support is
  imported but flagged as a validation error (not built yet).
- **Action mails are defined in code.** Per event each one is switched on or
  off and has its own template. Assigning or swapping a template switches the
  point off; a point cannot be switched on with an unpublished template, and a
  template a switched-on point uses cannot be unpublished, trashed or moved.
- **The set-password link**
  ([Registrations](registrations-and-identity.md#accounts-on-the-registration-form))
  has no mail of its own. A template places it, as the key
  `account.password_link`, in a branch on `account.unverified`, which is yes
  while the registration's account is unverified and the mail goes to that
  account's own address, no when it is verified, and none for a guest,
  another recipient or an event without accounts. **While an event has
  accounts, its "registered" mail must contain the key and cannot be switched
  off,** and a change of an event's account mode to one with accounts is
  refused unless that mail is on and carries it
  ([Events](events-and-forms.md#forms-implementation)). WordPress keeps one
  such link per account, so each new one replaces the last.
- **Scheduled mails are defined freely by admins** at a date reference
  ([Events](events-and-forms.md#date-references)): a fixed date and time, a
  point on the event's timeline (e.g. 2 days before the start), or a date type
  that may differ per registration.
- **Reminders are defined in code** by their module or plugin (e.g. an
  upcoming late fee). Admins configure the time base and the repeat interval.
- **An event-wide layout** wraps mails; each template says whether it uses
  it. There is no site-wide layout.

### Late registrants and activation

Not built yet, with scheduled mails.

"Late" is measured against **the mail's own send time for that
registration**, never against registration close or any other date. A
registration is late for a scheduled mail when that send time had already
passed when it first came within the mail's reach: it registered after the
send time, or the mail was activated after it.

**Each scheduled mail has one late-registrant switch** covering both cases:
on, late registrations get the mail as soon as they come within its reach;
off, they never get it. When an admin activates a mail with the switch off
and some registrations are already past their send time, the activation shows
how many, and tells the admin that to send to them they can turn the switch
on and deactivate and reactivate the mail. The delivery's unique key stops
anyone who already got it from getting it twice.

**A mail whose single fixed send time has already passed cannot be
activated.**

## Content

- **Every template is edited in the block editor** and stored as a block
  document (`kalaka_mail` posts), by full access only. **Blocks in a template
  are content and conditions,** never form fields. The layout is a raw HTML
  wrapper with one content placeholder.
- **Values and conditions** follow the shared rules
  ([Values and conditions](events-and-forms.md#values-and-conditions)). A
  mail's context is **the recipient, the registration and its event**
  (`event.*`), and for the „payment recorded” mail also the line that caused
  it (its amount, direction, method and day); every other mail reads those
  keys as none. **The registration's keys are the event's participant
  fields,** not the fields of the form it came through; a field with no value
  reads as none, so a mail naming a field only one form holds still renders
  for everyone. A key that is not in the context stays an error: it is
  refused at save, and a mail whose template went stale fails at send.
- **Links:** besides `registration.link`, a template may place a **page link**
  `page_<ID>.link`, the registrant's own secret link to that published page.
  The link is never stored on a delivery, logged or audited.
- **The money table and the transfer block** are blocks only a template's
  editor offers (or typed alone in a paragraph as `{{money_table}}` and
  `{{transfer_details}}`). They render through the status page's own read at
  send time, so the mail and the page agree, in a look of their own, and show
  nothing for a waitlisted registration.
- **The server renders the whole mail,** conditions included, at send time
  ([Sending](#sending)). **The template's title is the subject,** as plain
  text. Mail is sent as HTML only.
- **The layout** is one raw HTML text per event, written by full access and
  stored as written. It holds `{{content}}` exactly once and no other
  placeholder. A new event's layout is exactly `{{content}}`, so mail works
  with no setup. Everything but the outer design belongs in each template.

**What follows:** a mail always shows today's data, not the data at the time
it was decided; a mail decided at registration and sent five minutes later
already shows a payment made meanwhile. A template edit reaches every mail not
yet sent.

## Sending

- **Mail is never sent during an interactive request.** Neither a frontend
  nor an admin request calls `wp_mail`, not even a manual mail: requests only
  insert pending deliveries, and the send log is the mail queue. Sending in the
  request could add 1–3 seconds of SMTP time and hit shared hosts' limits.
- **One sender delivers every mail.** It runs from a **nudge** after a commit
  that inserted deliveries, from **itself** when it stops with deliveries
  left, and on **every periodic run** ([Foundation](foundation.md#deferred-work)).
  **Only one sender runs at a time** (a named database lock).
- **A delivery is claimed before sending:** the sender moves it from pending
  to "sending" before calling `wp_mail`, so overlapping runs never send the
  same delivery. After a successful attempt it is marked sent; after an error
  it goes back to pending ([Failures](#failures)).
- **The sender checks the pause before each claim,** read fresh, so a pause
  set by one run stops an overlapping run after at most the attempt in hand.
  It also holds the mail of an unpublished event.
- **Problems of the delivery itself fail it without an attempt:** an invalid
  or missing address, or a template that cannot render for its own reason (no
  template, one removed, unpublished or moved, a key the mail does not have,
  an invalid condition). Such a delivery is marked failed with that error and
  does not count as an error for pausing, because retrying cannot fix it.
- **It renders the template at send time from current data** and records the
  mail's version and send time, not the content. There is no archive copy;
  exact copies are left to a mail-logging plugin.
- **Throttle:** an **hourly budget**, a site setting with a default of 240 per
  hour (today's 20 per 5-minute run), spread over the hour's 5-minute slots
  so one run cannot spend it all. Attempts that end in an error count against
  it. **A run stops claiming after 20 seconds** and nudges itself, so it stays
  inside a shared host's `max_execution_time`.
- **Order:** self-sent and action mails first, then manual mails, reminders
  and scheduled mails.

### Failures

An **error** is any failure of an attempt, recorded after the claim:
`wp_mail` returns false, `wp_mail_failed` fires, or rendering throws for any
reason other than the template's own. **Delivery itself is never known,** only
whether an error was reported; a reported error may still have been delivered,
so a retry may send a second copy. The rule bounds that: **a mail is never
sent automatically more than twice.**

- **On an error the run stops.** The delivery goes back to pending, keeping
  its error and an attempt count.
- **The next run tries that delivery first,** after a wait of at most 5
  minutes with no nudge, which gives a passing error at the mail provider time
  to clear.
- **Two errors in a row pause sending** ([Pausing](#pausing)). Because the
  failed delivery is always tried next, its two errors are always in a row.
  It stays pending, so it is never removed from the queue.
- **The count of errors in a row is reset only by a successful attempt,** not
  by resuming: each resume costs at most one more attempt.
- **A crash** leaves the delivery in "sending". Whether it was sent is
  unknown, so it is not retried. It is rare, which is acceptable.
- **Staff see which deliveries carry an error, failed, or were left in
  "sending",** by registration code and name, never by address, and can send
  a chosen template to chosen registrations. This is the ordinary manual
  mail, not a retry feature.

**What follows:** the site's mail provider must report errors to WordPress
(e.g. no silent fallback connection), or the pause never triggers and a
failing provider silently drops mail.

## Pausing

- **The mail engine can be paused:** deliveries pile up, and an admin can
  clear the queue.
- **One pause, two causes.** An admin pauses by hand, or the sender pauses
  after two errors in a row ([Failures](#failures)). Both set the same pause,
  which records its cause, who set it and when, and for a pause after errors
  the delivery and its error, so the admin warning never reads the deliveries
  table.
- **Only a person resumes sending,** with the same control in either case.
  Nothing resumes automatically: there is no way to check the mail provider
  without sending.
- **Pausing and resuming are use cases** and are audited like any other
  ([Foundation](foundation.md#audit)).
- **Unpublishing an event** is a separate action, placed next to the pause in
  the UI. It stops the event's sending, and no scheduled or reminder
  deliveries are added while it is unpublished, so republishing does not
  release outdated mails. Action and manual mails are still queued, so none is
  forgotten; the admin sees the pending count with the option to clear them
  ([Events](events-and-forms.md#event-lifecycle)).
