<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Mail;

use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\ObjectCache;
use Kalaka\Mail\Api\ActionMail;
use Kalaka\Mail\Catalogue\ActionMailEntry;
use Kalaka\Mail\Catalogue\MailBody;
use Kalaka\Mail\Catalogue\UpdateActionMail;
use Kalaka\Mail\Pause\PauseMail;

/** The typed refusals of the catalogue as the runner gives them, and `UpdateActionMail::refuse()`, the one check. */
final class MailRefusalCodesTest extends MailBodyTestCase
{
    private function codeOf(callable $call): string
    {
        try {
            $call();
        } catch (RuleViolation $refused) {
            return $refused->errorCode();
        }
        self::fail('Not refused.');
    }

    public function testEachRefusalOfTheUpdateAndTheLayoutHasItsOwnCode(): void
    {
        global $wpdb;
        $event = $this->published();
        $body = $this->document($event, $this->paragraph('Szia'));
        $second = $this->document($event, $this->paragraph('Más'), 'Másik');

        self::assertSame('kalaka_mail_no_body', $this->codeOf(
            fn () => $this->updateMail(['event_id' => $event, 'mail' => 'waitlisted', 'enabled' => true])
        ));
        $this->updateMail(['event_id' => $event, 'mail' => 'waitlisted', 'body_id' => $body]);
        self::assertSame('kalaka_mail_body_taken', $this->codeOf(
            fn () => $this->updateMail(['event_id' => $event, 'mail' => 'confirmed', 'body_id' => $body])
        ));
        // A body belongs to one mail of the event: assigning it to another is refused while that mail is off too.
        self::assertSame('kalaka_mail_body_taken', $this->codeOf(
            fn () => $this->updateMail(
                ['event_id' => $event, 'mail' => 'confirmed', 'body_id' => $body, 'enabled' => false]
            )
        ));
        self::assertSame('kalaka_mail_layout_placeholder', $this->codeOf(
            fn () => $this->setLayout($event, '<p>nincs</p>')
        ));
        self::assertSame('kalaka_mail_layout_other_placeholder', $this->codeOf(
            fn () => $this->setLayout($event, '{{content}}{{egyeb}}')
        ));

        $wpdb->update($wpdb->prefix . 'kalaka_events', ['account_mode' => 'mandatory'], ['id' => $event]);
        ObjectCache::clear();
        self::assertSame('kalaka_mail_needs_password_link', $this->codeOf(
            fn () => $this->switchOn($event, 'registered', $second)
        ));
        $withLink = $this->document(
            $event,
            $this->paragraph('Jelszó: <span data-kalaka-value="account.password_link">link</span>'),
            'Linkes'
        );
        $this->switchOn($event, 'registered', $withLink);
        self::assertSame('kalaka_mail_registered_stays_on', $this->codeOf(
            fn () => $this->updateMail(['event_id' => $event, 'mail' => 'registered', 'enabled' => false])
        ));
    }

    public function testTheCheckIsOneFunctionThatNeedsTheFactsOfTheBodyItJudges(): void
    {
        $event = (new Events())->find($this->published());
        self::assertNotNull($event);
        $after = new ActionMailEntry($event->id, ActionMail::Waitlisted, true, 41);
        $facts = new MailBody(41, $event->id, true);

        // Passes with the facts; a layout switch of a mail that is on needs none.
        UpdateActionMail::refuse($event, ActionMail::Waitlisted, $after, 41, true, null, []);
        UpdateActionMail::refuse($event, ActionMail::Waitlisted, $after, null, false, $facts, []);
        self::assertSame(
            'kalaka_mail_body_taken',
            $this->codeOf(fn () => UpdateActionMail::refuse(
                $event,
                ActionMail::Waitlisted,
                $after,
                null,
                true,
                $facts,
                [new ActionMailEntry($event->id, ActionMail::Confirmed, false, 41)]
            ))
        );
        // Judged only when the body changes: a mail already on a body shared with another switches off and flips
        // its layout without the refusal (N564); the stored state cannot hold it (the body column is unique),
        // so the check is given such a state directly.
        $shared = [new ActionMailEntry($event->id, ActionMail::Confirmed, false, 41)];
        $off = new ActionMailEntry($event->id, ActionMail::Waitlisted, false, 41);
        $flipped = new ActionMailEntry($event->id, ActionMail::Waitlisted, true, 41, false);
        UpdateActionMail::refuse($event, ActionMail::Waitlisted, $off, 41, true, null, $shared);
        UpdateActionMail::refuse($event, ActionMail::Waitlisted, $flipped, 41, true, null, $shared);
        // A body newly given with no facts read is the caller's programming error, never a refusal.
        $this->expectException(SystemFailure::class);
        UpdateActionMail::refuse($event, ActionMail::Waitlisted, $after, null, false, null, []);
    }

    public function testThePausePassesThroughTheWidenedSiteStats(): void
    {
        $this->exec(new PauseMail(), []);
        $this->storeState(['errors_in_row' => 1, 'hourly_budget' => 77, 'paused_at' => '2026-11-30 09:00:30.000000',
            'cause' => 'errors', 'delivery_id' => 5, 'delivery_event_id' => 3, 'error' => 'send x: y']);

        $site = (new \Kalaka\Mail\Api\MailStats())->site();

        self::assertSame([1, 77], [$site->errorsInRow, $site->hourlyBudget]);
        self::assertNotNull($site->paused);
        self::assertSame([5, 3, 'send x: y'], [
            $site->paused->deliveryId, $site->paused->deliveryEventId, $site->paused->error,
        ]);
    }
}
