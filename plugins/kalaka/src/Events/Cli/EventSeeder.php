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
 * Five sample events, one in each place of the lifecycle, with every date relative to the seed day.
 * These names are seed data, not UI strings.
 */
final class EventSeeder implements Seeder
{
    public function seed(Seeding $seeding): void
    {
        $today = $seeding->today();
        $day = static fn (int $offset): string => $today->modify(sprintf('%+d days', $offset))->format('Y-m-d');

        $this->event($seeding, 'Nyári tábor', [
            'capacity' => 40,
            'registration_opens_on' => $day(-14),
            'registration_closes_on' => $day(30),
            'starts_on' => $day(60),
            'ends_on' => $day(66),
            'points' => [['name' => 'Előleg határideje', 'date' => $day(21)]],
        ], [new PublishEvent()]);

        $this->event($seeding, 'Őszi hétvége', [
            'capacity' => 25,
            'registration_opens_on' => $day(30),
            'registration_closes_on' => $day(60),
            'starts_on' => $day(75),
            'ends_on' => $day(77),
            'points' => [],
        ], [new PublishEvent()]);

        $this->event($seeding, 'Téli tábor', null, []);

        $this->event($seeding, 'Tavaszi találkozó', [
            'capacity' => 30,
            'registration_opens_on' => $day(-90),
            'registration_closes_on' => $day(-60),
            'starts_on' => $day(-45),
            'ends_on' => $day(-44),
            'points' => [],
        ], [new PublishEvent(), new UnpublishEvent()]);

        $this->event($seeding, 'Tavalyi nyári tábor', [
            'capacity' => 50,
            'registration_opens_on' => $day(-420),
            'registration_closes_on' => $day(-380),
            'starts_on' => $day(-360),
            'ends_on' => $day(-354),
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
