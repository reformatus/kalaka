<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Mail;

use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Mail\Pause\ResumeMail;
use Kalaka\Mail\SendReport;

/**
 * Errors: back to pending with the reported error, the run stops, the errored delivery is tried first, and
 * two errors in a row pause sending until a person resumes. mail.md, "Failures" and "Pausing".
 */
final class SenderFailureTest extends MailTestCase
{
    public function testAnErrorPutsTheDeliveryBackToPendingWithItsRawErrorAndStopsTheRun(): void
    {
        $event = $this->published();
        $this->queue($event, '00A0');
        $this->queue($event, '00B0');
        [$first, $second] = $this->deliveryIds();
        $this->mailFails();
        wp_unschedule_hook('kalaka_send_mail');

        $log = $this->capturedErrorLog(function () use (&$report): void {
            $report = $this->sender()->send();
        });

        self::assertEquals(new SendReport(0, 1), $report);
        self::assertSame(['teszt@example.test'], $this->mailed, 'The run stopped after the error.');
        $row = $this->delivery($first);
        self::assertSame('pending', $row['status']);
        self::assertSame('1', $row['attempts']);
        self::assertSame('v1', $row['mail_version']);
        self::assertMatchesRegularExpression(
            '/^send wp_mail_failed: SMTP Error: The following recipients failed: teszt@example\.test'
            . ' \(correlation [0-9a-f-]{36}\)$/',
            (string) $row['error']
        );
        self::assertSame('pending', $this->delivery($second)['status']);
        self::assertSame('0', $this->delivery($second)['attempts']);
        self::assertSame(1, (int) ($this->stateRow()['errors_in_row'] ?? 0));
        self::assertNull($this->stateRow()['paused_at'] ?? null);
        self::assertNull($this->nextNudge(), 'No nudge after an error: the next periodic run retries.');
        self::assertStringContainsString('mail delivery ' . $first . ' of event ' . $event . ' failed', $log);
        self::assertStringContainsString('teszt@example.test', $log, 'The full error goes to the log.');
    }

    public function testTheRawErrorIsStoredOnTheDeliveryAndInTheAudit(): void
    {
        $event = $this->published();
        $this->queue($event);
        $this->mailFails();

        $this->capturedErrorLog(fn () => $this->sender()->send());

        $stored = (string) $this->deliveries()[0]['error'];
        self::assertStringContainsString('The following recipients failed: teszt@example.test', $stored);
        $record = $this->entriesOf('kalaka/record-delivery')[0];
        $errors = array_values(array_filter(
            $this->changesOf($record),
            static fn (array $change): bool => $change['field'] === 'error'
        ));
        self::assertCount(1, $errors);
        self::assertSame($stored, $errors[0]['after']);
    }

    public function testTheNextRunTriesTheErroredDeliveryFirstAndSuccessResetsTheErrorsInARow(): void
    {
        $event = $this->published();
        $this->queue($event, '00A0');
        $this->queue($event, '00B0');
        [$first, $second] = $this->deliveryIds();
        $this->mailFails();
        $this->capturedErrorLog(fn () => $this->sender()->send());
        $this->mailSucceeds();
        $this->composer->composed = [];

        $report = $this->sender()->send();

        self::assertEquals(new SendReport(2), $report);
        self::assertSame([$first, $second], $this->composer->composed);
        self::assertSame('sent', $this->delivery($first)['status']);
        self::assertSame('2', $this->delivery($first)['attempts']);
        self::assertNotNull($this->delivery($first)['error'], 'The latest error stays on the delivery.');
        self::assertSame('0', $this->stateRow()['errors_in_row'] ?? null);
    }

    public function testTwoErrorsInARowPauseSendingNamingTheDeliveryAndItsError(): void
    {
        $event = $this->published();
        $this->queue($event, '00A0');
        $this->queue($event, '00B0');
        [$first, $second] = $this->deliveryIds();
        $this->mailFails();

        $this->capturedErrorLog(fn () => $this->sender()->send());
        $this->capturedErrorLog(function () use (&$report): void {
            $report = $this->sender()->send();
        });

        self::assertEquals(new SendReport(0, 1, 0, true), $report);
        $state = $this->stateRow();
        self::assertIsArray($state);
        self::assertSame('errors', $state['cause']);
        self::assertSame('2026-11-30 09:00:30.000000', $state['paused_at']);
        self::assertNull($state['paused_by']);
        self::assertSame((string) $first, $state['delivery_id']);
        self::assertSame((string) $event, $state['delivery_event_id']);
        self::assertSame($this->delivery($first)['error'], $state['error']);
        self::assertSame('2', $state['errors_in_row']);
        self::assertSame('pending', $this->delivery($first)['status'], 'It stays in the queue.');
        self::assertSame('0', $this->delivery($second)['attempts']);

        $records = $this->entriesOf('kalaka/record-delivery');
        $pausing = end($records);
        self::assertIsArray($pausing);
        self::assertSame('system', $pausing['actor_type']);
        self::assertContains('delivery/' . $event . '/' . $first, $this->subjectsOf($pausing['id'] ?? 0));
        self::assertContains('mail_state//1', $this->subjectsOf($pausing['id'] ?? 0));

        $this->mailSucceeds();
        self::assertEquals(new SendReport(), $this->sender()->send(), 'Paused: nothing is claimed.');
        self::assertSame('pending', $this->delivery($second)['status']);
    }

    public function testAfterAResumeTheFailingDeliveryIsTriedFirstAndAnotherErrorPausesAtOnce(): void
    {
        $event = $this->published();
        $this->queue($event, '00A0');
        $this->queue($event, '00B0');
        [$first] = $this->deliveryIds();
        $this->mailFails();
        $this->capturedErrorLog(fn () => $this->sender()->send());
        $this->capturedErrorLog(fn () => $this->sender()->send());
        $this->exec(new ResumeMail(), []);
        $this->composer->composed = [];

        $this->capturedErrorLog(function () use (&$report): void {
            $report = $this->sender()->send();
        });

        self::assertEquals(new SendReport(0, 1, 0, true), $report);
        self::assertSame([$first], $this->composer->composed);
        self::assertSame('3', $this->delivery($first)['attempts']);
        self::assertSame('errors', $this->stateRow()['cause'] ?? null);
    }

    public function testAPauseSetMidRunStopsTheRunBeforeTheNextClaim(): void
    {
        $event = $this->published();
        $this->queue($event, '00A0');
        $this->queue($event, '00B0');
        [, $second] = $this->deliveryIds();
        $this->composer->onRecipient = function ($delivery) use ($second): void {
            if ($delivery->id !== $second) {
                // Another request pauses while the first attempt is in hand.
                $this->storeState(['paused_at' => '2026-11-30 09:00:31.000000', 'cause' => 'manual']);
            }
        };

        $report = $this->sender()->send();

        self::assertEquals(new SendReport(1), $report);
        self::assertSame(['sent', 'pending'], array_column($this->deliveries(), 'status'));
    }

    public function testARenderingErrorKeepsTheExceptionClassAndItsMessage(): void
    {
        $event = $this->published();
        $this->queue($event);
        $this->composer->failure = new \RuntimeException('Teszt Elek <teszt@example.test> has no name.');

        $this->capturedErrorLog(fn () => $this->sender()->send());

        $row = $this->deliveries()[0];
        self::assertSame('pending', $row['status']);
        self::assertNull($row['mail_version']);
        self::assertStringStartsWith(
            'compose RuntimeException: Teszt Elek <teszt@example.test> has no name. (correlation ',
            (string) $row['error']
        );
        self::assertSame([], $this->mailed);
    }

    public function testAKalakaRefusalWhileRenderingKeepsItsOwnMessage(): void
    {
        $event = $this->published();
        $this->queue($event);
        $this->composer->failure = new RuleViolation();

        $this->capturedErrorLog(fn () => $this->sender()->send());

        self::assertStringStartsWith(
            'compose ' . RuleViolation::class . ': A művelet nem hajtható végre. (correlation ',
            (string) $this->deliveries()[0]['error']
        );
    }

    public function testAResumeDoesNotResetTheErrorsInARow(): void
    {
        $this->storeState([
            'paused_at' => '2026-11-30 08:00:00.000000', 'cause' => 'errors', 'errors_in_row' => 2,
            'delivery_id' => 7, 'delivery_event_id' => 5, 'error' => 'send x (correlation y)',
        ]);

        self::assertTrue($this->exec(new ResumeMail(), [], new UserActor($this->fullAccessUser())));

        $state = $this->stateRow();
        self::assertIsArray($state);
        self::assertNull($state['paused_at']);
        self::assertNull($state['cause']);
        self::assertNull($state['delivery_id']);
        self::assertNull($state['error']);
        self::assertSame('2', $state['errors_in_row']);
    }
}
