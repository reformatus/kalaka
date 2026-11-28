<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Mail;

use Kalaka\Admin\Api\AdminAccess;
use Kalaka\Mail\Body\BodyCheck;
use Kalaka\Mail\Body\MailRenderer;
use Kalaka\Mail\Body\MailValues;
use Kalaka\Mail\BodyUnusable;
use Kalaka\Mail\Module;

/** The placeholders typed by hand in a mail body (owner 2026-11-26, N960 option (a)): the save and the send. */
final class MailPlaceholderTest extends MailBodyTestCase
{
    private int $user = 0;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        (new Module())->registerMeta();
        $this->user = self::factory()->user->create();
        $user = get_userdata($this->user);
        self::assertInstanceOf(\WP_User::class, $user);
        $user->add_cap(AdminAccess::FULL_ACCESS);
        wp_set_current_user($this->user);
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

    public function testARestSaveWithUnknownPlaceholdersIsRefusedWithAllOfThemAsTyped(): void
    {
        $event = $this->create();
        $this->slotForm($event);
        $id = $this->document($event, $this->paragraph('Szia'));

        $inner = $this->paragraph('{{recipient.name}} {{foo}} {{ bar }} {{content}}');
        $response = $this->restSave($id, ['content' => $inner]);

        self::assertSame(422, $response->get_status());
        $error = $response->as_error();
        self::assertInstanceOf(\WP_Error::class, $error);
        self::assertSame('kalaka_unknown_placeholder', $error->get_error_code());
        self::assertStringEndsWith(
            'Ismeretlen jelölő: {{foo}}, {{ bar }}, {{content}}. A mentéshez javítani vagy törölni kell.',
            $error->get_error_message()
        );
        $data = $error->get_error_data();
        self::assertIsArray($data);
        self::assertSame(['{{foo}}', '{{ bar }}', '{{content}}'], $data['keys']);
        self::assertSame($this->paragraph('Szia'), get_post_field('post_content', $id));
    }

    public function testKnownKeysSaveInTextAndInAButtonsLink(): void
    {
        $event = $this->create();
        $this->slotForm($event);
        $id = $this->document($event, $this->paragraph('Szia'));
        $inner = $this->paragraph('Kedves {{recipient.name}}, kódod {{registration.code}}, {{camps}}.')
            . '<!-- wp:button {"url":"{{registration.link}}"} --><div class="wp-block-button">'
            . '<a class="wp-block-button__link wp-element-button" href="{{registration.link}}">Nyisd meg</a></div>'
            . '<!-- /wp:button -->';

        $response = $this->restSave($id, ['content' => $inner]);

        self::assertSame(200, $response->get_status());
        self::assertSame($inner, get_post_field('post_content', $id));
    }

    public function testAnyOtherSavePathVetoesAndTellsTheSavingUserTheText(): void
    {
        $event = $this->create();
        $id = $this->document($event, $this->paragraph('Szia'));

        wp_update_post(['ID' => $id, 'post_content' => $this->paragraph('{{foo}}')]);

        self::assertSame($this->paragraph('Szia'), get_post_field('post_content', $id));
        $notice = get_transient(BodyCheck::NOTICE . $this->user);
        self::assertIsString($notice);
        self::assertStringEndsWith('Ismeretlen jelölő: {{foo}}. A mentéshez javítani vagy törölni kell.', $notice);
    }

    public function testTheCommandLineRefusesWithTheSameText(): void
    {
        $event = $this->create();

        $message = BodyCheck::refusal(0, $this->paragraph('{{foo}}'), $event);

        self::assertIsString($message);
        self::assertStringEndsWith('Ismeretlen jelölő: {{foo}}. A mentéshez javítani vagy törölni kell.', $message);
    }

    public function testTheTypedKeysAreFoundForThePasswordLinkAndThePageLinks(): void
    {
        self::assertTrue(MailValues::readsPasswordLink('<p>{{account.password_link}}</p>'));
        self::assertTrue(MailValues::readsPasswordLink('<a href="{{ account.password_link }}">x</a>'));
        self::assertFalse(MailValues::readsPasswordLink('<p>{{registration.link}}</p>'));
        self::assertSame([12, 7], MailValues::pageLinks('<a href="{{page_12.link}}">x</a> {{page_7.link}}'));
    }

    public function testATypedPageLinkIsDeclaredOnlyForAPublishedPage(): void
    {
        $event = $this->create();
        $page = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Oldal'], true);
        self::assertIsInt($page);
        $draft = wp_insert_post(['post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Más'], true);
        self::assertIsInt($draft);

        self::assertNull(BodyCheck::unknown('<a href="{{page_' . $page . '.link}}">x</a>', $event));
        $unknown = BodyCheck::unknown('<a href="{{page_' . $draft . '.link}}">x</a>', $event);
        self::assertNotNull($unknown);
        self::assertSame(['{{page_' . $draft . '.link}}'], $unknown->keys);
    }

    public function testTheMailRendersItsPlaceholdersAndNothingElseOfTheLayout(): void
    {
        $event = $this->create();
        $key = $this->registration($event);
        $body = $this->document(
            $event,
            $this->paragraph('Kedves {{recipient.name}}, kódod {{registration.code}}.')
            . '<!-- wp:button {"url":"{{registration.link}}"} --><div class="wp-block-button">'
            . '<a class="wp-block-button__link wp-element-button" href="{{registration.link}}">Nyisd meg</a></div>'
            . '<!-- /wp:button -->'
        );
        $this->switchOn($event, 'registered', $body);

        $html = (new MailRenderer())->render($event, 'registered', $key)->html;

        self::assertStringContainsString('Kedves Teszt Elek, kódod 12A3.', $html);
        self::assertStringContainsString(self::SECRET, $html);
        self::assertStringNotContainsString('{{', $html);
    }

    public function testAKeyThatCannotBeResolvedAtSendFailsTheMailWithTheKeyAsTyped(): void
    {
        $event = $this->create();
        $key = $this->registration($event);
        $body = $this->brokenDocument($event, $this->paragraph('Szia {{foo}} {{content}}'));
        $this->switchOn($event, 'registered', $body);

        try {
            (new MailRenderer())->render($event, 'registered', $key);
            self::fail('Expected a failure.');
        } catch (BodyUnusable $e) {
            self::assertSame('unknown_placeholder {{foo}}, {{content}}', $e->problem);
        }
    }
}
