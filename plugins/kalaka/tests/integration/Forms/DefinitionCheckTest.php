<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Forms;

use Kalaka\Admin\Api\AdminAccess;
use Kalaka\Forms\BrokenForms;
use Kalaka\Forms\BrokenFormsWarning;
use Kalaka\Forms\DefinitionCheck;
use Kalaka\Forms\Module;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Tests\Integration\LogCapture;

/** A published form never goes from working to broken through an edit; one that is broken stays closed. */
final class DefinitionCheckTest extends FormsTestCase
{
    use LogCapture;

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

    /** @param array<string, mixed> $params */
    private function rest(string $route, array $params): \WP_REST_Response
    {
        $request = new \WP_REST_Request('POST', $route);
        $request->set_body_params($params);
        $response = rest_do_request($request);
        self::assertInstanceOf(\WP_REST_Response::class, $response);

        return $response;
    }

    private function unknownBlock(): string
    {
        return $this->block('kalaka/nope-field', ['key' => 'nope']);
    }

    private function paragraph(string $text): string
    {
        return '<!-- wp:paragraph --><p>' . $text . '</p><!-- /wp:paragraph -->';
    }

    public function testTheEditorCannotPublishABrokenFormAndIsToldWhy(): void
    {
        $response = $this->rest('/wp/v2/kalaka_form', [
            'title' => 'Regisztráció',
            'status' => 'publish',
            'content' => $this->slotFields() . $this->unknownBlock(),
            'meta' => ['kalaka_event_id' => 7, 'kalaka_placement' => 'registration'],
        ]);

        self::assertSame(400, $response->get_status());
        $data = $response->get_data();
        self::assertIsArray($data);
        self::assertSame('kalaka_form_definition', $data['code']);
        self::assertSame(
            'Az űrlapot így nem lehet menteni: unknown_field_block („kalaka/nope-field”).',
            $data['message']
        );
        self::assertSame([], get_posts(['post_type' => 'kalaka_form', 'post_status' => 'any']));
    }

    /**
     * A typed placeholder the form page's own context does not declare is refused at the form's save (owner
     * 2026-11-27, walkthrough 8.11 a and 8.13): the form page fills every block on load, so a form saved with
     * one would show every participant the system failure.
     */
    public function testAPlaceholderTheFormPageCannotFillIsRefusedAndAKnownOnePasses(): void
    {
        $help = $this->paragraph('Írd be: {{nincs_ilyen}}');
        $response = $this->rest('/wp/v2/kalaka_form', [
            'title' => 'Regisztráció',
            'status' => 'publish',
            'content' => $this->slotFields() . $help,
            'meta' => ['kalaka_event_id' => 7, 'kalaka_placement' => 'registration'],
        ]);

        self::assertSame(422, $response->get_status());
        $error = $response->as_error();
        self::assertInstanceOf(\WP_Error::class, $error);
        self::assertSame('kalaka_unknown_placeholder', $error->get_error_code());
        $data = $error->get_error_data();
        self::assertIsArray($data);
        self::assertSame(['{{nincs_ilyen}}'], $data['keys']);
        self::assertStringContainsString(
            'Ismeretlen jelölő: {{nincs_ilyen}}. A mentéshez javítani vagy törölni kell.',
            $error->get_error_message()
        );
        self::assertSame([], get_posts(['post_type' => 'kalaka_form', 'post_status' => 'any']));

        // The keys a form page fills pass: the event's values and the account fact.
        $known = $this->paragraph('{{event.name}} – {{account.logged_in}}');
        $made = $this->rest('/wp/v2/kalaka_form', [
            'title' => 'Regisztráció',
            'status' => 'publish',
            'content' => $this->slotFields() . $known,
            'meta' => ['kalaka_event_id' => 7, 'kalaka_placement' => 'registration'],
        ]);
        self::assertSame(201, $made->get_status());

        // Any other save path keeps the published form working and tells its editor why.
        $madeData = $made->get_data();
        self::assertIsArray($madeData);
        $id = (int) $madeData['id'];
        $stored = (string) get_post_field('post_content', $id);
        wp_update_post(['ID' => $id, 'post_content' => $this->slotFields() . $help]);
        self::assertSame($stored, (string) get_post_field('post_content', $id));
        self::assertSame(
            'A művelet nem hajtható végre. Ismeretlen jelölő: {{nincs_ilyen}}.'
            . ' A mentéshez javítani vagy törölni kell.',
            get_transient(DefinitionCheck::NOTICE . get_current_user_id())
        );
    }

    /**
     * The after-submit section has its own keys (`Api\AfterSubmit::TYPES`: the event's and the new registration's
     * code and status), so `{{registration.code}}` passes there; the same key in the form's other blocks is the
     * form page's, which does not declare it. [UNAPPROVED N1182]
     */
    public function testTheAfterSubmitSectionHasItsOwnPlaceholderKeys(): void
    {
        $after = '<!-- wp:kalaka/after-submit -->'
            . $this->paragraph('Köszönjük! Kódod: {{registration.code}} ({{registration.status}}, {{event.name}})')
            . '<!-- /wp:kalaka/after-submit -->';
        $made = $this->rest('/wp/v2/kalaka_form', [
            'title' => 'Regisztráció',
            'status' => 'publish',
            'content' => $this->slotFields() . $after,
            'meta' => ['kalaka_event_id' => 7, 'kalaka_placement' => 'registration'],
        ]);
        self::assertSame(201, $made->get_status());

        $response = $this->rest('/wp/v2/kalaka_form', [
            'title' => 'Regisztráció',
            'status' => 'publish',
            'content' => $this->slotFields() . $this->paragraph('Kódod: {{registration.code}}'),
            'meta' => ['kalaka_event_id' => 7, 'kalaka_placement' => 'registration'],
        ]);

        self::assertSame(422, $response->get_status());
        $error = $response->as_error();
        self::assertInstanceOf(\WP_Error::class, $error);
        self::assertSame('kalaka_unknown_placeholder', $error->get_error_code());
        $data = $error->get_error_data();
        self::assertIsArray($data);
        self::assertSame(['{{registration.code}}'], $data['keys']);
    }

    /**
     * The placeholder check is the definition check's own, so a stored form holding one is found by the
     * broken-form pass, closed and named, like any other definition problem. [UNAPPROVED N1182]
     */
    public function testAFormWithATypedPlaceholderItsPagesCannotFillIsNamedBrokenAndClosed(): void
    {
        $broken = $this->insertForm(
            $this->slotFields() . $this->paragraph('{{nincs_ilyen}}'),
            [],
            ['post_title' => 'Jelölős űrlap']
        );

        self::assertSame('publish', get_post_status($broken));
        self::assertSame(
            ['#' . $broken . ' Jelölős űrlap: unknown_placeholder {{nincs_ilyen}}'],
            BrokenForms::stored()
        );
        $shown = (new BrokenFormsWarning())->check();
        self::assertNotNull($shown);
        self::assertStringContainsString('unknown_placeholder {{nincs_ilyen}}', (string) $shown->details);
        $this->expectException(SystemFailure::class);
        $this->forms->definition($this->readForm($broken));
    }

    public function testASlotFormWithoutItsContactEmailCannotBePublished(): void
    {
        $response = $this->rest('/wp/v2/kalaka_form', [
            'title' => 'Regisztráció',
            'status' => 'publish',
            'content' => $this->field('name', 'name', ['displayName' => true]),
            'meta' => ['kalaka_event_id' => 7, 'kalaka_placement' => 'registration'],
        ]);

        self::assertSame(400, $response->get_status());
        $data = $response->get_data();
        self::assertIsArray($data);
        self::assertStringContainsString('contact_email_count', (string) $data['message']);
    }

    public function testABrokenDraftCanBeSaved(): void
    {
        $response = $this->rest('/wp/v2/kalaka_form', [
            'title' => 'Regisztráció',
            'status' => 'draft',
            'content' => $this->unknownBlock(),
            'meta' => ['kalaka_event_id' => 7, 'kalaka_placement' => 'registration'],
        ]);

        self::assertSame(201, $response->get_status());
    }

    public function testAnEditThatWouldBreakAPublishedFormIsRefusedAndTheFormKeepsWorking(): void
    {
        $id = $this->insertChecked($this->slotFields());
        $version = $this->readForm($id)->version;

        $response = $this->rest(
            '/wp/v2/kalaka_form/' . $id,
            ['content' => $this->slotFields() . $this->unknownBlock()]
        );

        self::assertSame(400, $response->get_status());
        self::assertSame($version, $this->readForm($id)->version);
        self::assertCount(2, $this->forms->definition($this->readForm($id))->fields);

        $result = wp_update_post([
            'ID' => $id,
            'post_content' => wp_slash($this->slotFields() . $this->unknownBlock()),
        ]);
        self::assertSame($id, $result);
        self::assertSame($version, $this->readForm($id)->version);
        self::assertStringNotContainsString('nope-field', (string) get_post_field('post_content', $id));
        self::assertSame(
            'Az űrlapot így nem lehet menteni: unknown_field_block („kalaka/nope-field”).',
            get_transient(DefinitionCheck::NOTICE . get_current_user_id())
        );
    }

    public function testAPlacementChangeOverRestIsCheckedWithTheNewPlacement(): void
    {
        $slot = $this->insertChecked($this->slotFields());
        $toAdditional = $this->rest('/wp/v2/kalaka_form/' . $slot, ['meta' => ['kalaka_placement' => 'additional']]);
        self::assertSame(400, $toAdditional->get_status());
        $data = $toAdditional->get_data();
        self::assertIsArray($data);
        self::assertStringContainsString('marking_on_additional_form', (string) $data['message']);
        self::assertSame('registration', get_post_meta($slot, 'kalaka_placement', true));

        $moved = $this->rest('/wp/v2/kalaka_form/' . $slot, [
            'content' => $this->field('text', 'note'),
            'meta' => ['kalaka_placement' => 'additional'],
        ]);
        self::assertSame(200, $moved->get_status());
        self::assertSame('additional', get_post_meta($slot, 'kalaka_placement', true));

        $additional = $this->insertChecked($this->field('text', 'note'), ['kalaka_placement' => 'additional']);
        $toSlot = $this->rest('/wp/v2/kalaka_form/' . $additional, ['meta' => ['kalaka_placement' => 'registration']]);
        self::assertSame(400, $toSlot->get_status());
        $data = $toSlot->get_data();
        self::assertIsArray($data);
        self::assertStringContainsString('contact_email_count', (string) $data['message']);

        $promoted = $this->rest('/wp/v2/kalaka_form/' . $additional, [
            'content' => $this->slotFields(),
            'meta' => ['kalaka_placement' => 'registration'],
        ]);
        self::assertSame(200, $promoted->get_status());
        self::assertSame('registration', get_post_meta($additional, 'kalaka_placement', true));
        self::assertCount(2, $this->forms->definition($this->readForm($additional))->fields);
    }

    public function testCodeCannotPublishABrokenFormEitherItIsKeptAsADraftWithANotice(): void
    {
        $id = $this->insertChecked($this->slotFields() . $this->unknownBlock());

        self::assertSame('draft', get_post_status($id));
        self::assertNull($this->forms->form($id));
        ob_start();
        DefinitionCheck::notice();
        $html = (string) ob_get_clean();
        self::assertStringContainsString('notice-error', $html);
        self::assertStringContainsString('unknown_field_block („kalaka/nope-field”)', $html);
        self::assertFalse(get_transient(DefinitionCheck::NOTICE . get_current_user_id()));
    }

    public function testRestoringABrokenRevisionKeepsThePublishedFormWorking(): void
    {
        // A revision from before the check: the form was published broken, then fixed.
        $id = $this->insertForm($this->slotFields() . $this->unknownBlock());
        $broken = $this->readForm($id)->version;
        wp_update_post(['ID' => $id, 'post_content' => wp_slash($this->slotFields())]);
        $version = $this->readForm($id)->version;
        self::assertNotSame($broken, $version);

        $restored = wp_restore_post_revision($broken);

        self::assertSame($id, $restored);
        self::assertSame('publish', get_post_status($id));
        self::assertSame($version, $this->readForm($id)->version);
        self::assertCount(2, $this->forms->definition($this->readForm($id))->fields);
        self::assertSame(
            'Az űrlapot így nem lehet menteni: unknown_field_block („kalaka/nope-field”).',
            get_transient(DefinitionCheck::NOTICE . get_current_user_id())
        );
    }

    public function testTheBrokenFormListIgnoresOtherPostTypesAndAutosaves(): void
    {
        $id = $this->insertChecked($this->slotFields());
        delete_option(BrokenForms::OPTION);

        self::factory()->post->create();
        $post = get_post($id, ARRAY_A);
        self::assertIsArray($post);
        _wp_put_post_revision(['post_content' => $this->unknownBlock()] + $post, true);

        self::assertNull(get_option(BrokenForms::OPTION, null));
    }

    public function testAFormBrokenBeforeTheCheckStaysClosedAndIsNamedInASiteWarning(): void
    {
        $working = $this->insertChecked($this->slotFields());
        $broken = $this->insertForm($this->slotFields() . $this->unknownBlock(), [], ['post_title' => 'Régi űrlap']);

        $shown = (new BrokenFormsWarning())->check();

        self::assertNotNull($shown);
        self::assertSame('Hibás közzétett űrlap', $shown->title);
        self::assertSame('#' . $broken . ' Régi űrlap: unknown_field_block kalaka/nope-field', $shown->details);
        self::assertStringNotContainsString('#' . $working . ' ', (string) $shown->details);
        $this->expectException(SystemFailure::class);
        $this->forms->definition($this->readForm($broken));
    }

    public function testTheWarningReadsTheStoredListWhichIsComputedOnceWhenMissing(): void
    {
        update_option(BrokenForms::OPTION, ['#1 Valami: invalid_key X'], false);
        $shown = (new BrokenFormsWarning())->check();
        self::assertNotNull($shown);
        self::assertSame('#1 Valami: invalid_key X', $shown->details);

        $broken = $this->insertForm($this->slotFields() . $this->unknownBlock(), [], ['post_title' => 'Régi']);
        delete_option(BrokenForms::OPTION);
        self::assertNull((new BrokenFormsWarning())->check());
        BrokenForms::ensure();
        self::assertSame(['#' . $broken . ' Régi: unknown_field_block kalaka/nope-field'], BrokenForms::stored());
        self::assertFalse(wp_load_alloptions()[BrokenForms::OPTION] ?? false);
    }

    public function testNoWarningWhileEveryPublishedFormWorks(): void
    {
        $this->insertChecked($this->slotFields());

        self::assertNull((new BrokenFormsWarning())->check());
    }

    public function testACodeSaveWithNoUserWritesALogLineAndAWarning(): void
    {
        $id = $this->insertChecked($this->slotFields());
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
                'post_content' => wp_slash($this->slotFields() . $this->unknownBlock()),
            ]));
        } finally {
            remove_action('wp_trigger_error_always_run', $listener, 10);
            wp_set_current_user($user);
        }

        self::assertSame('publish', get_post_status($id));
        self::assertStringNotContainsString('nope-field', (string) get_post_field('post_content', $id));
        self::assertStringContainsString('saving form ' . $id, $log);
        self::assertStringContainsString('unknown_field_block kalaka/nope-field', $log);
        self::assertCount(1, $warnings);
        self::assertSame(E_USER_WARNING, $warnings[0][2]);
        self::assertStringContainsString('unknown_field_block kalaka/nope-field', $warnings[0][1]);
    }

    public function testALoggedInAdminsVetoedSaveIsANoticeAndALogLineButNoPhpWarning(): void
    {
        $id = $this->insertChecked($this->slotFields());
        $warnings = [];
        $listener = static function (string $function, string $message, int $level) use (&$warnings): void {
            $warnings[] = [$function, $message, $level];
        };
        add_action('wp_trigger_error_always_run', $listener, 10, 3);
        try {
            [$log] = $this->captureLog(fn (): int => (int) wp_update_post([
                'ID' => $id,
                'post_content' => wp_slash($this->slotFields() . $this->unknownBlock()),
            ]));
        } finally {
            remove_action('wp_trigger_error_always_run', $listener, 10);
        }

        self::assertSame('publish', get_post_status($id));
        self::assertSame(
            'Az űrlapot így nem lehet menteni: unknown_field_block („kalaka/nope-field”).',
            get_transient(DefinitionCheck::NOTICE . get_current_user_id())
        );
        self::assertStringContainsString('saving form ' . $id, $log);
        self::assertSame([], $warnings);
    }

    public function testARestRefusalWritesNoLogLine(): void
    {
        [$log, $response] = $this->captureLog(fn (): \WP_REST_Response => $this->rest('/wp/v2/kalaka_form', [
            'title' => 'Regisztráció',
            'status' => 'publish',
            'content' => $this->slotFields() . $this->unknownBlock(),
            'meta' => ['kalaka_event_id' => 7, 'kalaka_placement' => 'registration'],
        ]));

        self::assertSame(400, $response->get_status());
        self::assertStringNotContainsString('saving form', $log);
    }

    /**
     * A date for a scheduled form, from the runner's clock (the tests run at a fixed instant, TestClock).
     * WordPress compares a scheduled date with the real time, so it lies years after the clock.
     */
    private function later(): string
    {
        return $this->fromClock('+10 years');
    }

    /** The runner's clock moved by `$shift`, as a GMT post date. */
    private function fromClock(string $shift): string
    {
        return Runner::current()->clock()->now()->setTimezone(new \DateTimeZone('UTC'))->modify($shift)
            ->format('Y-m-d H:i:s');
    }

    /** @return array<string, mixed> a scheduled form's status and date */
    private function scheduled(): array
    {
        return ['post_status' => 'future', 'post_date_gmt' => $this->later()];
    }

    /** Moves a scheduled form's date into the past, so WordPress publishes it when its event fires. */
    private function due(int $id): void
    {
        global $wpdb;
        // From the runner's clock too; WordPress publishes only a date before the real time, so a year back.
        $past = $this->fromClock('-1 year');
        $wpdb->update($wpdb->posts, ['post_date' => $past, 'post_date_gmt' => $past], ['ID' => $id]);
        clean_post_cache($id);
    }

    public function testTheEditorCannotScheduleABrokenForm(): void
    {
        $response = $this->rest('/wp/v2/kalaka_form', [
            'title' => 'Regisztráció',
            'status' => 'future',
            'date_gmt' => $this->later(),
            'content' => $this->slotFields() . $this->unknownBlock(),
            'meta' => ['kalaka_event_id' => 7, 'kalaka_placement' => 'registration'],
        ]);

        self::assertSame(400, $response->get_status());
        self::assertSame([], get_posts(['post_type' => 'kalaka_form', 'post_status' => 'any']));
    }

    public function testCodeCannotScheduleABrokenFormEitherItIsKeptAsADraft(): void
    {
        $id = $this->insertChecked(
            $this->slotFields() . $this->unknownBlock(),
            [],
            ['post_status' => 'future', 'post_date_gmt' => $this->later()]
        );

        self::assertSame('draft', get_post_status($id));
        self::assertSame(
            'Az űrlapot így nem lehet menteni: unknown_field_block („kalaka/nope-field”).',
            get_transient(DefinitionCheck::NOTICE . get_current_user_id())
        );
    }

    public function testAnEditThatWouldBreakAScheduledFormKeepsItsStoredContent(): void
    {
        $id = $this->insertChecked($this->slotFields(), [], $this->scheduled());
        self::assertSame('future', get_post_status($id));

        wp_update_post(['ID' => $id, 'post_content' => wp_slash($this->slotFields() . $this->unknownBlock())]);

        self::assertSame('future', get_post_status($id));
        self::assertStringNotContainsString('nope-field', (string) get_post_field('post_content', $id));
    }

    public function testAWorkingScheduledFormIsPublishedWhenItsTimeComes(): void
    {
        $id = $this->insertChecked($this->slotFields(), [], $this->scheduled());
        $this->due($id);

        do_action('publish_future_post', $id);

        self::assertSame('publish', get_post_status($id));
        self::assertCount(2, $this->forms->definition($this->readForm($id))->fields);
    }

    public function testAScheduledFormBrokenSinceGoesBackToDraftAndItsAuthorIsTold(): void
    {
        $author = self::factory()->user->create();
        // Broken after it was scheduled (as by a field type that is gone): written around the save check.
        $id = $this->insertForm(
            $this->slotFields() . $this->unknownBlock(),
            [],
            ['post_status' => 'future', 'post_date_gmt' => $this->later(), 'post_author' => $author]
        );
        self::assertSame('future', get_post_status($id));
        $this->due($id);

        do_action('publish_future_post', $id);

        self::assertSame('draft', get_post_status($id));
        self::assertNull($this->forms->form($id));
        self::assertSame(
            'Az űrlapot így nem lehet menteni: unknown_field_block („kalaka/nope-field”).',
            get_transient(DefinitionCheck::NOTICE . $author)
        );
    }
}
