<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Events;

use Kalaka\Events\ArchiveEvent;
use Kalaka\Events\CreateEvent;
use Kalaka\Events\PublishEvent;
use Kalaka\Events\ReopenEvent;
use Kalaka\Events\UnpublishEvent;
use Kalaka\Events\UpdateEvent;
use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\Error\PermissionDenied;

/** AC12: every Events use case needs full access. */
final class EventAccessTest extends EventsTestCase
{
    public function testAUserWithoutFullAccessIsRefusedByEveryUseCaseAndNothingChanges(): void
    {
        $id = $this->published();
        $this->markFull($id);
        $before = $this->row($id);
        $version = (int) $before['version'];
        $user = new UserActor(self::factory()->user->create());
        $rows = $this->eventCount();

        $attempts = [
            [new CreateEvent(), ['name' => 'Új']],
            [new UpdateEvent(), $this->updateInput($id, $version, ['name' => 'Másik'])],
            [new PublishEvent(), ['id' => $id, 'version' => $version]],
            [new UnpublishEvent(), ['id' => $id, 'version' => $version]],
            [new ArchiveEvent(), ['id' => $id, 'version' => $version]],
            [new ReopenEvent(), ['id' => $id, 'version' => $version]],
        ];
        foreach ($attempts as [$useCase, $input]) {
            try {
                $this->exec($useCase, $input, $user);
                self::fail($useCase::name() . ' should have been refused.');
            } catch (PermissionDenied) {
                self::assertSame($before, $this->row($id));
                self::assertSame($rows, $this->eventCount());
            }
        }
    }

    public function testTheCliWithoutAUserMayRunThem(): void
    {
        $id = $this->exec(new CreateEvent(), ['name' => 'Tábor'], new CliActor(null));

        self::assertIsInt($id);
        self::assertSame('Tábor', $this->row($id)['name']);
    }
}
