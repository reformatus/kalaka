<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Forms;

use Kalaka\Forms\Api\EventValues;
use Kalaka\Forms\Api\InvalidAnswers;
use Kalaka\Forms\Api\Value;
use Kalaka\Forms\Api\ValueContext;
use Kalaka\Forms\Api\ValueType;

/** A shown consent left unticked stores `0`, a consent that never showed stores nothing (owner ruling 2026-11-18). */
final class FormConsentTest extends FormsTestCase
{
    private function facts(): ValueContext
    {
        return new ValueContext([
            EventValues::NAME => Value::text('Teszt tábor', 'Teszt tábor'),
            EventValues::REGISTRATION_OPENS => Value::none(ValueType::Date),
            EventValues::REGISTRATION_CLOSES => Value::none(ValueType::Date),
            EventValues::START => Value::date('2027-07-10', '2027-07-10'),
            EventValues::END => Value::none(ValueType::Date),
        ]);
    }

    /** @return array<string, mixed> */
    private function basics(): array
    {
        return ['name' => 'Teszt Elek', 'email' => 'teszt@example.org'];
    }

    private function content(bool $required = false): string
    {
        $when = ['condition' => ['match' => 'all', 'items' => [
            ['key' => 'photo', 'operator' => 'empty', 'not' => true],
        ]]];

        return $this->slotFields()
            . $this->field('consent', 'photo', ['required' => $required])
            . '<!-- wp:kalaka/if ' . wp_json_encode($when) . ' -->'
            . $this->field('consent', 'extra')
            . '<!-- /wp:kalaka/if -->';
    }

    public function testAShownConsentLeftUntickedStoresZeroAndATickedOneStoresOne(): void
    {
        $definition = $this->forms->definition($this->readForm($this->insertForm($this->content())));

        $unticked = $this->forms->validate(
            $definition,
            $this->basics() + ['photo' => false, 'extra' => false],
            $this->facts()
        );
        $ticked = $this->forms->validate(
            $definition,
            $this->basics() + ['photo' => true, 'extra' => true],
            $this->facts()
        );

        self::assertSame('0', $unticked->stored()['photo']);
        self::assertSame('1', $ticked->stored()['photo']);
    }

    public function testAConsentInAHiddenBranchStoresNothing(): void
    {
        $content = $this->slotFields()
            . $this->field('consent', 'photo')
            . '<!-- wp:kalaka/if ' . wp_json_encode(['condition' => ['match' => 'all', 'items' => [
                ['key' => 'photo', 'operator' => 'equals', 'value' => '1'],
            ]]]) . ' -->'
            . $this->field('consent', 'extra')
            . '<!-- /wp:kalaka/if -->';
        $definition = $this->forms->definition($this->readForm($this->insertForm($content)));

        $valid = $this->forms->validate(
            $definition,
            $this->basics() + ['photo' => false, 'extra' => false],
            $this->facts()
        );

        self::assertSame('0', $valid->stored()['photo']);
        self::assertArrayNotHasKey('extra', $valid->stored());
    }

    public function testAnUntickedConsentIsNotEmptyForABranch(): void
    {
        // `extra` shows when `photo` is not empty: an unticked `photo` ('0') is an answer, so it shows
        $definition = $this->forms->definition($this->readForm($this->insertForm($this->content())));

        $valid = $this->forms->validate(
            $definition,
            $this->basics() + ['photo' => false, 'extra' => false],
            $this->facts()
        );

        self::assertSame('0', $valid->stored()['extra']);
    }

    public function testARequiredConsentStillMustBeTicked(): void
    {
        $definition = $this->forms->definition($this->readForm($this->insertForm($this->content(true))));

        $this->expectException(InvalidAnswers::class);
        $this->forms->validate($definition, $this->basics() + ['photo' => false], $this->facts());
    }
}
