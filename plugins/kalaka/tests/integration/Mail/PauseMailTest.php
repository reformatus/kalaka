<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Mail;

use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\Error\PermissionDenied;
use Kalaka\Mail\ClaimDelivery;
use Kalaka\Mail\ClearMailQueue;
use Kalaka\Mail\FailDelivery;
use Kalaka\Mail\Pause\PauseMail;
use Kalaka\Mail\Pause\ResumeMail;
use Kalaka\Mail\RecordDelivery;

/** Pausing, resuming and clearing the queue by hand; who may run the Mail use cases. mail.md, "Pausing". */
final class PauseMailTest extends MailTestCase
{
    public function testAPersonPausesRecordingWhoAndWhenAndAnotherPauseChangesNothing(): void
    {
        $user = $this->fullAccessUser();

        self::assertTrue($this->exec(new PauseMail(), [], new UserActor($user)));
        self::assertFalse($this->exec(new PauseMail(), [], new UserActor($user)));

        $state = $this->stateRow();
        self::assertIsArray($state);
        self::assertSame('manual', $state['cause']);
        self::assertSame((string) $user, $state['paused_by']);
        self::assertSame('2026-11-30 09:00:30.000000', $state['paused_at']);
        self::assertSame('1', $state['version'], 'The second pause wrote nothing.');
        $entries = $this->entriesOf('kalaka/pause-mail');
        self::assertCount(2, $entries);
        self::assertSame(['mail_state//1'], $this->subjectsOf($entries[0]['id'] ?? 0));
        self::assertContains('cause', $this->changedFields($entries[0]));
    }

    public function testAPauseAfterErrorsKeepsItsCauseWhenAPersonPausesToo(): void
    {
        $this->storeState(['paused_at' => '2026-11-30 08:00:00.000000', 'cause' => 'errors', 'errors_in_row' => 2]);

        self::assertFalse($this->exec(new PauseMail(), []));

        self::assertSame('errors', $this->stateRow()['cause'] ?? null);
    }

    public function testWhilePausedTheSenderClaimsNothing(): void
    {
        $event = $this->published();
        $this->queue($event);
        $this->exec(new PauseMail(), []);

        $this->sender()->send();

        self::assertSame('pending', $this->deliveries()[0]['status']);
        self::assertSame([], $this->entriesOf('kalaka/claim-delivery'));

        self::assertTrue($this->exec(new ResumeMail(), []));
        self::assertFalse($this->exec(new ResumeMail(), []), 'Not paused: nothing to resume.');
        $this->sender()->send();
        self::assertSame('sent', $this->deliveries()[0]['status']);
    }

    public function testClearingTheQueueDeletesEveryPendingDeliveryAndKeepsTheRest(): void
    {
        $event = $this->published();
        foreach (['00A0', '00B0', '00K1', '00G1'] as $code) {
            $this->queue($event, $code);
        }
        $this->queue($this->unpublished(), '00E2');
        [$sent, $sending, $failed] = $this->deliveryIds();
        global $wpdb;
        $table = $wpdb->prefix . 'kalaka_deliveries';
        $wpdb->update($table, ['status' => 'sent'], ['id' => $sent]);
        $wpdb->update($table, ['status' => 'sending'], ['id' => $sending]);
        $wpdb->update($table, ['status' => 'failed'], ['id' => $failed]);

        self::assertSame(2, $this->exec(new ClearMailQueue(), []));

        self::assertSame([$sent, $sending, $failed], $this->deliveryIds());
        $entry = $this->entriesOf('kalaka/clear-mail-queue')[0];
        self::assertCount(2, $this->subjectsOf($entry['id'] ?? 0));
    }

    public function testOnlyFullAccessAndTheCliRunTheMailUseCases(): void
    {
        $grantee = new UserActor(self::factory()->user->create());
        $useCases = [
            [new PauseMail(), []],
            [new ResumeMail(), []],
            [new ClearMailQueue(), []],
            [new ClaimDelivery(), ['event_id' => 5, 'id' => 1]],
            [new RecordDelivery(), ['event_id' => 5, 'id' => 1, 'mail_version' => null, 'error' => null]],
            [new FailDelivery(), ['event_id' => 5, 'id' => 1, 'error' => 'x']],
        ];
        foreach ($useCases as [$useCase, $input]) {
            try {
                $this->exec($useCase, $input, $grantee);
                self::fail($useCase::name() . ' should have been refused.');
            } catch (PermissionDenied) {
                self::assertNull($this->stateRow());
            }
        }

        self::assertTrue($this->exec(new PauseMail(), [], new CliActor(null)));
        self::assertNull($this->stateRow()['paused_by'] ?? null);
    }
}
