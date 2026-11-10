<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Mail;

use Kalaka\Foundation\Api\LoadFailure;
use Kalaka\Foundation\Api\LoadState;
use Kalaka\Mail\Api\MailKind;
use Kalaka\Mail\SendReport;
use Kalaka\Mail\Stopwatch;

/** The sender: what it sends, in which order, how much, and when it does not run. mail.md, "Sending". */
final class SenderTest extends MailTestCase
{
    public function testWithoutAComposerTheSenderClaimsNothingAndDeliveriesStayPending(): void
    {
        $event = $this->published();
        $this->queue($event);

        self::assertNull($this->sender(withComposer: false)->send());

        self::assertSame('pending', $this->deliveries()[0]['status']);
        self::assertSame('0', $this->deliveries()[0]['attempts']);
        self::assertSame([], $this->mailed);
        self::assertSame([], $this->entriesOf('kalaka/claim-delivery'));
    }

    public function testAPendingDeliveryOfAPublishedEventIsClaimedSentAndRecorded(): void
    {
        $event = $this->published();
        $this->queue($event);

        $report = $this->sender()->send();

        self::assertEquals(new SendReport(1), $report);
        self::assertSame(['teszt@example.test'], $this->mailed);
        $row = $this->deliveries()[0];
        self::assertSame('sent', $row['status']);
        self::assertSame('1', $row['attempts']);
        self::assertSame('2026-11-30 09:00:30.000000', $row['claimed_at']);
        self::assertSame('2026-11-30 09:00:30.000000', $row['sent_at']);
        self::assertSame('v1', $row['mail_version']);
        self::assertNull($row['error']);
        self::assertCount(1, $this->entriesOf('kalaka/claim-delivery'));
        self::assertCount(1, $this->entriesOf('kalaka/record-delivery'));
        self::assertSame('system', $this->entriesOf('kalaka/claim-delivery')[0]['actor_type']);
    }

    public function testOnlyPublishedEventsAreSent(): void
    {
        $published = $this->published();
        $unpublished = $this->unpublished();
        $this->queue($unpublished, '00A0');
        $this->queue($published, '00B0');

        $this->sender()->send();

        self::assertSame(['pending', 'sent'], array_column($this->deliveries(), 'status'));
    }

    public function testTheRetriedDeliveryComesFirstThenSelfSentAndActionThenManualReminderAndScheduled(): void
    {
        $event = $this->published();
        $this->queue($event, '00A0', MailKind::Scheduled);
        $this->queue($event, '00B0', MailKind::Reminder);
        $this->queue($event, '00C0', MailKind::Manual);
        $this->queue($event, '00D0', MailKind::Action);
        $this->queue($event, '00E0', MailKind::SelfSent);
        $this->queue($event, '00F0', MailKind::Scheduled);
        [$scheduled, $reminder, $manual, $action, $selfSent, $retried] = $this->deliveryIds();
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'kalaka_deliveries', ['attempts' => 1, 'error' => 'send x'], ['id' => $retried]);

        $this->sender()->send();

        self::assertSame([$retried, $action, $selfSent, $manual, $reminder, $scheduled], $this->composer->composed);
    }

    public function testAnInvalidAddressIsMarkedFailedWithoutAnAttemptAndTheRunGoesOn(): void
    {
        $event = $this->published();
        $this->queue($event, '00A0');
        $this->queue($event, '00B0');
        $this->queue($event, '00C0');
        [$noAddress, $bad, $good] = $this->deliveryIds();
        $this->composer->addresses = [$noAddress => null, $bad => 'nem-cím'];

        $report = $this->sender()->send();

        self::assertEquals(new SendReport(1, 0, 2), $report);
        self::assertSame(['failed', 'failed', 'sent'], array_column($this->deliveries(), 'status'));
        self::assertSame(['0', '0', '1'], array_column($this->deliveries(), 'attempts'));
        self::assertNull($this->delivery($bad)['claimed_at']);
        self::assertStringStartsWith('address invalid_address (correlation ', (string) $this->delivery($bad)['error']);
        self::assertSame([$good], $this->composer->composed);
        $errorsInRow = (int) ($this->stateRow()['errors_in_row'] ?? 0);
        self::assertSame(0, $errorsInRow, 'An invalid address is no error for pausing.');
    }

    public function testADeliveryClearedBetweenTheReadAndTheClaimIsSkipped(): void
    {
        $event = $this->published();
        $this->queue($event, '00A0');
        $this->queue($event, '00B0');
        [$cleared, $other] = $this->deliveryIds();
        $this->composer->onRecipient = static function ($delivery) use ($cleared): void {
            if ($delivery->id === $cleared) {
                global $wpdb;
                $wpdb->delete($wpdb->prefix . 'kalaka_deliveries', ['id' => $cleared]);
            }
        };

        $report = $this->sender()->send();

        self::assertEquals(new SendReport(1), $report);
        self::assertSame([$other], $this->deliveryIds());
        self::assertSame('sent', $this->delivery($other)['status']);
    }

    public function testARunSendsAtMostItsShareAndNudgesItselfForTheNextSlot(): void
    {
        $this->storeState(['hourly_budget' => 24]);
        $event = $this->published();
        foreach (['00A0', '00B0', '00C0', '00D0'] as $code) {
            $this->queue($event, $code);
        }
        wp_unschedule_hook('kalaka_send_mail');

        $report = $this->sender()->send();

        // 24 an hour in twelve slots: 2 for the slot from 09:00.
        self::assertEquals(new SendReport(2), $report);
        self::assertSame(['sent', 'sent', 'pending', 'pending'], array_column($this->deliveries(), 'status'));
        self::assertSame(
            (new \DateTimeImmutable('2026-11-30 09:05:00', new \DateTimeZone('UTC')))->getTimestamp(),
            $this->nextNudge()
        );

        self::assertEquals(new SendReport(), $this->sender()->send(), 'The slot\'s share is used up.');
    }

    public function testAnErroredAttemptCountsAgainstTheBudget(): void
    {
        $this->storeState(['hourly_budget' => 12]);
        $event = $this->published();
        $this->queue($event, '00A0');
        $this->queue($event, '00B0');
        $this->mailFails();
        $this->sender()->send();
        $this->mailSucceeds();

        self::assertEquals(new SendReport(), $this->sender()->send(), 'The one claim of the slot was the error.');
        self::assertNotContains('sent', array_column($this->deliveries(), 'status'));
    }

    public function testARunStopsClaimingAtItsTimeCapAndNudgesItselfAtOnce(): void
    {
        $event = $this->published();
        $this->queue($event, '00A0');
        $this->queue($event, '00B0');
        $ticks = 0;
        $stopwatch = new Stopwatch(static function () use (&$ticks): int {
            // The constructor, start() and the first check read 0; every later check reads 21 s.
            return $ticks++ < 3 ? 0 : 21_000_000_000;
        });
        wp_unschedule_hook('kalaka_send_mail');

        $report = $this->sender($stopwatch)->send();

        self::assertEquals(new SendReport(1), $report);
        self::assertSame(
            (new \DateTimeImmutable('2026-11-30 09:00:30', new \DateTimeZone('UTC')))->getTimestamp(),
            $this->nextNudge()
        );
    }

    public function testARunDoesNotStartWhileAnotherHoldsTheLock(): void
    {
        $event = $this->published();
        $this->queue($event);
        $other = $this->holdSenderLock();
        try {
            self::assertNull($this->sender()->send());
            self::assertSame('pending', $this->deliveries()[0]['status']);
        } finally {
            $other->close();
        }

        self::assertEquals(new SendReport(1), $this->sender()->send(), 'Once the other run is gone, it runs.');
    }

    public function testTheLockIsFreedAfterARun(): void
    {
        $event = $this->published();
        $this->queue($event);

        $this->sender()->send();

        $other = $this->holdSenderLock();
        $other->close();
    }

    public function testNothingRunsWhileTheLoadFailed(): void
    {
        $event = $this->published();
        $this->queue($event);
        $failures = LoadState::current()->failures;
        LoadState::record([new LoadFailure('x', 'y')]);
        try {
            self::assertNull($this->sender()->send());
        } finally {
            LoadState::record($failures);
        }

        self::assertSame('pending', $this->deliveries()[0]['status']);
    }
}
