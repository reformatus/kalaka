<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Mail;

use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Mail\Api\MailKind;
use Kalaka\Mail\Api\MailQueue;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Tests\Integration\Foundation\AdminProbe;

/** Queuing a delivery: once-only by its key, without an error on a repeat, and a nudge after the commit. */
final class MailQueueTest extends MailTestCase
{
    public function testADeliveryIsQueuedPendingWithItsKeyAndTheSenderIsNudged(): void
    {
        self::assertTrue($this->queue(5, '12A3', MailKind::Action, 'registered', 'c1'));

        $rows = $this->deliveries();
        self::assertCount(1, $rows);
        self::assertSame(
            [
                'event_id' => '5', 'mail' => 'registered', 'code' => '12A3', 'cause_type' => 'registration',
                'cause_key' => 'c1', 'version' => '1', 'kind' => 'action', 'status' => 'pending', 'attempts' => '0',
                'queued_at' => '2026-11-30 09:00:30.000000', 'claimed_at' => null, 'sent_at' => null,
                'mail_version' => null, 'error' => null,
            ],
            array_diff_key($rows[0], ['id' => true])
        );
        self::assertSame(
            (new \DateTimeImmutable('2026-11-30 09:00:30', new \DateTimeZone('UTC')))->getTimestamp(),
            $this->nextNudge()
        );
    }

    public function testTheSameKeyAgainQueuesNothingAndIsNoError(): void
    {
        self::assertTrue($this->queue(5));
        wp_unschedule_hook(MailQueue::SEND_HOOK);

        self::assertFalse($this->queue(5));

        self::assertCount(1, $this->deliveries());
        self::assertNull($this->nextNudge(), 'Nothing new was queued, so nothing is nudged.');
        self::assertSame([], $this->failedEntries());
    }

    public function testAnotherCauseRegistrationOrMailIsAnotherDelivery(): void
    {
        self::assertTrue($this->queue(5, '12A3', MailKind::Action, 'registered', 'c1'));
        self::assertTrue($this->queue(5, '12A3', MailKind::Action, 'registered', 'c2'));
        self::assertTrue($this->queue(5, '12A4', MailKind::Action, 'registered', 'c1'));
        self::assertTrue($this->queue(5, '12A3', MailKind::Action, 'admitted', 'c1'));
        self::assertTrue($this->queue(6, '12A3', MailKind::Action, 'registered', 'c1'));

        self::assertCount(5, $this->deliveries());
    }

    public function testTheInsertIsAuditedWithTheDeliveryAsItsSubject(): void
    {
        $this->queue(5);

        $entry = $this->onlyEntry();
        $id = $this->deliveryIds()[0];
        self::assertContains('delivery/5/' . $id, $this->subjectsOf($entry['id'] ?? 0));
        self::assertContains('status', $this->changedFields($entry));
    }

    public function testABadMailKeyOrACauseOfAnotherEventIsAProgrammingError(): void
    {
        foreach ([['Registered', 5], ['registered', 6]] as [$mail, $causeEvent]) {
            try {
                $this->runner->run(
                    new AdminProbe(static fn (array $input, Context $context): bool => (new MailQueue())->add(
                        $context,
                        new RegistrationKey(5, '12A3'),
                        $mail,
                        MailKind::Action,
                        new Subject('registration', $causeEvent, 'c1')
                    )),
                    [],
                    new CliActor(null)
                );
                self::fail('The queue should have refused.');
            } catch (SystemFailure) {
                self::assertSame([], $this->deliveries());
            }
        }
    }
}
