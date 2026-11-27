<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Mail;

use Kalaka\Admin\Api\AdminAccess;
use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\Mail\Body\BodyCheck;
use Kalaka\Mail\Body\MailRenderer;
use Kalaka\Mail\Module;

/**
 * The mail wiring of the path rule (`MailValues::paths()`): a mail body's condition on a path field is
 * asked with `contains`, never `equals`, at save (`BodyCheck`) and before the claim (`MailRenderer`).
 * Foundation.md, "Facts, decisions and computed values"; money.md, "Sponsor and church support"; N11.
 */
final class MailPathValuesTest extends MailBodyTestCase
{
    private const KEY = 'gyulekezet';

    /** Root first, ending with the leaf: what the congregation field stores. */
    private const SNAPSHOT = '{"node":5,"path":[{"ext":"ROOT","type":"Felekezet"},'
        . '{"ext":"D1","type":"Egyházkerület"},{"ext":"D1-A-1","type":"Gyülekezet"}]}';

    private Contributions $before;
    private int $user = 0;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        $this->before = Contributions::current();
        $contributions = new Contributions();
        foreach ($this->before->fieldTypes() as $type) {
            $contributions->fieldType($type);
        }
        $contributions->fieldType(new TestPathFieldType());
        Contributions::record($contributions);

        // The harness unregisters all meta keys after each test, so register them again the way `init` does.
        (new Module())->registerMeta();
        $this->user = self::factory()->user->create();
        $user = get_userdata($this->user);
        self::assertInstanceOf(\WP_User::class, $user);
        $user->add_cap(AdminAccess::FULL_ACCESS);
        wp_set_current_user($this->user);
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function tear_down(): void
    {
        Contributions::record($this->before);

        parent::tear_down();
    }

    /** Declares the event's congregation field, held by no form: the save check reads the type alone. */
    private function declarePathField(int $event): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'kalaka_participant_fields', [
            'event_id' => $event,
            'field_key' => self::KEY,
            'version' => 1,
            'type' => TestPathFieldType::BLOCK,
            'title' => 'Gyülekezet',
            'source' => 'form',
            'created_at' => '2026-11-29 08:00:00.000000',
            'updated_at' => '2026-11-29 08:00:00.000000',
            'position' => 1,
            'form_writable' => 1,
        ]);
    }

    /** @param array<string, mixed> $params */
    private function restSave(int $id, array $params): \WP_REST_Response
    {
        $request = new \WP_REST_Request('POST', '/wp/v2/kalaka_mail/' . $id);
        $request->set_body_params($params);
        $response = rest_do_request($request);
        self::assertInstanceOf(\WP_REST_Response::class, $response);

        return $response;
    }

    /** @param array<string, mixed> $condition */
    private function contains(string $ext): array
    {
        return ['match' => 'all', 'items' => [['key' => self::KEY, 'operator' => 'contains', 'value' => $ext]]];
    }

    public function testABodyComparedWithEqualsOnACongregationFieldIsRefused(): void
    {
        $event = $this->create();
        $this->declarePathField($event);
        $id = $this->document($event, $this->paragraph('Szia'));
        $inner = $this->paragraph('A') . "\n" . $this->branch('if', $this->paragraph('B'), [
            'match' => 'all',
            'items' => [['key' => self::KEY, 'operator' => 'equals', 'value' => 'D1-A-1']],
        ]);

        $response = $this->restSave($id, ['content' => $inner]);

        self::assertSame(400, $response->get_status());
        $error = $response->as_error();
        self::assertInstanceOf(\WP_Error::class, $error);
        self::assertSame(BodyCheck::CODE, $error->get_error_code());
        self::assertSame(
            'A sablon szövegét így nem lehet menteni: invalid_condition („' . self::KEY . '”).',
            $error->get_error_message()
        );
        self::assertSame($this->paragraph('Szia'), get_post_field('post_content', $id));

        // The same body with `contains` is accepted: the rule is the operator, not the key.
        $response = $this->restSave($id, [
            'content' => $this->paragraph('A') . "\n"
                . $this->branch('if', $this->paragraph('B'), $this->contains('D1-A-1')),
        ]);
        self::assertSame(200, $response->get_status());
    }

    public function testContainsOnTheStoredSnapshotRendersForAMatchingAnswerOnly(): void
    {
        $event = $this->create();
        $this->declarePathField($event);
        $key = $this->registration($event, values: [
            'name' => 'Teszt Elek',
            'email' => 'teszt@example.test',
            'camps' => '["ff"]',
            self::KEY => self::SNAPSHOT,
        ]);
        $body = $this->document(
            $event,
            $this->branch('if', $this->paragraph('Budai gyülekezet.'), $this->contains('D1-A-1'))
            . "\n" . $this->branch('else', $this->paragraph('Másutt.'))
        );
        $this->switchOn($event, 'registered', $body);

        $html = (new MailRenderer())->render($event, 'registered', $key, self::now())->html;

        self::assertStringContainsString('Budai gyülekezet.', $html);
        self::assertStringNotContainsString('Másutt.', $html);

        // A level above the leaf is in the snapshot too: the path is compared, not the picked node.
        self::assertStringContainsString('Budai gyülekezet.', $html);
        $above = $this->document(
            $event,
            $this->branch('if', $this->paragraph('Északi kerület.'), $this->contains('D1'))
            . "\n" . $this->branch('else', $this->paragraph('Nem ott.'))
        );
        $this->switchOn($event, 'waitlisted', $above);

        self::assertStringContainsString(
            'Északi kerület.',
            (new MailRenderer())->render($event, 'waitlisted', $key, self::now())->html
        );
    }

    public function testContainsOnANodeTheSnapshotDoesNotHoldDoesNotRender(): void
    {
        $event = $this->create();
        $this->declarePathField($event);
        $key = $this->registration($event, values: [
            'name' => 'Teszt Elek',
            'email' => 'teszt@example.test',
            self::KEY => self::SNAPSHOT,
        ]);
        $body = $this->document(
            $event,
            $this->branch('if', $this->paragraph('Déli gyülekezet.'), $this->contains('D2-B-1'))
            . "\n" . $this->branch('else', $this->paragraph('Másutt.'))
        );
        $this->switchOn($event, 'registered', $body);

        $html = (new MailRenderer())->render($event, 'registered', $key, self::now())->html;

        self::assertStringContainsString('Másutt.', $html);
        self::assertStringNotContainsString('Déli gyülekezet.', $html);
    }
}
