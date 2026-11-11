<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Mail;

use Kalaka\Mail\Catalogue\SetEventMailLayout;
use Kalaka\Mail\Catalogue\UpdateActionMail;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * The base of the mail-body tests: a registration of the test's choosing (its slot form, first submission
 * and field values written behind the repositories), `kalaka_mail` documents and the catalogue set through
 * its use cases.
 */
abstract class MailBodyTestCase extends MailTestCase
{
    protected const SECRET = 'TesztTitokTesztTitok01';

    /** A published registration-slot form of the event: name, contact email and a choice of camps. */
    protected function slotForm(int $event): int
    {
        $content = $this->block('kalaka/name-field', ['key' => 'name', 'label' => 'Név', 'displayName' => true])
            . $this->block('kalaka/email-field', ['key' => 'email', 'label' => 'E-mail', 'contactEmail' => true])
            . $this->block('kalaka/choice-field', [
                'key' => 'camps',
                'label' => 'Tábor',
                'multiple' => true,
                'options' => [['label' => 'Fiú', 'value' => 'ff'], ['label' => 'Lány', 'value' => 'll']],
            ]);
        $id = wp_insert_post([
            'post_type' => 'kalaka_form',
            'post_status' => 'publish',
            'post_title' => 'Jelentkezés',
            'post_content' => $content,
            'meta_input' => ['kalaka_event_id' => $event, 'kalaka_placement' => 'registration'],
        ], true);
        self::assertIsInt($id);

        return $id;
    }

    /**
     * A registration with its first submission on the slot form and its field values.
     *
     * @param array<string, string> $values field key → stored value
     */
    protected function registration(
        int $event,
        string $code = '12A3',
        array $values = ['name' => 'Teszt Elek', 'email' => 'teszt@example.test', 'camps' => '["ff"]'],
        string $status = 'registered',
        ?int $form = null,
    ): RegistrationKey {
        global $wpdb;
        $form ??= $this->slotForm($event);
        $revision = wp_get_latest_revision_id_and_total_count($form);
        self::assertIsArray($revision);
        $wpdb->insert($wpdb->prefix . 'kalaka_registrations', [
            'event_id' => $event,
            'code' => $code,
            'version' => 1,
            'status' => $status,
            'secret' => self::SECRET,
            'created_at' => '2026-11-29 08:00:00.000000',
        ]);
        $wpdb->insert($wpdb->prefix . 'kalaka_submissions', [
            'event_id' => $event,
            'code' => $code,
            'number' => 1,
            'form_id' => $form,
            'form_version' => $revision['latest_id'],
            'answers' => '{}',
            'submitted_at' => '2026-11-29 08:00:00.000000',
        ]);
        foreach ($values as $key => $value) {
            $wpdb->insert($wpdb->prefix . 'kalaka_field_values', [
                'event_id' => $event,
                'code' => $code,
                'field_key' => $key,
                'version' => 1,
                'value' => $value,
            ]);
        }

        return new RegistrationKey($event, $code);
    }

    /** A published `kalaka_mail` document of the event. */
    protected function document(int $event, string $inner, string $title = 'Sikeres jelentkezés'): int
    {
        $id = wp_insert_post([
            'post_type' => 'kalaka_mail',
            'post_status' => 'publish',
            'post_title' => $title,
            'post_content' => $inner,
            'meta_input' => ['kalaka_event_id' => $event],
        ], true);
        self::assertIsInt($id);

        return $id;
    }

    protected function paragraph(string $html): string
    {
        return "<!-- wp:paragraph -->\n<p>" . $html . "</p>\n<!-- /wp:paragraph -->";
    }

    /** @param array<string, mixed> $condition */
    protected function branch(string $name, string $inner, ?array $condition = null): string
    {
        $attributes = $condition === null ? '' : ' ' . wp_json_encode(['condition' => $condition]);

        return '<!-- wp:kalaka/' . $name . $attributes . " -->\n" . $inner . "\n<!-- /wp:kalaka/" . $name . ' -->';
    }

    /** @param array<string, mixed> $attributes */
    protected function block(string $name, array $attributes): string
    {
        return serialize_block([
            'blockName' => $name,
            'attrs' => $attributes,
            'innerBlocks' => [],
            'innerHTML' => '',
            'innerContent' => [],
        ]);
    }

    /**
     * Switches the action mail on with the body, through the use case.
     *
     * @return array<string, mixed> the settings it returns
     */
    protected function switchOn(int $event, string $mail, int $body, bool $layout = true): array
    {
        $settings = $this->exec(
            new UpdateActionMail(),
            ['event_id' => $event, 'mail' => $mail, 'body_id' => $body, 'enabled' => true, 'layout' => $layout]
        );
        self::assertIsArray($settings);

        return $settings;
    }

    protected function setLayout(int $event, string $html): void
    {
        $this->exec(new SetEventMailLayout(), ['event_id' => $event, 'html' => $html]);
    }
}
