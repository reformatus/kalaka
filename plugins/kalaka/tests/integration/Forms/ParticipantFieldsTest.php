<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Forms;

use Kalaka\Forms\Api\ParticipantField;
use Kalaka\Forms\BrokenForms;
use Kalaka\Forms\DefinitionCheck;
use Kalaka\Forms\Module;
use Kalaka\Forms\ParticipantField\DeclareParticipantFields;
use Kalaka\Foundation\Api\Error\SystemFailure;

/**
 * The event's participant fields: keys derived across the event and never reused, shared keys of one type,
 * options as a union, the event fixed once a form has fields, and the editor's read.
 */
final class ParticipantFieldsTest extends FormsTestCase
{
    private const EVENT = 7;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        // The harness unregisters all meta keys after each test, so register them again the way `init` does.
        (new Module())->registerMeta();
        wp_set_current_user($this->fullAccessUser());
    }

    /** A participant field another source declared, written as its use case would. */
    private function declareField(string $key, string $source, bool $formWritable = false): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'kalaka_participant_fields', [
            'event_id' => self::EVENT,
            'field_key' => $key,
            'version' => 1,
            'type' => 'kalaka/text-field',
            'title' => 'Támogatás',
            'source' => $source,
            'created_at' => '2026-11-13 08:00:00.000000',
            'updated_at' => '2026-11-13 08:00:00.000000',
            'position' => 100,
            'form_writable' => $formWritable ? 1 : 0,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function unkeyed(string $type, string $label, array $attributes = []): string
    {
        return $this->block('kalaka/' . $type . '-field', $attributes + ['label' => $label]);
    }

    /** @param list<array{value: string, label: string}> $options */
    private function choice(string $key, array $options): string
    {
        return $this->field('choice', $key, ['options' => $options]);
    }

    /** An additional form of the event, saved through every check. */
    private function additional(string $content, int $event = self::EVENT): int
    {
        return $this->insertChecked($content, ['kalaka_event_id' => $event, 'kalaka_placement' => 'additional']);
    }

    private function saveContent(int $id, string $content): void
    {
        $result = wp_update_post(['ID' => $id, 'post_content' => wp_slash($content)], true);
        self::assertSame($id, $result);
    }

    /** @param array<string, mixed> $params */
    private function rest(string $method, string $route, array $params = []): \WP_REST_Response
    {
        $request = new \WP_REST_Request($method, $route);
        if ($method === 'GET') {
            $request->set_query_params($params);
        } else {
            $request->set_body_params($params);
        }
        $response = rest_do_request($request);
        self::assertInstanceOf(\WP_REST_Response::class, $response);

        return $response;
    }

    /** @return array<string, mixed> the error a REST response carries */
    private function error(\WP_REST_Response $response): array
    {
        $data = $response->get_data();
        self::assertIsArray($data);

        return $data;
    }

    /** @return array<string, ParticipantField> by key */
    private function participantFields(int $event = self::EVENT, bool $includeRetired = true): array
    {
        $fields = [];
        foreach ($this->forms->participantFields($event, $includeRetired) as $field) {
            $fields[$field->key] = $field;
        }

        return $fields;
    }

    /** @return list<string> the keys of the stored content's field blocks */
    private function storedKeys(int $id): array
    {
        $keys = [];
        $walk = static function (array $blocks) use (&$walk, &$keys): void {
            foreach ($blocks as $block) {
                if (isset($block['attrs']['key'])) {
                    $keys[] = $block['attrs']['key'];
                }
                $walk($block['innerBlocks']);
            }
        };
        $walk(parse_blocks((string) get_post_field('post_content', $id)));

        return $keys;
    }

    private function notice(): mixed
    {
        return get_transient(DefinitionCheck::NOTICE . get_current_user_id());
    }

    public function testADerivedKeyIsUniqueAcrossTheFormsOfTheEvent(): void
    {
        $first = $this->additional($this->unkeyed('text', 'Megjegyzés'));
        $second = $this->additional($this->unkeyed('text', 'Megjegyzés'));
        $otherEvent = $this->additional($this->unkeyed('text', 'Megjegyzés'), 8);

        self::assertSame(['megjegyzes'], $this->storedKeys($first));
        self::assertSame(['megjegyzes_1'], $this->storedKeys($second));
        self::assertSame(['megjegyzes'], $this->storedKeys($otherEvent));
        self::assertSame(['megjegyzes', 'megjegyzes_1'], array_keys($this->participantFields()));
        self::assertSame('form', $this->participantFields()['megjegyzes']->source);
        self::assertSame('kalaka/text-field', $this->participantFields()['megjegyzes']->type);
    }

    public function testARetiredKeyIsNeverDerivedAgain(): void
    {
        $id = $this->additional($this->unkeyed('text', 'Megjegyzés'));
        $this->saveContent($id, $this->unkeyed('text', 'Kérdés'));

        self::assertTrue($this->participantFields()['megjegyzes']->retired);
        self::assertSame(['kerdes'], array_keys($this->participantFields(self::EVENT, false)));

        $this->saveContent($id, $this->field('text', 'kerdes') . $this->unkeyed('text', 'Megjegyzés'));

        self::assertSame(['kerdes', 'megjegyzes_1'], $this->storedKeys($id));
        self::assertTrue($this->participantFields()['megjegyzes']->retired);
        self::assertFalse($this->participantFields()['megjegyzes_1']->retired);
    }

    public function testTrashingAFormRetiresItsFieldsAndRestoringItBringsThemBack(): void
    {
        $id = $this->additional($this->field('text', 'note'));

        wp_trash_post($id);
        self::assertTrue($this->participantFields()['note']->retired);

        wp_untrash_post($id);
        wp_publish_post($id);
        self::assertFalse($this->participantFields()['note']->retired);
    }

    public function testATypedKeyJoinsTheSameParticipantField(): void
    {
        $this->additional($this->field('text', 'note', ['label' => 'Megjegyzés']));
        $this->additional($this->field('text', 'note', ['label' => 'Egyéb megjegyzés']));

        $fields = $this->participantFields();
        self::assertSame(['note'], array_keys($fields));
        self::assertSame('Egyéb megjegyzés', $fields['note']->title);
        self::assertFalse($fields['note']->retired);
    }

    public function testATypeClashIsRefusedOverRest(): void
    {
        $this->additional($this->field('text', 'note'));

        $response = $this->rest('POST', '/wp/v2/kalaka_form', [
            'title' => 'Pótlap',
            'status' => 'draft',
            'content' => $this->field('date', 'note'),
            'meta' => ['kalaka_event_id' => self::EVENT, 'kalaka_placement' => 'additional'],
        ]);

        self::assertSame(400, $response->get_status());
        self::assertSame(
            'Az űrlapot így nem lehet menteni: type_clash („note”).',
            $this->error($response)['message']
        );
        self::assertSame('kalaka/text-field', $this->participantFields()['note']->type);
    }

    public function testATypeClashIsVetoedOnACodeSave(): void
    {
        $this->additional($this->field('text', 'note'));
        $other = $this->additional($this->field('text', 'other'));

        $this->saveContent($other, $this->field('text', 'other') . $this->field('date', 'note'));

        self::assertSame(['other'], $this->storedKeys($other));
        self::assertSame('Az űrlapot így nem lehet menteni: type_clash („note”).', $this->notice());
        self::assertSame('kalaka/text-field', $this->participantFields()['note']->type);

        $new = $this->additional($this->field('date', 'note'));
        self::assertSame('draft', get_post_status($new));
        self::assertSame([], $this->storedKeys($new));
    }

    public function testASystemFieldIsReadOnly(): void
    {
        $this->declareField('aid_award', 'system');

        $response = $this->rest('POST', '/wp/v2/kalaka_form', [
            'title' => 'Pótlap',
            'status' => 'draft',
            'content' => $this->field('text', 'aid_award'),
            'meta' => ['kalaka_event_id' => self::EVENT, 'kalaka_placement' => 'additional'],
        ]);

        self::assertSame(400, $response->get_status());
        self::assertStringContainsString('system_field („aid_award”)', (string) $this->error($response)['message']);
        self::assertSame(['aid_award_1'], $this->storedKeys($this->additional($this->unkeyed('text', 'Aid award'))));
    }

    public function testAnExtensionFieldIsReadOnlyUnlessDeclaredFormWritableAndAStaffFieldIsWritable(): void
    {
        $this->declareField('aid_award', 'extension');
        $this->declareField('ranking', 'extension', true);
        $this->declareField('staff_note', 'staff');

        $refused = $this->rest('POST', '/wp/v2/kalaka_form', [
            'title' => 'Pótlap',
            'status' => 'draft',
            'content' => $this->field('text', 'aid_award'),
            'meta' => ['kalaka_event_id' => self::EVENT, 'kalaka_placement' => 'additional'],
        ]);
        self::assertSame(400, $refused->get_status());
        self::assertSame(
            'Az űrlapot így nem lehet menteni: extension_field („aid_award”).',
            $this->error($refused)['message']
        );

        $id = $this->additional($this->field('text', 'ranking') . $this->field('text', 'staff_note'));
        self::assertSame(['ranking', 'staff_note'], $this->storedKeys($id));
        $fields = $this->participantFields();
        self::assertFalse($fields['aid_award']->formWritable);
        self::assertTrue($fields['ranking']->formWritable);
        self::assertSame('extension', $fields['ranking']->source);
        self::assertSame('staff', $fields['staff_note']->source);
        self::assertSame([['form_id' => $id, 'title' => 'Regisztráció']], $fields['staff_note']->forms);
    }

    public function testAKeylessBlockKeepsItsKeyAcrossSaves(): void
    {
        $id = $this->additional($this->unkeyed('text', 'Megjegyzés'));
        self::assertSame(['megjegyzes'], $this->storedKeys($id));

        // The block editor sends its own block state again, still without the key the server set.
        $this->saveContent($id, $this->unkeyed('text', 'Megjegyzés'));
        self::assertSame(['megjegyzes'], $this->storedKeys($id));
        $response = $this->rest(
            'POST',
            '/wp/v2/kalaka_form/' . $id,
            ['content' => $this->unkeyed('text', 'Megjegyzés')]
        );
        self::assertSame(200, $response->get_status());
        self::assertSame(['megjegyzes'], $this->storedKeys($id));

        // A keyless block inserted before it: the original keeps its key.
        $this->saveContent($id, $this->unkeyed('text', 'Kérdés') . $this->unkeyed('text', 'Megjegyzés'));
        self::assertSame(['kerdes', 'megjegyzes'], $this->storedKeys($id));
        self::assertSame(['megjegyzes', 'kerdes'], array_keys($this->participantFields(self::EVENT, false)));
    }

    public function testFieldsComeBackInTheOrderTheyWereDeclared(): void
    {
        $this->additional($this->unkeyed('text', 'Zebra') . $this->unkeyed('date', 'Alma'));
        $this->additional($this->unkeyed('text', 'Barack'));

        self::assertSame(['zebra', 'alma', 'barack'], array_keys($this->participantFields()));
        self::assertSame('Dátum', $this->participantFields()['alma']->typeLabel);
    }

    public function testChoiceOptionsAreAUnionAndARemovedOneIsRetiredWithItsLabel(): void
    {
        $first = $this->additional(
            $this->choice('size', [['value' => 's', 'label' => 'Kicsi'], ['value' => 'm', 'label' => 'Közepes']])
        );
        $this->additional(
            $this->choice('size', [['value' => 'm', 'label' => 'Közép'], ['value' => 'l', 'label' => 'Nagy']])
        );

        self::assertSame(
            [
                ['value' => 's', 'label' => 'Kicsi', 'retired' => false],
                ['value' => 'm', 'label' => 'Közép', 'retired' => false],
                ['value' => 'l', 'label' => 'Nagy', 'retired' => false],
            ],
            $this->participantFields()['size']->options
        );

        $this->saveContent($first, $this->choice('size', [['value' => 'm', 'label' => 'Közepes']]));

        self::assertSame(
            [
                ['value' => 's', 'label' => 'Kicsi', 'retired' => true],
                ['value' => 'm', 'label' => 'Közepes', 'retired' => false],
                ['value' => 'l', 'label' => 'Nagy', 'retired' => false],
            ],
            $this->participantFields()['size']->options
        );
    }

    public function testTheEventIsKnownOnAFormsFirstSave(): void
    {
        $this->additional($this->unkeyed('text', 'Megjegyzés'));

        // Code: the event only in `meta_input`.
        $code = $this->additional($this->unkeyed('text', 'Megjegyzés'));
        self::assertSame(['megjegyzes_1'], $this->storedKeys($code));

        // REST: the event only in the request's meta, stored after the post.
        $response = $this->rest('POST', '/wp/v2/kalaka_form', [
            'title' => 'Pótlap',
            'status' => 'draft',
            'content' => $this->unkeyed('text', 'Megjegyzés'),
            'meta' => ['kalaka_event_id' => self::EVENT, 'kalaka_placement' => 'additional'],
        ]);
        self::assertSame(201, $response->get_status());
        $data = $response->get_data();
        self::assertIsArray($data);
        self::assertSame(['megjegyzes_2'], $this->storedKeys((int) $data['id']));
        self::assertSame(['megjegyzes', 'megjegyzes_1', 'megjegyzes_2'], array_keys($this->participantFields()));

        // The block editor: an auto-draft first, then its first save sets the event.
        $draft = wp_insert_post(['post_type' => 'kalaka_form', 'post_status' => 'auto-draft', 'post_title' => '']);
        self::assertIsInt($draft);
        $response = $this->rest('POST', '/wp/v2/kalaka_form/' . $draft, [
            'status' => 'draft',
            'content' => $this->unkeyed('text', 'Megjegyzés'),
            'meta' => ['kalaka_event_id' => self::EVENT, 'kalaka_placement' => 'additional'],
        ]);
        self::assertSame(200, $response->get_status());
        self::assertSame(['megjegyzes_3'], $this->storedKeys($draft));
    }

    public function testTheEventOfAFormWithFieldsCannotChange(): void
    {
        $id = $this->additional($this->field('text', 'note'));

        $response = $this->rest('POST', '/wp/v2/kalaka_form/' . $id, ['meta' => ['kalaka_event_id' => 8]]);
        self::assertSame(400, $response->get_status());
        self::assertSame(
            'Az űrlapot így nem lehet menteni: event_fixed („kalaka_event_id”).',
            $this->error($response)['message']
        );
        self::assertSame('7', get_post_meta($id, 'kalaka_event_id', true));

        self::assertFalse(update_post_meta($id, 'kalaka_event_id', 8));
        self::assertFalse(delete_post_meta($id, 'kalaka_event_id'));
        wp_update_post(['ID' => $id, 'meta_input' => ['kalaka_event_id' => 8]]);
        self::assertSame('7', get_post_meta($id, 'kalaka_event_id', true));
        self::assertSame(
            'Az űrlapot így nem lehet menteni: event_fixed („kalaka_event_id”).',
            $this->notice()
        );
        self::assertSame([], $this->participantFields(8));
    }

    public function testASaveThatAddsFieldsAndChangesTheEventIsRefusedBeforeAnythingIsStored(): void
    {
        $intro = '<!-- wp:paragraph --><p>Bevezető</p><!-- /wp:paragraph -->';
        $id = $this->additional($intro);

        $response = $this->rest('POST', '/wp/v2/kalaka_form/' . $id, [
            'content' => $intro . $this->unkeyed('text', 'Megjegyzés'),
            'meta' => ['kalaka_event_id' => 8],
        ]);
        self::assertSame(400, $response->get_status());
        self::assertSame(
            'Az űrlapot így nem lehet menteni: event_fixed („kalaka_event_id”).',
            $this->error($response)['message']
        );
        self::assertSame($intro, get_post_field('post_content', $id));
        self::assertSame('7', get_post_meta($id, 'kalaka_event_id', true));

        wp_update_post([
            'ID' => $id,
            'post_content' => wp_slash($intro . $this->unkeyed('text', 'Megjegyzés')),
            'meta_input' => ['kalaka_event_id' => 8],
        ]);
        self::assertSame($intro, get_post_field('post_content', $id));
        self::assertSame('7', get_post_meta($id, 'kalaka_event_id', true));
        self::assertSame(
            'Az űrlapot így nem lehet menteni: event_fixed („kalaka_event_id”).',
            $this->notice()
        );
        self::assertSame([], $this->participantFields(7));
        self::assertSame([], $this->participantFields(8));
    }

    public function testTheEventChangesFirstAndTheFieldsComeInALaterSave(): void
    {
        $this->additional($this->unkeyed('text', 'Megjegyzés'), 8);
        $intro = '<!-- wp:paragraph --><p>Bevezető</p><!-- /wp:paragraph -->';
        $id = $this->additional($intro);

        $moved = $this->rest('POST', '/wp/v2/kalaka_form/' . $id, ['meta' => ['kalaka_event_id' => 8]]);
        self::assertSame(200, $moved->get_status());
        $added = $this->rest('POST', '/wp/v2/kalaka_form/' . $id, [
            'content' => $intro . $this->unkeyed('text', 'Megjegyzés'),
        ]);
        self::assertSame(200, $added->get_status());

        self::assertSame('8', get_post_meta($id, 'kalaka_event_id', true));
        self::assertSame(['megjegyzes_1'], $this->storedKeys($id));
        self::assertSame(['megjegyzes', 'megjegyzes_1'], array_keys($this->participantFields(8)));
    }

    public function testAFormWrittenAroundTheCheckNeverStopsTheRestOfTheList(): void
    {
        $log = (string) tempnam(sys_get_temp_dir(), 'kalaka-log');
        $previous = ini_set('error_log', $log);
        try {
            $this->additional($this->field('text', 'note'));
            // Written around the save check: `note` as a date.
            $stale = $this->insertForm(
                $this->field('date', 'note') . $this->field('text', 'extra'),
                ['kalaka_placement' => 'additional']
            );
            $fields = $this->participantFields();
            self::assertSame('kalaka/text-field', $fields['note']->type);
            self::assertArrayHasKey('extra', $fields);

            // A later save of another form still declares its fields.
            $other = $this->additional($this->unkeyed('text', 'Új kérdés'));
            self::assertArrayHasKey('uj_kerdes', $this->participantFields());
            self::assertSame(['uj_kerdes'], $this->storedKeys($other));
        } finally {
            ini_set('error_log', (string) $previous);
        }

        self::assertStringContainsString(
            'key note of form ' . $stale . ' (kalaka/date-field, not kalaka/text-field)',
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local test log
            (string) file_get_contents($log)
        );
        wp_delete_file($log);

        // The clashing form is closed and named in the broken-form warning.
        try {
            $this->forms->definition($this->readForm($stale));
            self::fail('A form whose key clashes with the event field is closed.');
        } catch (SystemFailure $e) {
            self::assertStringContainsString('type_clash note', $e->getMessage());
        }
        self::assertContains('#' . $stale . ' Regisztráció: type_clash note', BrokenForms::stored());
    }

    public function testDeletingAFormForGoodRetiresTheKeysOnlyItHeld(): void
    {
        $kept = $this->additional($this->field('text', 'shared') . $this->unkeyed('text', 'Megjegyzés'));
        $gone = $this->additional($this->field('text', 'shared') . $this->field('text', 'only_here'));

        wp_delete_post($gone, true);

        $fields = $this->participantFields();
        self::assertTrue($fields['only_here']->retired);
        self::assertFalse($fields['shared']->retired);
        self::assertFalse($fields['megjegyzes']->retired);

        $this->saveContent($kept, $this->field('text', 'shared') . $this->unkeyed('text', 'Only here'));
        self::assertSame(['shared', 'only_here_1'], $this->storedKeys($kept));
    }

    public function testAFormWithoutFieldsMayChangeItsEvent(): void
    {
        $id = $this->additional('<!-- wp:paragraph --><p>Bevezető</p><!-- /wp:paragraph -->');

        $response = $this->rest('POST', '/wp/v2/kalaka_form/' . $id, ['meta' => ['kalaka_event_id' => 8]]);

        self::assertSame(200, $response->get_status());
        self::assertSame('8', get_post_meta($id, 'kalaka_event_id', true));
    }

    public function testTwoBlocksWithOneKeyInAFormAreRefusedAndNothingChanges(): void
    {
        $id = $this->additional($this->field('text', 'note', ['label' => 'Megjegyzés']));
        $content = (string) get_post_field('post_content', $id);
        $fields = $this->participantFields();
        $copyAbove = $this->field('text', 'note', ['label' => 'Másolat']) . $content;

        $response = $this->rest('POST', '/wp/v2/kalaka_form/' . $id, ['content' => $copyAbove]);
        self::assertSame(400, $response->get_status());
        self::assertSame(
            'Az űrlapot így nem lehet menteni: duplicate_key („note”).',
            $this->error($response)['message']
        );

        $draft = $this->rest('POST', '/wp/v2/kalaka_form', [
            'title' => 'Pótlap',
            'status' => 'draft',
            'content' => $copyAbove,
            'meta' => ['kalaka_event_id' => self::EVENT, 'kalaka_placement' => 'additional'],
        ]);
        self::assertSame(400, $draft->get_status());

        $this->saveContent($id, $copyAbove);
        self::assertSame($content, get_post_field('post_content', $id));
        self::assertSame('Az űrlapot így nem lehet menteni: duplicate_key („note”).', $this->notice());
        self::assertEquals($fields, $this->participantFields());
    }

    public function testASaveDeclaresItsFieldsAsAnAuditedUseCase(): void
    {
        $this->additional($this->field('text', 'note'));

        $actions = array_column($this->entries(), 'action');
        self::assertContains(DeclareParticipantFields::name(), $actions);
    }

    public function testTheEditorReadsTheEventsFieldsWithFullAccessOnly(): void
    {
        $event = $this->create();
        $id = $this->additional(
            $this->field('text', 'note', ['label' => 'Megjegyzés']) . $this->field('text', 'gone'),
            $event
        );
        $this->saveContent($id, $this->field('text', 'note', ['label' => 'Megjegyzés']));

        $response = $this->rest('GET', '/kalaka-admin/v1/events/' . $event . '/participant-fields');
        self::assertSame(200, $response->get_status());
        self::assertSame(
            [[
                'key' => 'note',
                'type' => 'kalaka/text-field',
                'type_label' => 'Szöveg',
                'title' => 'Megjegyzés',
                'options' => [],
                'source' => 'form',
                'form_writable' => true,
                'retired' => false,
                'forms' => [['form_id' => $id, 'title' => 'Regisztráció']],
            ]],
            $response->get_data()
        );
        $route = '/kalaka-admin/v1/events/' . $event . '/participant-fields';
        $all = $this->rest('GET', $route, ['include_retired' => true]);
        self::assertSame(['note', 'gone'], array_column((array) $all->get_data(), 'key'));
        self::assertSame([], ((array) $all->get_data())[1]['forms']);

        self::assertSame(404, $this->rest('GET', '/kalaka-admin/v1/events/99999/participant-fields')->get_status());

        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $refused = $this->rest('GET', '/kalaka-admin/v1/events/' . $event . '/participant-fields');
        self::assertSame(403, $refused->get_status());
    }
}
