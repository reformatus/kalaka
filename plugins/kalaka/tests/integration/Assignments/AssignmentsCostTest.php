<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Assignments;

use Kalaka\Assignments\Api\AssignmentSets;
use Kalaka\Assignments\AssignmentRepository;
use Kalaka\Foundation\ObjectCache;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Tests\Integration\Foundation\PerEventCostTestCase;

/** S44: the per-event reads of sets, options and a registration's assignment examine only their own event. */
final class AssignmentsCostTest extends PerEventCostTestCase
{
    private function seedEvent(int $event): void
    {
        global $wpdb;
        for ($set = 1; $set <= 3; $set++) {
            $wpdb->insert($wpdb->prefix . 'kalaka_assignment_sets', [
                'event_id' => $event,
                'version' => 1,
                'name' => 'Csoport ' . $set,
                'mode' => 'request',
            ]);
            $setId = (int) $wpdb->insert_id;
            for ($option = 1; $option <= 8; $option++) {
                $wpdb->insert($wpdb->prefix . 'kalaka_assignment_options', [
                    'set_id' => $setId,
                    'option_key' => 'modul_' . $option,
                    'event_id' => $event,
                    'version' => 1,
                    'position' => $option,
                    'label' => 'Modul ' . $option,
                    'leaders' => '',
                    'place' => '',
                ]);
                $wpdb->insert($wpdb->prefix . 'kalaka_assignments', [
                    'event_id' => $event,
                    'code' => sprintf('%02dA%d', $option, $set),
                    'set_id' => $setId,
                    'version' => 1,
                    'option_key' => 'modul_' . $option,
                    'source' => 'staff',
                    'state' => 'confirmed',
                ]);
            }
        }
    }

    public function testTheSetsOfAnEventAreReadWithinTheEvent(): void
    {
        $this->assertPerEventCost(
            fn (int $event) => $this->seedEvent($event),
            static function (int $event): void {
                ObjectCache::clear();
                (new AssignmentSets())->ofEvent($event);
            }
        );
    }

    public function testARegistrationsAssignmentIsReadWithinTheEvent(): void
    {
        $this->assertPerEventCost(
            fn (int $event) => $this->seedEvent($event),
            static function (int $event): void {
                ObjectCache::clear();
                (new AssignmentRepository())->ofRegistration(new RegistrationKey($event, '01A1'));
            }
        );
    }
}
