<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\ParticipantFields;

use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\ParticipantFields\Api\FieldKeyHeld;
use Kalaka\ParticipantFields\Api\ParticipantField;
use Kalaka\ParticipantFields\Api\ParticipantFields;
use Kalaka\Tests\Integration\Forms\FormsTestCase;
use Kalaka\Tests\Integration\Foundation\AdminProbe;

/**
 * The one check that a code-declared key is free for a declarer (`ParticipantFields::checkDeclarable()`, the rule
 * `DeclareExtensionField` also applies), the declarer a field stores, and the money-relevant flag a declaration sets.
 */
final class DeclarableFieldTest extends FormsTestCase
{
    private const EVENT = 7;
    private const TEXT = 'kalaka/text-field';

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names it
    public function set_up(): void
    {
        parent::set_up();

        wp_set_current_user($this->fullAccessUser());
    }

    private function declareAs(
        string $key,
        string $declarer,
        string $type = self::TEXT,
        bool $moneyRelevant = false,
    ): void {
        $this->runner->run(
            new AdminProbe(
                static function (array $input, Context $context) use ($key, $declarer, $type, $moneyRelevant): void {
                    (new ParticipantFields())->declareExtensionField(
                        $context,
                        self::EVENT,
                        $key,
                        $type,
                        'Cím',
                        null,
                        true,
                        $declarer,
                        null,
                        $moneyRelevant
                    );
                }
            ),
            [],
            new CliActor(null)
        );
    }

    private function seedFieldRow(string $key, string $source, ?string $declaredBy = null): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'kalaka_participant_fields', [
            'event_id' => self::EVENT,
            'field_key' => $key,
            'version' => 1,
            'type' => self::TEXT,
            'title' => $key,
            'source' => $source,
            'created_at' => '2026-11-13 08:00:00.000000',
            'updated_at' => '2026-11-13 08:00:00.000000',
            'position' => 1,
            'form_writable' => 1,
            'declared_by' => $declaredBy,
        ]);
    }

    private function declarerOf(string $key): ?string
    {
        global $wpdb;
        $value = $wpdb->get_var($wpdb->prepare(
            'SELECT declared_by FROM ' . $wpdb->prefix . 'kalaka_participant_fields'
            . ' WHERE event_id = %d AND field_key = %s',
            self::EVENT,
            $key
        ));

        return is_string($value) ? $value : null;
    }

    private function storedField(string $key): ParticipantField
    {
        foreach ((new ParticipantFields())->of(self::EVENT, true) as $field) {
            if ($field->key === $key) {
                return $field;
            }
        }
        self::fail('No field ' . $key);
    }

    public function testAFreeKeyAFormFieldOfTheSameTypeAndTheSameOrNoDeclarerAreDeclarable(): void
    {
        $fields = new ParticipantFields();
        $this->seedFieldRow('form_key', 'form');
        $this->seedFieldRow('mine', 'extension', 'kalaka-aid');
        $this->seedFieldRow('older', 'extension', null);
        $this->seedFieldRow('retired_form', 'form');
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'kalaka_participant_fields',
            ['retired_at' => '2026-11-14 08:00:00.000000'],
            ['event_id' => self::EVENT, 'field_key' => 'retired_form']
        );

        foreach (['free', 'form_key', 'mine', 'older', 'retired_form'] as $key) {
            $fields->checkDeclarable(self::EVENT, $key, self::TEXT, 'kalaka-aid');
        }
    }

    public function testAnyOtherHolderRefusesTheCheckAndTheDeclarationAlike(): void
    {
        $this->seedFieldRow('another_declarer', 'extension', 'kalaka-aid');
        $this->seedFieldRow('staff_key', 'staff');
        $this->seedFieldRow('system_key', 'system');
        $this->seedFieldRow('form_key', 'form');
        $this->seedFieldRow('extension_key', 'extension', null);
        $cases = [
            ['another_declarer', self::TEXT],
            ['staff_key', self::TEXT],
            ['system_key', self::TEXT],
            ['form_key', 'kalaka/number-field'],
            ['extension_key', 'kalaka/number-field'],
        ];

        foreach ($cases as [$key, $type]) {
            try {
                (new ParticipantFields())->checkDeclarable(self::EVENT, $key, $type, 'kalaka-extras');
                self::fail('The check accepted ' . $key);
            } catch (FieldKeyHeld $held) {
                self::assertSame('kalaka_field_key_held', $held->errorCode());
                self::assertTrue(true);
            }
            try {
                $this->declareAs($key, 'kalaka-extras', $type);
                self::fail('The declaration accepted ' . $key);
            } catch (FieldKeyHeld $held) {
                self::assertSame('kalaka_field_key_held', $held->errorCode());
                self::assertTrue(true);
            }
        }
    }

    public function testADeclarationStoresItsDeclarerAndAdoptingOrTakingAnOlderFieldRecordsIt(): void
    {
        $this->declareAs('first', 'kalaka-extras');
        self::assertSame('kalaka-extras', $this->declarerOf('first'));

        $this->seedFieldRow('older', 'extension', null);
        $this->declareAs('older', 'kalaka-support');
        self::assertSame('kalaka-support', $this->declarerOf('older'));

        $this->seedFieldRow('form_key', 'form');
        $this->declareAs('form_key', 'kalaka-aid');
        self::assertSame('kalaka-aid', $this->declarerOf('form_key'));
        self::assertSame(ParticipantField::SOURCE_EXTENSION, $this->storedField('form_key')->source);
    }

    public function testAFormSaveLeavesAnExtensionFieldsFlagAndDeclarerAlone(): void
    {
        $this->declareAs('joined', 'kalaka-extras', self::TEXT, true);
        $this->insertChecked(
            $this->block(self::TEXT, ['key' => 'joined', 'label' => 'Csatlakozó']),
            ['kalaka_event_id' => self::EVENT, 'kalaka_placement' => 'additional']
        );

        $field = $this->storedField('joined');
        self::assertSame(ParticipantField::SOURCE_EXTENSION, $field->source);
        self::assertTrue($field->moneyRelevant);
        self::assertSame('kalaka-extras', $this->declarerOf('joined'));
    }

    public function testMoneyRelevanceIsTheFlagTheDeclarationSetsAndIsTheLockRead(): void
    {
        $fields = new ParticipantFields();
        $this->declareAs('locked', 'kalaka-extras', self::TEXT, true);
        $this->declareAs('open', 'kalaka-extras');
        $this->seedFieldRow('plain_form', 'form');

        self::assertTrue($this->storedField('locked')->moneyRelevant);
        self::assertTrue($fields->lockedAfterFirstWrite($this->storedField('locked')));
        self::assertFalse($fields->lockedAfterFirstWrite($this->storedField('open')));
        self::assertFalse($fields->lockedAfterFirstWrite($this->storedField('plain_form')));

        $this->declareAs('locked', 'kalaka-extras');
        self::assertTrue(
            $fields->lockedAfterFirstWrite($this->storedField('locked')),
            'A redeclaration never lowers the claim.'
        );
    }
}
