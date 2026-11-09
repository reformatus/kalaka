<?php

declare(strict_types=1);

namespace Kalaka\Events\Cli;

use Kalaka\Events\ArchiveEvent;
use Kalaka\Events\CreateEvent;
use Kalaka\Events\PublishEvent;
use Kalaka\Events\UnpublishEvent;
use Kalaka\Events\UpdateEvent;
use Kalaka\Foundation\Api\Seed\Seeder;
use Kalaka\Foundation\Api\Seed\Seeding;
use Kalaka\Foundation\Api\UseCase\UseCase;

/**
 * Five sample events, one in each place of the lifecycle, with every point at a local time on a day
 * relative to the seed day.
 * These names are seed data, not UI strings.
 */
final class EventSeeder implements Seeder
{
    public function seed(Seeding $seeding): void
    {
        $today = $seeding->today();
        $at = static fn (int $offset, string $time): string
            => $today->modify(sprintf('%+d days', $offset))->format('Y-m-d') . ' ' . $time;

        $this->event($seeding, 'Nyári tábor', [
            'capacity' => 40,
            'registration_opens_at' => $at(-14, '08:00'),
            'registration_closes_at' => $at(30, '20:00'),
            'starts_at' => $at(60, '10:00'),
            'ends_at' => $at(66, '14:00'),
            'points' => [['name' => 'Előleg határideje', 'at' => $at(21, '20:00')]],
        ], [new PublishEvent()]);

        $this->event($seeding, 'Őszi hétvége', [
            'capacity' => 25,
            'registration_opens_at' => $at(30, '08:00'),
            'registration_closes_at' => $at(60, '20:00'),
            'starts_at' => $at(75, '10:00'),
            'ends_at' => $at(77, '14:00'),
            'points' => [],
        ], [new PublishEvent()]);

        $this->event($seeding, 'Téli tábor', null, []);

        $this->event($seeding, 'Tavaszi találkozó', [
            'capacity' => 30,
            'registration_opens_at' => $at(-90, '08:00'),
            'registration_closes_at' => $at(-60, '20:00'),
            'starts_at' => $at(-45, '10:00'),
            'ends_at' => $at(-44, '14:00'),
            'points' => [],
        ], [new PublishEvent(), new UnpublishEvent()]);

        $this->event($seeding, 'Tavalyi nyári tábor', [
            'capacity' => 50,
            'registration_opens_at' => $at(-420, '08:00'),
            'registration_closes_at' => $at(-380, '20:00'),
            'starts_at' => $at(-360, '10:00'),
            'ends_at' => $at(-354, '14:00'),
            'points' => [],
        ], [new PublishEvent(), new UnpublishEvent(), new ArchiveEvent()]);
    }

    /**
     * Creates a draft, fills it when it has fields, then moves it along the given transitions. Each
     * write carries the version the previous one left.
     *
     * @param array<string, mixed>|null $fields
     * @param list<UseCase> $transitions
     */
    private function event(Seeding $seeding, string $name, ?array $fields, array $transitions): void
    {
        $id = $seeding->run(new CreateEvent(), ['name' => $name]);
        $version = 1;

        if ($fields !== null) {
            $seeding->run(new UpdateEvent(), ['id' => $id, 'version' => $version, 'name' => $name] + $fields);
            $version++;
        }
        foreach ($transitions as $transition) {
            $seeding->run($transition, ['id' => $id, 'version' => $version]);
            $version++;
        }
    }
}
