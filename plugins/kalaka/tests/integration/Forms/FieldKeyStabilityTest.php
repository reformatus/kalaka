<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Forms;

use Kalaka\Admin\Api\AdminAccess;
use Kalaka\Events\PublishEvent;
use Kalaka\Forms\Api\FormField;
use Kalaka\Forms\Module;
use Kalaka\Registrations\Api\RegistrationSubmit;
use Kalaka\Registrations\Api\SubmitRequest;
use Kalaka\Registrations\Api\SubmitToken;
use Kalaka\Foundation\Api\Actor\GuestActor;

/**
 * The owner's key-stability cases through the real save path (events-and-forms.md, "Fields"): the block
 * editor's REST save of the content as the editor sends it, through `FieldKeys` and the save check. The
 * editor's side of the same cases is in tests/js/keys.test.js ("key stability").
 *
 * In (b) the editor has already given the copy its own key when it was duplicated (`keys.js`): the server
 * cannot tell a copy from its original, and `FieldKeys` keeps the first of two equal keys in document
 * order, so the original keeps its key only because the editor sends the copy with a new one.
 */
final class FieldKeyStabilityTest extends FormsTestCase
{
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        // The harness unregisters all meta keys after each test, so register them again the way `init` does.
        (new Module())->registerMeta();
        $user = self::factory()->user->create();
        $wpUser = get_userdata($user);
        self::assertInstanceOf(\WP_User::class, $wpUser);
        $wpUser->add_cap(AdminAccess::FULL_ACCESS);
        wp_set_current_user($user);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed> the response's data
     */
    private function save(string $route, array $params, int $status): array
    {
        $request = new \WP_REST_Request('POST', $route);
        $request->set_body_params($params);
        $response = rest_do_request($request);
        self::assertSame($status, $response->get_status(), (string) wp_json_encode($response->get_data()));
        $data = $response->get_data();
        self::assertIsArray($data);

        return $data;
    }

    /** Publishes a registration-slot form of a published event through REST, as the editor does. */
    private function publish(string $content): int
    {
        $event = $this->create();
        $this->update($event, ['capacity' => 41]);
        $this->transition(new PublishEvent(), $event);
        $data = $this->save('/wp/v2/kalaka_form', [
            'title' => 'Regisztráció',
            'status' => 'publish',
            'content' => $content,
            'meta' => ['kalaka_event_id' => $event, 'kalaka_placement' => 'registration'],
        ], 201);
        self::assertIsInt($data['id']);

        return $data['id'];
    }

    private function saveAgain(int $id, string $content): void
    {
        $this->save('/wp/v2/kalaka_form/' . $id, ['content' => $content], 200);
    }

    /** @return list<array{string, string}> each field's key and title in the saved content, in document order */
    private function savedFields(int $id): array
    {
        $post = get_post($id);
        self::assertInstanceOf(\WP_Post::class, $post);
        $fields = [];
        foreach (parse_blocks($post->post_content) as $block) {
            if (str_starts_with((string) $block['blockName'], 'kalaka/') && isset($block['attrs']['key'])) {
                $fields[] = [(string) $block['attrs']['key'], (string) ($block['attrs']['label'] ?? '')];
            }
        }

        return $fields;
    }

    private function text(string $label, ?string $key = null): string
    {
        return $this->block('kalaka/text-field', ['label' => $label] + ($key === null ? [] : ['key' => $key]));
    }

    /** @return array<string, string> */
    private function storedValues(int $event): array
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test inspection; table prefix only
            $wpdb->prepare(
                "SELECT field_key, value FROM {$wpdb->prefix}kalaka_field_values WHERE event_id = %d",
                $event
            ),
            ARRAY_A
        );
        self::assertIsArray($rows);
        $values = [];
        foreach ($rows as $row) {
            $values[(string) $row['field_key']] = (string) $row['value'];
        }

        return $values;
    }

    /** @return array<string, FormField> the latest definition's fields by key */
    private function latestFields(int $id): array
    {
        $fields = [];
        foreach ($this->forms->definition($this->readForm($id))->fields as $field) {
            $fields[$field->key] = $field;
        }

        return $fields;
    }

    /** @return iterable<string, array{?string}> the editor's key, or none (the server derives it on the first save) */
    public static function firstSaves(): iterable
    {
        yield 'key set by the editor' => ['megjegyzes'];
        yield 'key derived by the server' => [null];
    }

    /** @dataProvider firstSaves */
    public function testARenamedTitleKeepsItsKey(?string $editorKey): void
    {
        $id = $this->publish($this->slotFields() . $this->text('Megjegyzés', $editorKey));
        self::assertSame(['megjegyzes', 'Megjegyzés'], $this->savedFields($id)[2]);

        // The editor loads the saved content, the title is renamed, and the block goes back with its key.
        $this->saveAgain($id, $this->slotFields() . $this->text('Egyéb megjegyzés', 'megjegyzes'));

        self::assertSame(['megjegyzes', 'Egyéb megjegyzés'], $this->savedFields($id)[2]);
        self::assertSame('Egyéb megjegyzés', $this->latestFields($id)['megjegyzes']->label());
    }

    public function testADuplicateMovedAboveItsOriginalLeavesTheOriginalItsKeyAndItsData(): void
    {
        $id = $this->publish($this->slotFields() . $this->text('Megjegyzés', 'megjegyzes'));
        $event = $this->readForm($id)->eventId;
        (new RegistrationSubmit($this->runner))->submit(
            new SubmitRequest(
                $id,
                $this->readForm($id)->version,
                ['name' => 'Teszt Elek', 'email' => 'teszt@example.org', 'megjegyzes' => 'Vegetáriánus'],
                SubmitToken::generate(),
                [],
                false
            ),
            new GuestActor()
        );

        // Duplicated, moved above the original and renamed: the editor gave the copy its key when it was
        // duplicated, from the title it had then.
        $this->saveAgain(
            $id,
            $this->slotFields()
                . $this->text('Megjegyzés (másolat)', 'megjegyzes_1')
                . $this->text('Megjegyzés', 'megjegyzes')
        );

        self::assertSame(
            [['megjegyzes_1', 'Megjegyzés (másolat)'], ['megjegyzes', 'Megjegyzés']],
            array_slice($this->savedFields($id), 2)
        );
        $fields = $this->latestFields($id);
        self::assertSame('Megjegyzés', $fields['megjegyzes']->label());
        self::assertSame('Megjegyzés (másolat)', $fields['megjegyzes_1']->label());
        $values = $this->storedValues($event);
        self::assertSame('Vegetáriánus', $values['megjegyzes']);
        self::assertArrayNotHasKey('megjegyzes_1', $values);
    }
}
