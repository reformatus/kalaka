<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Admin;

use Kalaka\Foundation\Api\LoadState;
use Kalaka\Foundation\Api\Module\ActionCatalogue;
use Kalaka\Foundation\Boot;
use Kalaka\Tests\Integration\Foundation\RunnerTestCase;

/** S36-5: the action catalogue the boot publishes. */
final class ActionCatalogueTest extends RunnerTestCase
{
    private ActionCatalogue $catalogue;

    /** @var list<\Kalaka\Foundation\Api\LoadFailure> */
    private array $failures = [];

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        $this->catalogue = ActionCatalogue::current();
        $this->failures = LoadState::current()->failures;
        delete_option(Boot::FAILURE_OPTION);
        remove_all_actions('kalaka_register_modules');
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function tear_down(): void
    {
        ActionCatalogue::record($this->catalogue);
        LoadState::record($this->failures);
        delete_option(Boot::FAILURE_OPTION);

        parent::tear_down();
    }

    /** @param list<ActionsModule> $modules */
    private function boot(array $modules): Boot
    {
        $boot = new Boot($modules);
        $this->capturedErrorLog(static function () use ($boot): void {
            $boot->run();
        });

        return $boot;
    }

    public function testTheCoreModulesOfferTheLaunchCatalogue(): void
    {
        self::assertSame(
            [
                'kalaka_withdraw_registration',
                'kalaka_remove_registration',
                'kalaka_admit_registration',
                'kalaka_regenerate_link',
                'kalaka_record_payment',
                'kalaka_record_refund',
                'kalaka_notes',
                'kalaka_audit_history',
            ],
            $this->catalogue->names()
        );
    }

    public function testTheCatalogueIsEmptyBeforeTheBootHasFinished(): void
    {
        self::assertSame([], (new ActionCatalogue())->names());
    }

    public function testTheCatalogueHoldsEachModulesActionsOnce(): void
    {
        $boot = $this->boot([
            new ActionsModule('first', ['kalaka_withdraw_registration', 'kalaka_edit_fields']),
            new ActionsModule('second', ['kalaka_record_payment', 'kalaka_a1']),
            new ActionsModule('third', []),
        ]);

        self::assertSame([], $boot->failures());
        self::assertTrue(LoadState::current()->isOpen());
        self::assertSame(
            ['kalaka_withdraw_registration', 'kalaka_edit_fields', 'kalaka_record_payment', 'kalaka_a1'],
            ActionCatalogue::current()->names()
        );
    }

    /** @return iterable<string, array{string}> */
    public static function malformedNames(): iterable
    {
        yield 'no prefix' => ['withdraw_registration'];
        yield 'capitals' => ['kalaka_Withdraw'];
        yield 'nothing after the prefix' => ['kalaka_'];
        yield 'a digit first' => ['kalaka_1st'];
        yield 'a hyphen' => ['kalaka_with-draw'];
        yield 'a slash' => ['kalaka/withdraw'];
        yield 'a trailing newline' => ["kalaka_withdraw\n"];
        yield 'an accent' => ['kalaka_átlag'];
        yield 'empty' => [''];
        yield '65 characters' => ['kalaka_' . str_repeat('a', 58)];
    }

    /** @dataProvider malformedNames */
    public function testAMalformedActionNameIsALoadFailureThatClosesTheSite(string $name): void
    {
        $boot = $this->boot([new ActionsModule('bad', ['kalaka_fine', $name])]);

        self::assertCount(1, $boot->failures());
        self::assertSame('bad', $boot->failures()[0]->moduleId);
        self::assertFalse(LoadState::current()->isOpen());
        self::assertSame([], ActionCatalogue::current()->names());
    }

    public function testAnActionNameOf64CharactersIsAccepted(): void
    {
        $name = 'kalaka_' . str_repeat('a', 57);
        $boot = $this->boot([new ActionsModule('long', [$name])]);

        self::assertSame([], $boot->failures());
        self::assertSame([$name], ActionCatalogue::current()->names());
    }

    public function testADuplicateNameIsALoadFailureThatClosesTheSite(): void
    {
        $inOneModule = $this->boot([new ActionsModule('twice', ['kalaka_edit_fields', 'kalaka_edit_fields'])]);

        self::assertCount(1, $inOneModule->failures());
        self::assertFalse(LoadState::current()->isOpen());
        self::assertSame([], ActionCatalogue::current()->names());

        $acrossModules = $this->boot([
            new ActionsModule('first', ['kalaka_edit_fields', 'kalaka_a1']),
            new ActionsModule('second', ['kalaka_b1', 'kalaka_edit_fields']),
        ]);

        self::assertCount(1, $acrossModules->failures());
        self::assertSame('second', $acrossModules->failures()[0]->moduleId);
        self::assertFalse(LoadState::current()->isOpen());
        self::assertSame(['kalaka_edit_fields', 'kalaka_a1'], ActionCatalogue::current()->names());
    }
}
