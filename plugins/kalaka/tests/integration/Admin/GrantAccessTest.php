<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Admin;

use Kalaka\Foundation\Api\Actor\Actor;
use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\Error\PermissionDenied;
use Kalaka\Foundation\Api\Module\ActionCatalogue;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Tests\Integration\Foundation\AdminProbe;

/** AC4 and AC5: the coarse check's grant path. */
final class GrantAccessTest extends GrantTestCase
{
    private int $event;

    private int $user;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        $this->event = $this->createEvent();
        $this->user = $this->plainUser();
        $this->addRegistration($this->event, '00A0');
        $this->addValue($this->event, '00A0', 'module', 'építő');
        $this->addRegistration($this->event, '00A2');
        $this->addValue($this->event, '00A2', 'module', 'konyha');
    }

    /** @param list<string> $codes */
    private function probe(?string $action, ?int $event, array $codes, int $inEvent = 0): AdminProbe
    {
        return new AdminProbe(
            static fn (): string => 'ran',
            event: $event,
            targets: array_map(
                static fn (string $code): RegistrationKey => new RegistrationKey($inEvent ?: ($event ?? 0), $code),
                $codes
            ),
            action: $action,
        );
    }

    private function passes(AdminProbe $useCase, ?Actor $actor = null): bool
    {
        try {
            self::assertSame('ran', $this->runner->run($useCase, [], $actor ?? new UserActor($this->user)));
        } catch (PermissionDenied) {
            self::assertSame(0, $useCase->calls);

            return false;
        }

        return true;
    }

    private function moduleGrant(string $module, array $actions = [self::EDIT], ?int $user = null): int
    {
        return $this->saveGrant($this->event, $user ?? $this->user, [
            'view_id' => $this->parameterView($this->event),
            'bound_value' => $module,
            'actions' => $actions,
        ]);
    }

    public function testAGrantWithTheActionAndATargetInsideTheFilterPasses(): void
    {
        $this->moduleGrant('építő');

        self::assertTrue($this->passes($this->probe(self::EDIT, $this->event, ['00A0'])));
    }

    public function testTheBoundValueIsComparedAsTheFilterComparesIt(): void
    {
        $this->moduleGrant('ÉPÍTŐ');

        self::assertTrue($this->passes($this->probe(self::EDIT, $this->event, ['00A0'])));
    }

    public function testTheSameGrantWithoutTheActionIsRefused(): void
    {
        $this->moduleGrant('építő', [self::WITHDRAW]);

        self::assertFalse($this->passes($this->probe(self::EDIT, $this->event, ['00A0'])));
    }

    public function testATargetOutsideTheFilterIsRefusedEvenWithAnotherTargetInside(): void
    {
        $this->moduleGrant('építő');

        self::assertFalse($this->passes($this->probe(self::EDIT, $this->event, ['00A2'])));
        self::assertFalse($this->passes($this->probe(self::EDIT, $this->event, ['00A0', '00A2'])));
        self::assertFalse($this->passes($this->probe(self::EDIT, $this->event, ['00A0', '00B3'])));
    }

    public function testAGrantOnAnotherEventOnlyIsRefused(): void
    {
        $other = $this->createEvent('Másik');
        $this->saveGrant($other, $this->user);

        self::assertFalse($this->passes($this->probe(self::EDIT, $this->event, ['00A0'])));
    }

    public function testAnotherUsersGrantIsRefused(): void
    {
        $this->saveGrant($this->event, $this->plainUser());

        self::assertFalse($this->passes($this->probe(self::EDIT, $this->event, ['00A0'])));
    }

    public function testAUseCaseWithoutAnActionPassesWithAnyGrantWhoseFilterHoldsTheTargets(): void
    {
        $this->moduleGrant('építő', []);

        self::assertTrue($this->passes($this->probe(null, $this->event, ['00A0'])));
        self::assertFalse($this->passes($this->probe(null, $this->event, ['00A2'])));
        self::assertFalse($this->passes($this->probe(null, $this->event, ['00A0', '00A2'])));
    }

    /** A1, S36-3: an event use case with no action and no target is full access only. */
    public function testAnEventUseCaseWithNoActionAndNoTargetIsRefusedToEveryGrant(): void
    {
        $this->saveGrant($this->event, $this->user, ['actions' => [self::EDIT, self::WITHDRAW]]);
        $this->moduleGrant('építő', [self::EDIT, self::WITHDRAW]);

        self::assertFalse($this->passes($this->probe(null, $this->event, [])));
        self::assertTrue($this->passes($this->probe(null, $this->event, []), $this->admin));
        // With an action the grant carries, no target is needed.
        self::assertTrue($this->passes($this->probe(self::WITHDRAW, $this->event, [])));
    }

    /** A2, S36-4: a bound grant whose view lost its parameter holds nothing; it never widens. */
    public function testABoundGrantWhoseViewLostItsParameterHoldsNothing(): void
    {
        $this->moduleGrant('építő');
        self::assertTrue($this->passes($this->probe(self::EDIT, $this->event, ['00A0'])));

        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}kalaka_views SET filter = %s WHERE event_id = %d",
            '[{"column":"field:module","in":["építő","konyha"]}]',
            $this->event
        ));

        self::assertFalse($this->passes($this->probe(self::EDIT, $this->event, ['00A0'])));
        self::assertFalse($this->passes($this->probe(self::EDIT, $this->event, ['00A2'])));
    }

    public function testASecondGrantOfTheUserCanCarryWhatTheFirstDoesNot(): void
    {
        $this->moduleGrant('építő', [self::WITHDRAW]);
        $this->moduleGrant('konyha', [self::EDIT]);

        self::assertFalse($this->passes($this->probe(self::EDIT, $this->event, ['00A0'])));
        self::assertTrue($this->passes($this->probe(self::EDIT, $this->event, ['00A2'])));
        self::assertTrue($this->passes($this->probe(self::WITHDRAW, $this->event, ['00A0'])));
    }

    public function testAGrantOnTheMasterViewHoldsEveryRegistrationOfItsEventAndNoOtherEvent(): void
    {
        $this->saveGrant($this->event, $this->user);
        $other = $this->createEvent('Másik');
        $this->addRegistration($other, '00B0');

        self::assertTrue($this->passes($this->probe(self::EDIT, $this->event, ['00A0', '00A2'])));
        self::assertTrue($this->passes($this->probe(self::EDIT, $this->event, [])));
        self::assertFalse($this->passes($this->probe(self::EDIT, $this->event, ['00B0'], $other)));
        self::assertFalse($this->passes($this->probe(self::EDIT, $other, ['00B0'])));
    }

    public function testASiteLevelUseCaseIsRefusedWhateverTheGrants(): void
    {
        $this->saveGrant($this->event, $this->user, ['actions' => [self::EDIT, self::WITHDRAW]]);

        self::assertFalse($this->passes($this->probe(null, null, [])));
        self::assertFalse($this->passes($this->probe(self::EDIT, null, [])));
    }

    public function testAnActionThatLeftTheCatalogueCountsAsAbsent(): void
    {
        $this->saveGrant($this->event, $this->user);
        self::assertTrue($this->passes($this->probe(self::EDIT, $this->event, ['00A0'])));

        ActionCatalogue::record(new ActionCatalogue([self::WITHDRAW]));

        self::assertFalse($this->passes($this->probe(self::EDIT, $this->event, ['00A0'])));
    }

    public function testACliActorWithTheUserIsCheckedTheSameWay(): void
    {
        $this->moduleGrant('építő');
        $cli = new CliActor($this->user);

        self::assertTrue($this->passes($this->probe(self::EDIT, $this->event, ['00A0']), $cli));
        self::assertFalse($this->passes($this->probe(self::EDIT, $this->event, ['00A2']), $cli));
        self::assertFalse($this->passes($this->probe(self::WITHDRAW, $this->event, ['00A0']), $cli));
        self::assertFalse($this->passes($this->probe(null, null, []), $cli));
    }

    public function testAGrantWhoseViewGainedAParameterHoldsNothing(): void
    {
        $view = $this->plainView($this->event);
        $this->saveGrant($this->event, $this->user, ['view_id' => $view]);
        self::assertTrue($this->passes($this->probe(self::EDIT, $this->event, ['00A0'])));

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'kalaka_views',
            ['filter' => '[{"column":"field:module","parameter":true}]'],
            ['id' => $view]
        );

        self::assertFalse($this->passes($this->probe(self::EDIT, $this->event, ['00A0'])));
    }

    public function testAUserWithoutAnyGrantOrWithFullAccessBehavesAsBefore(): void
    {
        self::assertFalse($this->passes($this->probe(self::EDIT, $this->event, ['00A0'])));
        self::assertTrue($this->passes($this->probe(self::EDIT, $this->event, ['00A0']), $this->admin));
        self::assertTrue($this->passes($this->probe(null, null, []), $this->admin));
    }

    public function testTwelveModuleLeadersEachPassForTheirOwnModuleOnly(): void
    {
        $modules = [];
        $codes = ['00B6', '00B9', '00E2', '00E5', '00E8', '00F0', '00F3', '00F6', '00F8', '00G1', '00G4', '00G7'];
        foreach ($codes as $index => $code) {
            $modules[$code] = sprintf('modul %02d', $index + 1);
        }
        foreach ($modules as $code => $module) {
            $this->addRegistration($this->event, $code);
            $this->addValue($this->event, $code, 'module', $module);
        }

        // One view filtered on the module with the parameter, bound to each leader's own module.
        $view = $this->parameterView($this->event);
        $leaders = [];
        foreach ($modules as $code => $module) {
            $leaders[$code] = $this->plainUser();
            $this->saveGrant($this->event, $leaders[$code], [
                'view_id' => $view,
                'bound_value' => $module,
                'actions' => [self::EDIT],
            ]);
        }

        foreach ($leaders as $code => $leader) {
            $actor = new UserActor($leader);
            self::assertTrue($this->passes($this->probe(self::EDIT, $this->event, [$code]), $actor), $code);
            foreach (array_keys($modules) as $another) {
                if ($another !== $code) {
                    $probe = $this->probe(self::EDIT, $this->event, [$another]);
                    self::assertFalse($this->passes($probe, $actor), "$code $another");
                }
            }
        }
    }
}
