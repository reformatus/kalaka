<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Mail;

use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Mail\Api\EventMailStats;
use Kalaka\Mail\Api\MailStats;
use Kalaka\Mail\Api\PauseInfo;
use Kalaka\Mail\Api\SiteMailStats;
use Kalaka\Mail\Pause\PauseMail;

/** The mail counts for the dashboard. */
final class MailStatsTest extends MailTestCase
{
    public function testTheSiteShowsThePauseAndThePendingDeliveriesOfEveryEvent(): void
    {
        self::assertEquals(new SiteMailStats(null, 0), (new MailStats())->site());

        $this->queue(5, '00A0');
        $this->queue(6, '00B0');
        $user = $this->fullAccessUser();
        $this->exec(new PauseMail(), [], new UserActor($user));

        self::assertEquals(
            new SiteMailStats(
                new PauseInfo('manual', $user, new \DateTimeImmutable('2026-11-30 09:00:30', new \DateTimeZone('UTC'))),
                2
            ),
            (new MailStats())->site()
        );
    }

    public function testEachEventIsCountedByStatusAndEveryAskedEventIsThere(): void
    {
        foreach (['00A0', '00B0', '00K1', '00G1', '00E2', '00F0'] as $code) {
            $this->queue(5, $code);
        }
        $this->queue(6, '00A0');
        [, $withError, $sent, $failed, $sending] = $this->deliveryIds();
        global $wpdb;
        $table = $wpdb->prefix . 'kalaka_deliveries';
        $wpdb->update($table, ['error' => 'send x', 'attempts' => 1], ['id' => $withError]);
        $wpdb->update($table, ['status' => 'sent', 'error' => 'send x'], ['id' => $sent]);
        $wpdb->update($table, ['status' => 'failed', 'error' => 'address x'], ['id' => $failed]);
        $wpdb->update($table, ['status' => 'sending'], ['id' => $sending]);

        $stats = (new MailStats())->ofEvents([5, 7]);

        self::assertEquals([5 => new EventMailStats(3, 1, 1, 1, 1), 7 => new EventMailStats()], $stats);
    }
}
