<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Mail;

use Kalaka\Admin\Api\AdminAccess;
use Kalaka\Mail\Body\BodyCheck;
use Kalaka\Mail\Module;

/** A mail body is checked when it is saved as published: its keys and conditions against the mail's keys. */
final class BodyCheckTest extends MailBodyTestCase
{
    private int $user = 0;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        // The harness unregisters all meta keys after each test, so register them again the way `init` does.
        (new Module())->registerMeta();
        $this->user = self::factory()->user->create();
        $user = get_userdata($this->user);
        self::assertInstanceOf(\WP_User::class, $user);
        $user->add_cap(AdminAccess::FULL_ACCESS);
        wp_set_current_user($this->user);
    }

    /** @return list<array{string, string}> the bodies the check refuses, each with its problem as the refusal names it */
    private function broken(): array
    {
        $binding = '<!-- wp:paragraph {"metadata":{"bindings":{"content":'
            . '{"source":"kalaka/value","args":{"key":"event.place"}}}}} -->' . "\n<p>x</p>\n<!-- /wp:paragraph -->";

        return [
            ['unknown_value_key („nincs”)', $this->paragraph('<span data-kalaka-value="nincs">x</span>')],
            ['unknown_value_key („event.place”)', $binding],
            // A key in a branch that would not hold.
            [
                'unknown_value_key („nincs”)',
                $this->branch('if', $this->paragraph('A'), $this->is('registration.code')) . "\n"
                    . $this->branch('else', $this->paragraph('<span data-kalaka-value="nincs">x</span>')),
            ],
            ['invalid_condition („”)', $this->branch('if', $this->paragraph('A'), ['match' => 'all'])],
            ['orphan_branch („kalaka/else”)', $this->branch('else', $this->paragraph('A'))],
            // Never a field block or a form-only block, not even inside a branch.
            [
                'field_in_document („kalaka/text-field”)',
                $this->block('kalaka/text-field', ['key' => 'x', 'label' => 'X']),
            ],
            [
                'field_in_document („kalaka/email-field”)',
                $this->branch('if', $this->block('kalaka/email-field', ['key' => 'y']), $this->is('registration.code')),
            ],
            ['form_block_in_document („kalaka/form-page”)', '<!-- wp:kalaka/form-page /-->'],
        ];
    }

    /** @return array<string, mixed> */
    private function is(string $key): array
    {
        return ['match' => 'all', 'items' => [['key' => $key, 'operator' => 'empty']]];
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

    public function testEveryKeyOfTheMailPasses(): void
    {
        $event = $this->create();
        $this->slotForm($event);
        $inner = $this->paragraph(
            '<span data-kalaka-value="recipient.name">n</span> <span data-kalaka-value="recipient.email">e</span>'
            . ' <span data-kalaka-value="registration.code">c</span>'
            . ' <span data-kalaka-value="registration.link">l</span>'
            . ' <span data-kalaka-value="event.name">e</span> <span data-kalaka-value="event.start">s</span>'
            . ' <span data-kalaka-value="camps">t</span>'
        ) . "\n" . $this->branch('if', $this->paragraph('A'), [
            'match' => 'all',
            'items' => [['key' => 'registration.status', 'operator' => 'equals', 'value' => 'waitlisted']],
        ]);
        $id = $this->document($event, $inner);

        self::assertSame('publish', get_post_status($id));
        self::assertSame($inner, get_post_field('post_content', $id));
        self::assertSame(200, $this->restSave($id, ['content' => $inner])->get_status());
    }

    public function testASpanInsideAValueSpanIsNotCheckedAsTheRendererNeverReadsIt(): void
    {
        $event = $this->create();
        $this->slotForm($event);
        $inner = $this->paragraph(
            '<span data-kalaka-value="recipient.name"><span data-kalaka-value="nincs_ilyen">n</span></span>'
        );
        $id = $this->document($event, $inner);

        self::assertSame('publish', get_post_status($id));
        self::assertSame(200, $this->restSave($id, ['content' => $inner])->get_status());
    }

    public function testRestRefusesPublishingABodyWithAProblem(): void
    {
        $event = $this->create();
        $id = $this->document($event, $this->paragraph('Szia'));

        foreach ($this->broken() as [$why, $inner]) {
            $response = $this->restSave($id, ['content' => $inner]);
            self::assertSame(400, $response->get_status(), $why);
            $error = $response->as_error();
            self::assertInstanceOf(\WP_Error::class, $error);
            self::assertSame(BodyCheck::CODE, $error->get_error_code());
            self::assertSame(
                'A sablon szövegét így nem lehet menteni: ' . $why . '.',
                $error->get_error_message(),
                $why
            );
        }
        self::assertSame($this->paragraph('Szia'), get_post_field('post_content', $id));

        // A draft is not checked: it is not used.
        $bad = $this->paragraph('<span data-kalaka-value="nincs">x</span>');
        $draft = $this->restSave($id, ['content' => $bad, 'status' => 'draft']);
        self::assertSame(200, $draft->get_status());
    }

    public function testARestSaveIsCheckedAgainstTheEventItSetsOnBothChecks(): void
    {
        $event = $this->create();
        $other = $this->create('Másik');
        $this->slotForm($other);
        $id = $this->document($event, $this->paragraph('Szia'));
        // `camps` is a participant field of the other event only.
        $inner = $this->paragraph('<span data-kalaka-value="camps">t</span>');

        $response = $this->restSave($id, ['content' => $inner, 'meta' => ['kalaka_event_id' => $other]]);

        self::assertSame(200, $response->get_status());
        self::assertSame($inner, get_post_field('post_content', $id));
        self::assertSame((string) $other, get_post_meta($id, 'kalaka_event_id', true));
        self::assertFalse(get_transient(BodyCheck::NOTICE . $this->user));
    }

    public function testAnyOtherPathVetoesGracefullyAndTellsTheSavingUser(): void
    {
        $event = $this->create();
        $id = $this->document($event, $this->paragraph('Szia'));
        $bad = $this->paragraph('<span data-kalaka-value="nincs">x</span>');

        wp_update_post(['ID' => $id, 'post_content' => $bad]);

        self::assertSame($this->paragraph('Szia'), get_post_field('post_content', $id));
        self::assertSame('publish', get_post_status($id));
        self::assertSame(
            'A sablon szövegét így nem lehet menteni: unknown_value_key („nincs”).',
            get_transient(BodyCheck::NOTICE . $this->user)
        );

        // A new body with a problem is kept, unpublished.
        $new = wp_insert_post([
            'post_type' => 'kalaka_mail',
            'post_status' => 'publish',
            'post_title' => 'Teszt',
            'post_content' => $bad,
            'meta_input' => ['kalaka_event_id' => $event],
        ], true);
        self::assertIsInt($new);
        self::assertSame('draft', get_post_status($new));

        ob_start();
        BodyCheck::notice();
        self::assertStringContainsString('unknown_value_key („nincs”)', (string) ob_get_clean());
        self::assertFalse(get_transient(BodyCheck::NOTICE . $this->user));
    }

    public function testACodeSaveWithNoUserWritesALogLineAndAWarning(): void
    {
        $event = $this->create();
        $id = $this->document($event, $this->paragraph('Szia'));
        $user = get_current_user_id();
        wp_set_current_user(0);
        $warnings = [];
        $listener = static function (string $function, string $message, int $level) use (&$warnings): void {
            $warnings[] = [$function, $message, $level];
        };
        add_action('wp_trigger_error_always_run', $listener, 10, 3);
        try {
            [$log] = $this->captureLog(fn (): int => (int) wp_update_post([
                'ID' => $id,
                'post_content' => $this->paragraph('<span data-kalaka-value="nincs">x</span>'),
            ]));
        } finally {
            remove_action('wp_trigger_error_always_run', $listener, 10);
            wp_set_current_user($user);
        }

        self::assertSame($this->paragraph('Szia'), get_post_field('post_content', $id));
        self::assertStringContainsString('saving mail body ' . $id, $log);
        self::assertStringContainsString('unknown_value_key nincs', $log);
        self::assertCount(1, $warnings);
        self::assertSame(E_USER_WARNING, $warnings[0][2]);
        self::assertStringContainsString('unknown_value_key nincs', $warnings[0][1]);
    }

    public function testARestRefusalWritesNoLogLine(): void
    {
        $event = $this->create();
        $id = $this->document($event, $this->paragraph('Szia'));

        [$log, $response] = $this->captureLog(fn (): \WP_REST_Response => $this->restSave(
            $id,
            ['content' => $this->paragraph('<span data-kalaka-value="nincs">x</span>')]
        ));

        self::assertSame(400, $response->get_status());
        self::assertStringNotContainsString('saving mail body', $log);
    }

    /**
     * The log lines written while the body runs.
     *
     * @param \Closure(): mixed $body
     * @return array{string, mixed}
     */
    private function captureLog(\Closure $body): array
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'kalaka-log-');
        $previous = ini_set('error_log', $file);
        try {
            $result = $body();
        } finally {
            ini_set('error_log', (string) $previous);
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the captured error log, a temporary file
        $log = (string) file_get_contents($file);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removes the captured error log, a temporary file
        unlink($file);

        return [$log, $result];
    }
}
