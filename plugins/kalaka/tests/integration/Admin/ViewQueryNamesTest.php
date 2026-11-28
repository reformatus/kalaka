<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Admin;

use Kalaka\Admin\Api\HeldView;
use Kalaka\Admin\Api\ViewQuery;
use Kalaka\Admin\View\ViewShape;
use Kalaka\Foundation\Api\Error\InvalidInput;

/**
 * The display names of a code list through the view (`ViewQuery::namesOf()`), the owner's ruling 2026-11-26:
 * a viewer with name access sees the name, one without sees null, and more than 100 codes is refused.
 */
final class ViewQueryNamesTest extends RegistrationDetailTestCase
{
    private ViewQuery $query;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        $this->query = new ViewQuery();
    }

    /** A registration 00A0 on the slot form, with its name answered. */
    private function seed(): void
    {
        $form = $this->detailForm();
        $this->addRegistration('00A0');
        $this->addSubmission('00A0', $form->id, $form->version);
        $this->addValue('00A0', 'name', 'Teszt Elek');
        $this->addValue('00A0', 'email', 'teszt.elek@example.com');
    }

    /** @param list<array{column: string, write: bool}> $columns */
    private function view(array $columns, array $filter = []): HeldView
    {
        return new HeldView($this->event, null, null, null, ViewShape::of($filter, $columns), null);
    }

    private function withName(): HeldView
    {
        return $this->view([
            ['column' => 'code', 'write' => false],
            ['column' => 'field:name', 'write' => false],
        ]);
    }

    private function withoutName(): HeldView
    {
        return $this->view([['column' => 'code', 'write' => false]]);
    }

    public function testANameIsShownWhenTheViewListsTheNameColumn(): void
    {
        $this->seed();

        self::assertSame(['00A0' => 'Teszt Elek'], $this->query->namesOf($this->withName(), ['00A0']));
    }

    public function testANameIsNullWithoutNameAccess(): void
    {
        $this->seed();

        self::assertSame(['00A0' => null], $this->query->namesOf($this->withoutName(), ['00A0']));
    }

    public function testANameIsNullForACodeOutsideTheViewsFilter(): void
    {
        $this->seed();
        $view = $this->view(
            [['column' => 'code', 'write' => false], ['column' => 'field:name', 'write' => false]],
            [['column' => 'status', 'in' => ['waitlisted']]]
        );

        self::assertSame(['00A0' => null], $this->query->namesOf($view, ['00A0']));
    }

    public function testANameIsNullForACodeThatIsNoRegistration(): void
    {
        $this->seed();

        self::assertSame(['00B0' => null], $this->query->namesOf($this->withName(), ['00B0']));
    }

    public function testMoreThanOneHundredCodesIsInvalidInput(): void
    {
        $codes = array_map(
            static fn (int $i): string => str_pad((string) $i, 4, '0', STR_PAD_LEFT),
            range(1, ViewQuery::MAX_NAMES + 1)
        );

        $this->expectException(InvalidInput::class);
        $this->query->namesOf($this->withName(), $codes);
    }
}
