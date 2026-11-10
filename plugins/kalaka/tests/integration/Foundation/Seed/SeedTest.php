<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation\Seed;

use Kalaka\Foundation\Api\Seed\Seeder;
use Kalaka\Foundation\Api\Seed\Seeding;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Foundation\Seed\Seed;
use Kalaka\Tests\Integration\Foundation\NamedProbe;

/** AC3, AC6 and the refusal half of AC7. */
final class SeedTest extends SeedTestCase
{
    public function testSeedingTwiceKeepsTheUsersOnceAndAddsTheEventsAgain(): void
    {
        $first = $this->seeded();
        $ids = $this->eventRows();
        $second = $this->seeded();
        $rows = $this->eventRows();

        self::assertCount(6, $this->seededLogins());
        self::assertSame(
            6,
            count(array_filter($first, static fn (string $l): bool => str_starts_with($l, 'Created user')))
        );
        self::assertSame(
            6,
            count(array_filter($second, static fn (string $l): bool => str_starts_with($l, 'Kept user')))
        );
        self::assertCount(10, $rows);
        self::assertSame(array_slice($rows, 0, 5), $ids);
        self::assertSame(
            array_map(static fn (array $row): array => [$row['name'], $row['state'], $row['points']], $ids),
            array_map(
                static fn (array $row): array => [$row['name'], $row['state'], $row['points']],
                array_slice($rows, 5)
            )
        );
        self::assertSame(10, count(array_unique(array_column($rows, 'id'))));
    }

    public function testAContributedSeederRunsAfterTheCoreOnesAsTheCliActorWithoutAUser(): void
    {
        $order = [];
        $this->contributions->seeder(new class ($order, 'first') implements Seeder {
            /** @param list<string> $order */
            public function __construct(private array &$order, private readonly string $label)
            {
            }

            public function seed(Seeding $seeding): void
            {
                $this->order[] = $this->label;
                $seeding->run(new NamedProbe(fn (): null => null), []);
            }
        });
        $this->contributions->seeder(new class ($order, 'second') implements Seeder {
            /** @param list<string> $order */
            public function __construct(private array &$order, private readonly string $label)
            {
            }

            public function seed(Seeding $seeding): void
            {
                $this->order[] = $this->label;
            }
        });

        $lines = $this->seeded();

        self::assertSame(['first', 'second'], $order);
        $seederLines = array_values(
            array_filter($lines, static fn (string $l): bool => str_starts_with($l, 'Running seeder'))
        );
        self::assertCount(3, $seederLines);
        self::assertStringContainsString('EventSeeder', $seederLines[0]);

        $entries = $this->entriesOf('kalaka/named-probe');
        self::assertCount(1, $entries);
        self::assertSame('cli', $entries[0]['actor_type']);
        self::assertNull($entries[0]['actor_user_id']);
        $all = $this->entries();
        self::assertSame('kalaka/named-probe', $all[count($all) - 1]['action']);
        self::assertSame(5, $this->eventCount());
    }

    public function testAClosedSiteRefusesTheSeedAndWritesNothing(): void
    {
        $this->closeTheSite();

        $message = $this->refusalOf(fn () => $this->seeded());

        self::assertSame('Refused: Kaláka did not load cleanly; run "wp kalaka seed --drop".', $message);
        self::assertSame([], $this->seededLogins());
        self::assertSame(0, $this->eventCount());
        self::assertSame([], $this->entries());
    }

    public function testTheDropOnAClosedSiteNeverAsksForTheRunner(): void
    {
        $this->closeTheSite();
        $asked = 0;
        $seed = new Seed([], $this->contributions, static function () use (&$asked): Runner {
            ++$asked;
            throw new \LogicException('Foundation never booted.');
        }, $this->seedClock);

        $message = $seed->run(true, 'local', static function (string $line): void {
        });

        self::assertSame('Schema recreated; run "wp kalaka seed" again.', $message);
        self::assertSame(0, $asked);
    }

    public function testARefusalOnAClosedSiteNeverAsksForTheRunner(): void
    {
        $this->closeTheSite();
        $asked = 0;
        $seed = new Seed([], $this->contributions, static function () use (&$asked): Runner {
            ++$asked;
            throw new \LogicException('Foundation never booted.');
        }, $this->seedClock);

        $message = $this->refusalOf(static fn () => $seed->run(false, 'local', static function (string $line): void {
        }));

        self::assertSame('Refused: Kaláka did not load cleanly; run "wp kalaka seed --drop".', $message);
        self::assertSame(0, $asked);
    }

    public function testAFailedUseCaseEndsTheSeedNamingIt(): void
    {
        $this->contributions->seeder(new class implements Seeder {
            public function seed(Seeding $seeding): void
            {
                $seeding->run(new \Kalaka\Events\PublishEvent(), ['id' => 999999, 'version' => 1]);
            }
        });

        $message = $this->refusalOf(fn () => $this->seeded());

        self::assertStringStartsWith('kalaka/publish-event: ', $message);
        self::assertSame(5, $this->eventCount());
    }

    /** @return list<array<string, ?string>> */
    private function eventRows(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}kalaka_events ORDER BY id", ARRAY_A);
        self::assertIsArray($rows);

        return array_values($rows);
    }
}
