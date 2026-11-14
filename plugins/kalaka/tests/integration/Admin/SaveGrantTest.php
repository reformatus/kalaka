<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Admin;

use Kalaka\Admin\Grant\DeleteGrant;
use Kalaka\Admin\Grant\SaveGrant;
use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\PermissionDenied;
use Kalaka\Foundation\Api\Error\VersionConflict;

/** AC2: saving and deleting a grant. */
final class SaveGrantTest extends GrantTestCase
{
    /** @return list<array<string, ?string>> */
    private function entriesOf(string $action): array
    {
        return array_values(array_filter(
            $this->entries(),
            static fn (array $entry): bool => $entry['action'] === $action
        ));
    }

    public function testANewGrantIsStoredWithVersionOneAndAudited(): void
    {
        $event = $this->createEvent();
        $view = $this->parameterView($event);
        $user = $this->plainUser();

        $id = $this->saveGrant($event, $user, [
            'view_id' => $view,
            'bound_value' => 'építő',
            'actions' => [self::WITHDRAW, self::EDIT, self::EDIT],
        ]);

        $row = $this->grantRow($id);
        self::assertSame(
            [
                (string) $event,
                '1',
                (string) $user,
                (string) $view,
                'építő',
                '["' . self::EDIT . '","' . self::WITHDRAW . '"]',
            ],
            [$row['event_id'], $row['version'], $row['user_id'], $row['view_id'], $row['bound_value'], $row['actions']]
        );

        $entries = $this->entriesOf('kalaka/save-grant');
        self::assertCount(1, $entries);
        self::assertSame('succeeded', $entries[0]['outcome']);
        self::assertSame("grant/{$event}/{$id}", $this->subjectsOf($entries[0]['id'])[0]);
        $changes = [];
        foreach ($this->decode($entries[0]['changes']) as $change) {
            self::assertIsArray($change);
            $changes[(string) $change['field']] = $change['after'];
        }
        self::assertSame((string) $user, (string) $changes['user_id']);
        self::assertSame('építő', $changes['bound_value']);
    }

    public function testAGrantOnTheMasterViewHasNoViewAndNoBoundValue(): void
    {
        $event = $this->createEvent();

        $id = $this->saveGrant($event, $this->plainUser(), ['actions' => []]);

        $row = $this->grantRow($id);
        self::assertNull($row['view_id']);
        self::assertNull($row['bound_value']);
        self::assertSame('[]', $row['actions']);
    }

    public function testAnUpdateWithTheCurrentVersionChangesTheGrantAndAStaleOneIsAVersionConflict(): void
    {
        $event = $this->createEvent();
        $user = $this->plainUser();
        $id = $this->saveGrant($event, $user);

        $this->exec(new SaveGrant(), $this->grantInput($event, $user, [
            'id' => $id,
            'version' => 1,
            'actions' => [self::WITHDRAW],
        ]));

        $row = $this->grantRow($id);
        self::assertSame(['2', '["' . self::WITHDRAW . '"]'], [$row['version'], $row['actions']]);
        $entries = $this->entriesOf('kalaka/save-grant');
        self::assertCount(2, $entries);
        $changes = $this->decode($entries[1]['changes']);
        self::assertCount(1, $changes);
        self::assertIsArray($changes[0]);
        self::assertSame('actions', $changes[0]['field']);

        try {
            $this->exec(new SaveGrant(), $this->grantInput($event, $user, [
                'id' => $id,
                'version' => 1,
                'actions' => [self::EDIT],
            ]));
            self::fail('The run should have thrown.');
        } catch (VersionConflict) {
            $row = $this->grantRow($id);
            self::assertSame(['2', '["' . self::WITHDRAW . '"]'], [$row['version'], $row['actions']]);
        }
    }

    public function testDeletingAGrantRemovesItAndAudits(): void
    {
        $event = $this->createEvent();
        $id = $this->saveGrant($event, $this->plainUser());

        $this->exec(new DeleteGrant(), ['id' => $id]);

        self::assertSame(0, $this->grantCount());
        $entries = $this->entriesOf('kalaka/delete-grant');
        self::assertCount(1, $entries);
        self::assertSame('succeeded', $entries[0]['outcome']);
        self::assertSame("grant/{$event}/{$id}", $this->subjectsOf($entries[0]['id'])[0]);
    }

    public function testDeletingOrUpdatingAGrantThatDoesNotExistIsNotFound(): void
    {
        $event = $this->createEvent();
        $user = $this->plainUser();
        $id = $this->saveGrant($event, $user);

        foreach (
            [
            [new DeleteGrant(), ['id' => $id + 1000]],
            [new SaveGrant(), $this->grantInput($event, $user, ['id' => $id + 1000, 'version' => 1])],
            [new SaveGrant(), $this->grantInput($this->createEvent('Másik'), $user, ['id' => $id, 'version' => 1])],
            ] as [$useCase, $input]
        ) {
            try {
                $this->exec($useCase, $input);
                self::fail('The run should have thrown.');
            } catch (NotFound) {
                self::assertSame('1', $this->grantRow($id)['version']);
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidInputs(): iterable
    {
        yield 'an action outside the catalogue' => ['unknown action'];
        yield 'a bound value for a view without a parameter' => ['bound without parameter'];
        yield 'a bound value for the master view' => ['bound for master'];
        yield 'no bound value for a view with a parameter' => ['parameter without bound'];
        yield 'a view of another event' => ['view of another event'];
        yield 'a view that does not exist' => ['no such view'];
        yield 'a user who does not exist' => ['no such user'];
        yield 'an id without a version' => ['id without version'];
        yield 'an empty bound value' => ['empty bound value'];
    }

    /** @dataProvider invalidInputs */
    public function testInvalidInputIsRefusedAndNothingIsStored(string $case): void
    {
        $event = $this->createEvent();
        $other = $this->createEvent('Másik');
        $plain = $this->plainView($event);
        $parameter = $this->parameterView($event);
        $foreign = $this->plainView($other);
        $user = $this->plainUser();
        $existing = $this->saveGrant($event, $user);
        $before = $this->grantCount();

        $overrides = match ($case) {
            'unknown action' => ['actions' => ['kalaka_nothing']],
            'bound without parameter' => ['view_id' => $plain, 'bound_value' => 'x'],
            'bound for master' => ['bound_value' => 'x'],
            'parameter without bound' => ['view_id' => $parameter],
            'view of another event' => ['view_id' => $foreign],
            'no such view' => ['view_id' => $parameter + 1000],
            'no such user' => ['user_id' => $user + 1000],
            'id without version' => ['id' => $existing],
            'empty bound value' => ['view_id' => $parameter, 'bound_value' => ''],
        };

        try {
            $this->exec(new SaveGrant(), $this->grantInput($event, $user, $overrides));
            self::fail('The run should have thrown.');
        } catch (InvalidInput) {
            self::assertSame($before, $this->grantCount());
            self::assertSame('1', $this->grantRow($existing)['version']);
        }
    }

    public function testAnUpdateAlsoChecksTheNewData(): void
    {
        $event = $this->createEvent();
        $user = $this->plainUser();
        $id = $this->saveGrant($event, $user);

        $this->expectException(InvalidInput::class);
        $this->exec(new SaveGrant(), $this->grantInput($event, $user, [
            'id' => $id,
            'version' => 1,
            'actions' => ['kalaka_nothing'],
        ]));
    }

    public function testAUserWithoutFullAccessIsRefusedAndNothingChanges(): void
    {
        $event = $this->createEvent();
        $user = $this->plainUser();
        $id = $this->saveGrant($event, $user);
        // Even a grant on the event with every action does not open a site-level use case.
        $this->saveGrant($event, $user, ['actions' => [self::EDIT, self::WITHDRAW]]);
        $before = $this->grantCount();
        $actor = new UserActor($user);

        foreach (
            [
            [new SaveGrant(), $this->grantInput($event, $user, ['actions' => []])],
            [new DeleteGrant(), ['id' => $id]],
            ] as [$useCase, $input]
        ) {
            try {
                $this->exec($useCase, $input, $actor);
                self::fail('The run should have thrown.');
            } catch (PermissionDenied) {
                self::assertSame($before, $this->grantCount());
            }
        }
    }
}
