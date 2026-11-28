<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Admin\FieldEdit;

use Kalaka\Admin\FieldEdit\FieldEditInput;
use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Tests\Integration\Forms\FormsTestCase;

/** A multiple choice held by a form takes a JSON list, as the command line sends one. */
final class FieldEditInputMultipleTest extends FormsTestCase
{
    private int $event;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        $this->event = $this->create('Tábor');
        $options = [];
        foreach (['a', 'b', 'c'] as $value) {
            $options[] = ['value' => $value, 'label' => strtoupper($value)];
        }
        $this->insertChecked(
            $this->slotFields() . $this->field('choice', 'interests', ['multiple' => true, 'options' => $options]),
            ['kalaka_event_id' => $this->event]
        );
    }

    private function stored(mixed $value): ?string
    {
        return (new FieldEditInput())->prepare([
            'event_id' => $this->event,
            'code' => '00A0',
            'field' => 'interests',
            'value' => $value,
            'reason' => 'Elírás',
        ], new CliActor(null))->stored;
    }

    public function testAJsonListIsTriedAfterTheStringAnswerIsRefused(): void
    {
        self::assertSame('["a","b"]', $this->stored('["a","b"]'));
        self::assertSame('["a","c"]', $this->stored(['c', 'a']));
    }

    public function testABadListOrAPlainStringIsRefused(): void
    {
        foreach (['["a","zzz"]', 'a', '[1]'] as $value) {
            try {
                $this->stored($value);
                self::fail('The value should have been refused.');
            } catch (InvalidInput) {
                self::assertTrue(true);
            }
        }
    }
}
