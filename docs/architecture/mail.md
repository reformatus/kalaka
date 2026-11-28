---
title: Mail
---

# Mail

## Words

- A **mail** is one configured message (a **sending point** on the screen): its
  trigger settings plus at most one **template**. The template is a
  `kalaka_mail` post of the event; the screen calls it a sablon. A point holds
  at most one template and a template serves at most one point; an event may
  also hold templates no point uses. A template that is not published may be
  held, but only a published one lets the point send. [UNAPPROVED R320]
- A **delivery** is one row in the send log: one mail to one registration (or
  address) on one occurrence: pending, sending, sent or failed. A pending
  delivery may carry the error of an earlier attempt. **Only the sender
  decides on the deliveries table:** no decision, condition or view depends on
  whether a mail was sent. Staff reads of it, such as the counts and the
  listing of failed deliveries (`wp kalaka mail failed`), only show it.

## Five kinds of mail

Mail is grouped by what causes it. **Deciding that a mail is due always means
inserting a pending delivery** whose unique key (event, mail, registration,
occurrence) makes it once-only. **Every once-only mail is keyed by the record
that caused it:** the registration, the payment line, the confirmation
record, the check-in act, and so on.

| Kind | Caused by | Decided by |
|---|---|---|
| **Action** | an act on one registration: registered (admission from the waitlist sends this mail too; a restore sends the mail of the status it returns to, registered or waitlisted, once per restore), waitlisted, payment recorded (the participant's money in and money out, a payment or a refund, by hand or online, keyed by the line; never a correction; a „Díj” or „Kedvezmény” ticked „Azonnal befizetve” mails for its paid entry like any payment, the fee itself mails none), confirmed, answered, an extension's act such as a check-in or a wallet top-up | the use case, inside its own transaction; "confirmed" by the evaluation's mail step ([Foundation](foundation.md#evaluation)), keyed by the confirmation record, so every `confirmed` record mails once, a re-confirmation right after staff's unconfirm included; "answered" by the answering act, keyed by the answer record, so every answer mails, a changed one included; every check-in route calls the same check-in use case |
| **Scheduled** | a point on the event's calendar, for an audience (e.g. everyone `registered`) | the periodic run, one delivery per registration |
| **Reminder** | time passing while a condition holds; repeats | the periodic run, for each due repeat; keyed by the repeat number |
| **Manual** | staff send a chosen mail to chosen registrations, now or scheduled | the staff use case; keyed by the send batch |
| **Self-sent** | the registrant asks for it through a participant block (e.g. resend my link), addressed to an email address | the portal use case, rate-limited |

## Configuration

- **Mails belong to one event.** They are reused by copying a body from
  another mail; anything the new trigger does not support is imported but
  flagged as a validation error.
- **Action mails are defined in code.** Per event each one is switched on or
  off and has its own template.
- **The set-password link**
  ([Registrations](registrations-and-identity.md#accounts-on-the-registration-form))
  has no mail of its own. A body places it, as the key
  `account.password_link`, in a branch on `account.unverified`, which is yes
  while the registration's account is still unverified and the mail goes to
  that account's own address, no when that account is verified, and none for
  a guest, another recipient or an event without accounts. While an event's
  account mode is mandatory or optional, its "registered" mail must contain the key and cannot be switched
  off, and a change of an event's account mode to one with accounts is refused
  unless that mail is on and carries it
  ([Events](events-and-forms.md#forms-and-the-registration-slot)). WordPress keeps one such link per account, so each new one replaces
  the last.
- **Scheduled mails are defined freely by admins** at a date reference
  ([Events](events-and-forms.md#date-references)): a fixed date and time, a
  point on the event's timeline (e.g. 2 days before the start, 5 days after
  the end), or a date type that may differ per registration.
- **Reminders are defined in code** by their module or plugin (e.g. an
  upcoming late fee). Admins configure the time base and the repeat interval;
  repeating is supported from the start.
- **An event-wide layout** wraps mails, optional per mail template. There is no
  site-wide layout.

### Late registrants and activation

"Late" is measured against **the mail's own send time for that
registration**, never against registration close or any other date. A
registration is late for a scheduled mail when that send time had already
passed when it first came within the mail's reach, which happens in one of two
ways:

- it registered after the send time;
- the mail was activated after the send time.

**Each scheduled mail has one late-registrant switch** covering both cases:

- **on:** late registrations get the mail as soon as they come within its
  reach, at registration or at activation;
- **off:** they never get it.

This holds for mails at a fixed date and for mails at a date type. When an
admin activates a mail with the switch off and some registrations are already
past their send time, the activation shows how many, and tells the admin that
to send to them they can turn the switch on and deactivate and reactivate the
mail. The delivery's unique key stops anyone who already got it from getting
it twice; no manual follow-up mail is needed.

**A mail whose single fixed send time has already passed cannot be
activated.**

## Content

- **Every body is edited in the block editor** and stored as a block document
  (`kalaka_mail` posts). **Blocks in a mail body are content and
  conditions;** the layout is a raw HTML wrapper with one content
  placeholder.
- **Values and conditions** follow the shared rules
  ([Values and conditions](events-and-forms.md#values-and-conditions)). A
  mail's context is **the recipient, the registration and its event**
  (`event.*`), and for the „payment recorded” mail also the line that caused
  it (its amount, direction, method and day: the value date, or the day of the
  paid time for an online line); every other mail reads that line's keys as
  none. The registration's keys are **the event's participant fields**
  ([Fields](events-and-forms.md#fields)), not the fields of the form it
  came through; a field with no value for that registration reads as none,
  so a mail naming a field only one form holds still renders for everyone.
  A key that is not a participant field of the event stays an error. Actions and
  reminders add their own blocks (e.g. a payment table). Besides
  `registration.link`, a body may place a **page link** `page_<ID>.link`: the
  registrant's own secret link to that published page, declared only while the
  page is published. The money table and the
  transfer block are such blocks: they render through the status page's read at
  send time, so the mail and the page agree, in a look of their own. The same two
  can be typed by hand: a paragraph or an HTML block that holds only
  `{{money_table}}` or `{{transfer_details}}` is the money table or the transfer
  block. They are not values: typed anywhere else in a body they are unknown
  placeholders.
- **The server renders the whole mail,** conditions included, at send time
  ([Sending](#sending)).
- **The body's title is the subject,** as plain text. Mail is sent as HTML
  only.
- **The layout** is one raw HTML text per event, written by full access and
  stored as written. It holds `{{content}}` exactly once and no other
  placeholder; a layout without exactly one is refused. A new event's layout
  is exactly `{{content}}`, so mail works with no setup. The rendered body
  replaces `{{content}}`; everything but the outer design belongs in each
  mail's body. Typed into a body, `{{content}}` is an unknown placeholder.

## Sending

- **Mail is never sent during an interactive request.** Neither a frontend
  nor an admin request calls `wp_mail`, not even a manual mail: requests only
  insert pending deliveries, and the send log is the mail queue. Sending after
  commit in the request could add 1–3 seconds of SMTP time and hit shared
  hosts' limits.
- **One sender delivers every mail.** It runs:
  - from a **nudge**: after a commit that inserted deliveries, a one-off
    sender run is scheduled for now (`wp_schedule_single_event`);
  - from **itself**: when it stops with deliveries left, it nudges itself
    again rather than waiting;
  - on **every periodic run** (every 5 minutes; a real system cron, with
    WP-Cron as the fallback: [Foundation](foundation.md#deferred-work)).
- **A delivery is claimed before sending:** the sender moves it from pending
  to "sending" before calling `wp_mail`, so overlapping sender runs never send
  the same delivery at the same time. After a successful attempt it is marked
  sent; after an error it goes back to pending ([Failures](#failures)).
- **The sender checks the pause before each claim,** reading it fresh rather
  than from a per-request cache, so a pause set by one run stops an
  overlapping run after at most the attempt it has in hand.
- **The address is checked before the claim** with `is_email()`. A delivery
  without a valid address is marked failed with that error and no attempt is
  made; it does not count as an error for pausing, because retrying cannot
  fix it.
- **The mail is rendered before the claim too.** A body that cannot render
  for its own reason (no body, a body removed, unpublished or moved, a key
  the mail does not have, an invalid condition)
  fails the same way: marked failed with that error, no attempt, not counted
  for pausing.
- **It renders the body at send time from current data** and records the
  mail's version and send time, not the content. The content can be recovered
  by re-rendering, and may be incomplete after a privacy erase. Exact copies
  are left to a mail-logging plugin. There is no archive copy.
- **Throttle:** an **hourly budget**, a site setting with a default of 240 per
  hour (today's 20 per 5-minute run). Each run sends at most its share of
  what is left. Attempts that end in an error count against it.
  - **The share:** the hour is the UTC clock hour in twelve 5-minute slots. A
    run may claim `ceil(L / S) − C`: L is the budget less the hour's claims
    before the current slot, S the slots left counting the current one, C the
    claims already made in it. A run that uses up its share nudges itself for
    the next slot.
  - **A run stops claiming after 20 seconds** and nudges itself at once, so it
    stays inside a shared host's `max_execution_time`.
  - **Only one sender runs at a time** (a named database lock); a run that
    finds another one running ends at once.
- **Order:** self-sent and action mails first, then manual mails, reminders
  and scheduled mails.

### Failures

An **error** is any failure of an attempt, recorded after the claim:
`wp_mail` returns false, `wp_mail_failed` fires, or rendering the mail throws
for any reason other than the body's own ([Sending](#sending)). Delivery itself is never
known, only whether an error was reported; a reported error may still have
been delivered, so a retry may send a second copy. The rule bounds that: a
mail is never sent automatically more than twice.

- **On an error the run stops.** The delivery goes back to pending, keeping
  its error and an attempt count. No other delivery is touched.
- **The next run tries that delivery first,** before the usual order. The
  wait until the next run (at most 5 minutes; no nudge) gives a passing error
  at the mail provider time to clear.
- **Two errors in a row pause sending** ([Pausing](#pausing)). Because the
  failed delivery is always tried next, its two errors are always in a row: a
  delivery that fails for its own reason cannot be retried run after run. It
  stays pending, so it is never removed from the queue.
- **The count of errors in a row is reset only by a successful attempt,** not
  by resuming. After a resume the first attempt is the same delivery; if it
  fails again, sending pauses at once. Each resume costs at most one more
  attempt.
- **A crash** leaves the delivery in "sending". Whether it was sent is
  unknown, so it is not retried. It is rare, which is acceptable.
- **Staff see which deliveries carry an error, failed, or were left in
  "sending",** and can send a chosen mail to chosen registrations. This is
  the ordinary manual mail, not a retry feature.

## Pausing

- **The mail engine can be paused:** deliveries pile up, and an admin can
  clear the queue.
- **One pause, two causes.** An admin pauses by hand, or the sender pauses
  after two errors in a row ([Failures](#failures)). Both set the same pause.
  It records its cause, who set it (a user, or the system) and when, and for
  a pause after errors the delivery's key and error, so the admin warning
  never reads the deliveries table.
- **Only a person resumes sending,** with the same control in either case.
  Nothing resumes automatically: there is no way to check the mail provider
  without sending.
- **Pausing and resuming are use cases** and are audited like any other
  ([Foundation](foundation.md#audit)): the actor is the user, or the system
  for a pause after errors, and a pause after errors names the delivery as
  its subject.
- **Unpublishing an event** is a separate action, placed next to the pause in
  the UI. It stops the event's sending, and no scheduled or reminder
  deliveries are added to the queue while it is unpublished, so republishing
  does not release outdated mails. Action mails are still queued, so
  none is forgotten. The event's participant blocks, including "resend my
  link", are closed;
  self-sent mails requested before unpublishing stay queued. The admin sees the pending count with the
  option to clear them ([Events](events-and-forms.md#event-lifecycle)).
