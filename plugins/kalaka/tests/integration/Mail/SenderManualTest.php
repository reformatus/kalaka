<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Mail;

use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Mail\Api\ActionMail;
use Kalaka\Mail\Api\ManualMails;
use Kalaka\Mail\Body\KindComposer;
use Kalaka\Mail\Sender;

/**
 * A manual delivery is rendered from its send batch at send time, and its own problems fail it without an
 * attempt and without pausing. mail.md, "Sending" and "Five kinds of mail".
 */
final class SenderManualTest extends MailBodyTestCase
{
    /** @var list<array<string, mixed>> what `wp_mail` was called with */
    private array $sent = [];

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        $this->sent = [];
        remove_all_filters('pre_wp_mail');
        add_filter('pre_wp_mail', function (mixed $result, array $atts): bool {
            $this->sent[] = $atts;

            return true;
        }, 10, 2);
    }

    private function realSender(): Sender
    {
        return new Sender(fn () => $this->runner, new KindComposer());
    }

    /**
     * @param list<string> $codes
     * @return int the batch ID
     */
    private function batch(int $event, int $body, array $codes, bool $layout = true): int
    {
        $result = (new ManualMails())->send(
            ['event_id' => $event, 'body_id' => $body, 'selection' => ['codes' => $codes], 'layout' => $layout],
            new CliActor(null),
            $this->runner
        );

        return $result['batch_id'];
    }

    public function testItSendsTheBatchsBodyToTheContactEmailAndRecordsOnlyItsVersion(): void
    {
        $event = $this->published();
        $this->registration($event);
        $body = $this->document($event, $this->paragraph('Szia!'), 'Tájékoztató');
        $this->batch($event, $body, ['12A3']);

        $this->realSender()->send();

        self::assertCount(1, $this->sent);
        self::assertSame('teszt@example.test', $this->sent[0]['to']);
        self::assertSame('Tájékoztató', $this->sent[0]['subject']);
        self::assertStringContainsString('Szia!', (string) $this->sent[0]['message']);
        $row = $this->deliveries()[0];
        self::assertSame('sent', $row['status']);
        $revision = wp_get_latest_revision_id_and_total_count($body);
        self::assertIsArray($revision);
        self::assertSame('b' . $revision['latest_id'], $row['mail_version']);
    }

    public function testTheBatchsLayoutFlagDecidesWhetherTheEventLayoutWrapsIt(): void
    {
        $event = $this->published();
        $this->registration($event);
        $this->setLayout($event, '<div class="keret">{{content}}</div>');
        $body = $this->document($event, $this->paragraph('Szia!'));
        $this->batch($event, $body, ['12A3'], true);
        $this->batch($event, $body, ['12A3'], false);

        $this->realSender()->send();

        self::assertCount(2, $this->sent);
        self::assertStringContainsString('class="keret"', (string) $this->sent[0]['message']);
        self::assertStringNotContainsString('class="keret"', (string) $this->sent[1]['message']);
        self::assertMatchesRegularExpression('/\Ab\d+-l\d+\z/', (string) $this->deliveries()[0]['mail_version']);
        self::assertMatchesRegularExpression('/\Ab\d+\z/', (string) $this->deliveries()[1]['mail_version']);
    }

    public function testABatchThatIsGoneFailsTheDeliveryWithoutAnAttemptAndWithoutPausing(): void
    {
        $event = $this->published();
        $this->registration($event, '12A3');
        $this->registration($event, '12B4');
        $body = $this->document($event, $this->paragraph('Szia!'));
        $batch = $this->batch($event, $body, ['12A3', '12B4']);
        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'kalaka_mail_batches', ['id' => $batch]);

        $this->capturedErrorLog(function (): void {
            $this->realSender()->send();
        });

        self::assertSame([], $this->sent);
        foreach ($this->deliveries() as $row) {
            self::assertSame('failed', $row['status']);
            self::assertSame('0', $row['attempts']);
            self::assertStringStartsWith('body no_batch ' . $batch . ' (correlation ', (string) $row['error']);
        }
        self::assertNull($this->stateRow()['paused_at'] ?? null);
        self::assertSame(0, (int) ($this->stateRow()['errors_in_row'] ?? 0));
    }

    public function testABodyTrashedAfterTheSendFailsItsDeliveriesWithoutPausing(): void
    {
        $event = $this->published();
        $this->registration($event, '12A3');
        $this->registration($event, '12B4');
        $this->registration($event, '12K0');
        $body = $this->document($event, $this->paragraph('Szia!'));
        $this->batch($event, $body, ['12A3', '12B4', '12K0']);
        wp_trash_post($body);

        $this->capturedErrorLog(function (): void {
            $this->realSender()->send();
        });

        self::assertSame([], $this->sent);
        foreach ($this->deliveries() as $row) {
            self::assertSame('failed', $row['status']);
            self::assertStringStartsWith('body unpublished ' . $body . ' (correlation ', (string) $row['error']);
        }
        self::assertNull($this->stateRow()['paused_at'] ?? null);
    }

    public function testABodyWhoseKeyWentStaleFailsItsOwnDeliveriesOnly(): void
    {
        $event = $this->published();
        $this->registration($event);
        $good = $this->document($event, $this->paragraph('Szia!'));
        $bad = $this->brokenDocument($event, $this->paragraph('<span data-kalaka-value="nincs">x</span>'));
        $this->batch($event, $bad, ['12A3']);
        $this->batch($event, $good, ['12A3']);

        $this->capturedErrorLog(function (): void {
            $this->realSender()->send();
        });

        self::assertSame('failed', $this->deliveries()[0]['status']);
        self::assertStringStartsWith('body unknown_value_key nincs', (string) $this->deliveries()[0]['error']);
        self::assertSame('sent', $this->deliveries()[1]['status']);
    }

    public function testAnActionMailGoesBeforeAManualOne(): void
    {
        $event = $this->published();
        $this->registration($event, '12A3');
        $this->registration($event, '12B4', ['name' => 'Teszt Elek', 'email' => 'masik@example.test']);
        $body = $this->document($event, $this->paragraph('Szia!'), 'Kézi');
        $action = $this->document($event, $this->paragraph('Szia!'), 'Automatikus');
        $this->switchOn($event, ActionMail::Registered->value, $action);
        $this->batch($event, $body, ['12A3']);
        $this->queue($event, '12B4', mail: 'registered', causeKey: 'c9');

        $this->realSender()->send();

        self::assertSame(['Automatikus', 'Kézi'], array_column($this->sent, 'subject'));
    }
}
