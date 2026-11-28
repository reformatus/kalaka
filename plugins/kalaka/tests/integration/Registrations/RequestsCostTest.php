<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Registrations;

use Kalaka\Registrations\Answer\AnswerRepository;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Tests\Integration\Foundation\PerEventCostTestCase;

/** The answers read of one registration stays inside its event (foundation.md, "Entities, repositories and queries"). */
final class RequestsCostTest extends PerEventCostTestCase
{
    public function testTheAnswersReadStaysInsideItsEvent(): void
    {
        $this->assertPerEventCost(
            static function (int $event): void {
                global $wpdb;
                for ($i = 0; $i < 50; $i++) {
                    $wpdb->insert($wpdb->prefix . 'kalaka_answers', [
                        'event_id' => $event,
                        'code' => sprintf('%02dA0', $i),
                        'request' => 'offer:church',
                        'approved' => 1,
                        'answered_at' => '2026-12-04 09:00:00.000000',
                    ]);
                }
            },
            static fn (int $event): array => (new AnswerRepository())->forRegistration(
                new RegistrationKey($event, '00A0')
            )
        );
    }
}
