<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Mail;

use Kalaka\Mail\Api\MailStats;
use Kalaka\Tests\Integration\Foundation\PerEventCostTestCase;

/** The per-event counts stay inside their event. */
final class MailStatsCostTest extends PerEventCostTestCase
{
    private function seedEvent(int $event): void
    {
        global $wpdb;
        foreach (['pending', 'pending', 'sent', 'sent', 'sent', 'failed', 'sending'] as $number => $status) {
            $wpdb->insert($wpdb->prefix . 'kalaka_deliveries', [
                'event_id' => $event, 'mail' => 'registered', 'code' => '00A' . $number, 'cause_type' => 'registration',
                'cause_key' => 'c1', 'version' => 1, 'kind' => 'action', 'status' => $status, 'attempts' => 0,
                'queued_at' => '2026-11-30 08:00:00.000000',
            ]);
        }
    }

    public function testTheCountsOfOneEventStayInsideIt(): void
    {
        $this->assertPerEventCost(
            fn (int $event) => $this->seedEvent($event),
            static fn (int $event) => (new MailStats())->ofEvents([$event])
        );
    }
}
