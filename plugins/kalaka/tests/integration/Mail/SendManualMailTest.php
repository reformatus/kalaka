<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Mail;

use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Mail\Api\ManualMails;
use Kalaka\Mail\Catalogue\BodyUnpublished;
use Kalaka\Mail\Catalogue\UpdateActionMail;
use Kalaka\Mail\Manual\UnknownRegistrationCode;
use Kalaka\Mail\Manual\MailBatchQuery;
use Kalaka\Mail\Manual\SendManualMail;
use Kalaka\Registrations\Api\RegistrationStatus;

/** `kalaka/send-manual-mail`: the batch, its members and its deliveries. mail.md, "Five kinds of mail", Manual. */
final class SendManualMailTest extends MailBodyTestCase
{
    /** @param array<string, mixed> $input */
    private function send(array $input, ?CliActor $actor = null): array
    {
        return (new ManualMails())->send($input, $actor ?? new CliActor(null), $this->runner);
    }

    /**
     * @param list<string> $codes
     * @return array<string, mixed>
     */
    private function input(int $event, int $body, array $codes, array $more = []): array
    {
        return ['event_id' => $event, 'body_id' => $body, 'codes' => $codes] + $more;
    }

    /** @return array{int, int} the event and a published body of it, with two registered registrations */
    private function ready(): array
    {
        $event = $this->published();
        $this->registration($event, '00A0');
        $this->registration($event, '00A2');

        return [$event, $this->document($event, $this->paragraph('Szia'))];
    }

    private function batchCount(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}kalaka_mail_batches");
    }

    private function memberCount(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}kalaka_mail_batch_members");
    }

    public function testItQueuesOneManualDeliveryPerCodeKeyedByTheBatch(): void
    {
        [$event, $body] = $this->ready();

        $result = $this->send($this->input($event, $body, ['00A0', '00A2']));

        self::assertSame(2, $result['queued']);
        self::assertSame([], $result['skipped']);
        self::assertFalse($result['repeat']);
        $rows = $this->deliveries();
        self::assertCount(2, $rows);
        foreach ($rows as $row) {
            self::assertSame(['manual', 'manual', 'mail_batch', (string) $result['batch_id'], 'pending'], [
                $row['mail'], $row['kind'], $row['cause_type'], $row['cause_key'], $row['status'],
            ]);
        }
        self::assertSame(['00A0', '00A2'], array_column($rows, 'code'));
        $batch = (new MailBatchQuery())->find($event, $result['batch_id']);
        self::assertNotNull($batch);
        self::assertSame([$body, true, 2, 0, null], [
            $batch->bodyId, $batch->layout, $batch->queued, $batch->skipped, $batch->createdBy,
        ]);
        $members = (new MailBatchQuery())->members($result['batch_id']);
        self::assertSame(['00A0', '00A2'], array_map(static fn ($member): string => $member->code, $members));
        self::assertNotNull($this->nextNudge());
    }

    public function testTheBatchRecordsOnlyCodesAndOutcomes(): void
    {
        [$event, $body] = $this->ready();
        $this->send($this->input($event, $body, ['00A0']));

        global $wpdb;
        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$wpdb->prefix}kalaka_mail_batch_members");
        self::assertSame(['batch_id', 'code', 'event_id', 'outcome'], $columns);
    }

    public function testWithdrawnAndRemovedAreSkippedAndListedAndAWaitlistedOneIsSentTo(): void
    {
        $event = $this->published();
        $this->registration($event, '00A0', status: 'withdrawn');
        $this->registration($event, '00A2', status: 'removed');
        $this->registration($event, '00A5', status: 'waitlisted');
        $this->registration($event, '00A8');
        $body = $this->document($event, $this->paragraph('Szia'));

        $result = $this->send($this->input($event, $body, ['00A0', '00A2', '00A5', '00A8']));

        self::assertSame(2, $result['queued']);
        self::assertSame(
            [['code' => '00A0', 'reason' => 'withdrawn'], ['code' => '00A2', 'reason' => 'removed']],
            $result['skipped']
        );
        self::assertSame(['00A5', '00A8'], array_column($this->deliveries(), 'code'));
        $outcomes = [];
        foreach ((new MailBatchQuery())->members($result['batch_id']) as $member) {
            $outcomes[$member->code] = $member->outcome;
        }
        self::assertSame(
            ['00A0' => 'skipped_withdrawn', '00A2' => 'skipped_removed', '00A5' => 'queued', '00A8' => 'queued'],
            $outcomes
        );
    }

    public function testAnUnknownCodeRefusesTheWholeBatchAndWritesNothing(): void
    {
        [$event, $body] = $this->ready();

        try {
            $this->send($this->input($event, $body, ['00A0', '00A5']));
            self::fail('An unknown code should refuse the batch.');
        } catch (UnknownRegistrationCode $e) {
            self::assertSame('kalaka_mail_unknown_code', $e->errorCode());
            self::assertSame([], $this->deliveries());
            self::assertSame(0, $this->batchCount());
            self::assertSame(0, $this->memberCount());
        }
    }

    public function testThePlanSplitsTheCodesIntoQueuedSkippedWithItsReasonAndUnknown(): void
    {
        $plan = SendManualMail::plan(
            ['00A0', '00A2', '00A5', '00A8', '00A9'],
            [
                '00A0' => RegistrationStatus::Withdrawn,
                '00A2' => RegistrationStatus::Removed,
                '00A5' => RegistrationStatus::Waitlisted,
                '00A8' => RegistrationStatus::Registered,
            ]
        );

        self::assertSame(['00A5', '00A8'], $plan['queue']);
        $skipped = [['code' => '00A0', 'reason' => 'withdrawn'], ['code' => '00A2', 'reason' => 'removed']];
        self::assertSame($skipped, $plan['skipped']);
        self::assertSame(['00A9'], $plan['unknown']);
        $this->expectException(UnknownRegistrationCode::class);
        SendManualMail::refuseUnknown($plan);
    }

    public function testACodeOfAnotherEventIsUnknown(): void
    {
        [$event, $body] = $this->ready();
        $other = $this->published();
        $this->registration($other, '00A5');

        $this->expectException(UnknownRegistrationCode::class);
        $this->send($this->input($event, $body, ['00A5']));
    }

    public function testABodyOfAnotherEventOrNotPublishedOrNotReadIsRefused(): void
    {
        [$event] = $this->ready();
        $other = $this->published();
        $elsewhere = $this->document($other, $this->paragraph('Szia'));
        try {
            $this->send($this->input($event, $elsewhere, ['00A0']));
            self::fail('A body of another event is refused.');
        } catch (InvalidInput) {
            self::assertSame([], $this->deliveries());
        }

        $draft = $this->document($event, $this->paragraph('Szia'));
        wp_update_post(['ID' => $draft, 'post_status' => 'draft']);
        try {
            $this->send($this->input($event, $draft, ['00A0']));
            self::fail('A body that is not published is refused.');
        } catch (BodyUnpublished $e) {
            self::assertSame('kalaka_mail_body_unpublished', $e->errorCode());
            self::assertStringContainsString(UpdateActionMail::BODY_UNPUBLISHED, $e->getMessage());
        }

        $body = $this->document($event, $this->paragraph('Szia'));
        $this->expectException(SystemFailure::class);
        $this->runner->run(new SendManualMail(), $this->input($event, $body, ['00A0']), new CliActor(null));
    }

    public function testTheSameRequestKeyIsARepeatThatQueuesNothing(): void
    {
        [$event, $body] = $this->ready();
        $input = $this->input($event, $body, ['00A0', '00A2'], ['request_key' => 'abcdefgh-1']);

        $first = $this->send($input);
        $again = $this->send($this->input($event, $body, ['00A2', '00A0'], ['request_key' => 'abcdefgh-1']));

        self::assertFalse($first['repeat']);
        self::assertTrue($again['repeat']);
        self::assertSame($first['batch_id'], $again['batch_id']);
        self::assertSame(2, $again['queued']);
        self::assertCount(2, $this->deliveries());
        self::assertSame(1, $this->batchCount());
    }

    public function testARepeatIsAnsweredAsOneEvenWhenTheBodyWasUnpublishedSince(): void
    {
        [$event, $body] = $this->ready();
        $input = $this->input($event, $body, ['00A0'], ['request_key' => 'abcdefgh-1']);
        $first = $this->send($input);
        wp_update_post(['ID' => $body, 'post_status' => 'draft']);

        $again = $this->send($input);

        self::assertTrue($again['repeat']);
        self::assertSame($first['batch_id'], $again['batch_id']);
        self::assertCount(1, $this->deliveries());
    }

    public function testTheSameKeyWithOtherCodesOrAnotherBodyIsRefusedNeverMerged(): void
    {
        [$event, $body] = $this->ready();
        $this->send($this->input($event, $body, ['00A0'], ['request_key' => 'abcdefgh-1']));
        $other = $this->document($event, $this->paragraph('Más'));

        foreach ([$this->input($event, $body, ['00A0', '00A2']), $this->input($event, $other, ['00A0'])] as $input) {
            try {
                $this->send($input + ['request_key' => 'abcdefgh-1']);
                self::fail('A key used for another send is refused.');
            } catch (InvalidInput) {
                self::assertCount(1, $this->deliveries());
            }
        }
    }

    public function testARequestKeyIsPerEvent(): void
    {
        [$event, $body] = $this->ready();
        $other = $this->published();
        $this->registration($other, '00A0');
        $otherBody = $this->document($other, $this->paragraph('Szia'));
        $this->send($this->input($event, $body, ['00A0'], ['request_key' => 'abcdefgh-1']));

        $result = $this->send($this->input($other, $otherBody, ['00A0'], ['request_key' => 'abcdefgh-1']));

        self::assertFalse($result['repeat']);
        self::assertCount(2, $this->deliveries());
    }

    public function testARunThatLosesTheRaceForTheKeyAnswersAsARepeat(): void
    {
        [$event, $body] = $this->ready();
        $raced = false;
        $competing = function (string $query) use (&$raced, $event, $body): string {
            global $wpdb;
            if (!$raced && str_contains($query, 'INSERT IGNORE INTO `' . $wpdb->prefix . 'kalaka_mail_batches`')) {
                $raced = true;
                // The other run committed first: its batch and members are there when this insert runs.
                $wpdb->insert($wpdb->prefix . 'kalaka_mail_batches', [
                    'event_id' => $event, 'version' => 1, 'body_id' => $body, 'layout' => 1,
                    'request_key' => 'abcdefgh-1', 'created_at' => '2026-11-30 09:00:00.000000',
                    'queued' => 1, 'skipped' => 0,
                ]);
                $wpdb->insert($wpdb->prefix . 'kalaka_mail_batch_members', [
                    'batch_id' => $wpdb->insert_id, 'code' => '00A0', 'event_id' => $event, 'outcome' => 'queued',
                ]);
            }

            return $query;
        };
        add_filter('query', $competing);
        try {
            $result = $this->send($this->input($event, $body, ['00A0'], ['request_key' => 'abcdefgh-1']));
        } finally {
            remove_filter('query', $competing);
        }

        self::assertTrue($raced);
        self::assertTrue($result['repeat']);
        self::assertSame([], $this->deliveries());
        self::assertSame(1, $this->batchCount());
    }

    public function testWithoutAKeyEverySendIsANewBatchAndANewDelivery(): void
    {
        [$event, $body] = $this->ready();

        $first = $this->send($this->input($event, $body, ['00A0']));
        $second = $this->send($this->input($event, $body, ['00A0']));

        self::assertNotSame($first['batch_id'], $second['batch_id']);
        self::assertCount(2, $this->deliveries());
    }

    public function testItWritesNoRegistrationAndRunsNoEvaluation(): void
    {
        [$event, $body] = $this->ready();
        global $wpdb;
        $before = $wpdb->get_var("SELECT SUM(version) FROM {$wpdb->prefix}kalaka_registrations");

        $this->send($this->input($event, $body, ['00A0', '00A2']));

        self::assertSame($before, $wpdb->get_var("SELECT SUM(version) FROM {$wpdb->prefix}kalaka_registrations"));
    }

    public function testTheAuditEntryNamesTheBatchTheDeliveriesAndTheRegistrations(): void
    {
        [$event, $body] = $this->ready();
        $result = $this->send($this->input($event, $body, ['00A0']));

        $entry = $this->entriesOf('kalaka/send-manual-mail')[0];
        $subjects = $this->subjectsOf($entry['id']);
        self::assertContains('mail_batch/' . $event . '/' . $result['batch_id'], $subjects);
        self::assertContains('registration/' . $event . '/00A0', $subjects);
        self::assertContains('delivery/' . $event . '/' . $this->deliveryIds()[0], $subjects);
    }

    public function testAPausedSiteStillQueuesAndTheResultSaysSo(): void
    {
        [$event, $body] = $this->ready();
        $this->storeState(['paused_at' => '2026-11-30 08:00:00.000000', 'cause' => 'manual', 'errors_in_row' => 0]);

        $result = $this->send($this->input($event, $body, ['00A0']));

        self::assertTrue($result['paused']);
        self::assertFalse($result['held']);
        self::assertCount(1, $this->deliveries());
    }

    public function testAnUnpublishedEventQueuesAndTheResultSaysItIsHeld(): void
    {
        $event = $this->unpublished();
        $this->registration($event, '00A0');
        $body = $this->document($event, $this->paragraph('Szia'));

        $result = $this->send($this->input($event, $body, ['00A0']));

        self::assertTrue($result['held']);
        self::assertFalse($result['paused']);
        self::assertCount(1, $this->deliveries());
    }

    public function testAnActionMailsBodyMayBeSentAsAManualMail(): void
    {
        [$event, $body] = $this->ready();
        $this->switchOn($event, 'registered', $body);

        $result = $this->send($this->input($event, $body, ['00A0']));

        self::assertSame(1, $result['queued']);
    }

    public function testTheInputLimitsAreChecked(): void
    {
        [$event, $body] = $this->ready();
        foreach ([[], ['00A0', '00A0']] as $codes) {
            try {
                $this->send($this->input($event, $body, $codes));
                self::fail('The codes were refused.');
            } catch (InvalidInput) {
                self::assertSame([], $this->deliveries());
            }
        }
        $this->expectException(InvalidInput::class);
        $this->send($this->input($event, $body, ['00A0'], ['request_key' => 'short']));
    }
}
