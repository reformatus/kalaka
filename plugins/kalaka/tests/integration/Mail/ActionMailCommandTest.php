<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Mail;

use Kalaka\Admin\Api\AdminAccess;
use Kalaka\Mail\Api\ActionMailSettings;
use Kalaka\Mail\Body\AccountLinkRule;
use Kalaka\Mail\Cli\ActionMailCommand;
use Kalaka\Mail\Body\MailPosts;
use Kalaka\Foundation\ObjectCache;

/** `wp kalaka action-mail list|show|on|off|body|layout` (G4): the state it prints, the changes and the refusals. */
final class ActionMailCommandTest extends MailBodyTestCase
{
    /** @var list<string> */
    private array $files = [];

    /** @var list<array{string, list<array<string, string>>}> */
    private array $printed = [];

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function set_up(): void
    {
        require_once __DIR__ . '/../Foundation/Seed/WpCliDouble.php';
        parent::set_up();
        if (!defined('WP_CLI::IS_TEST_DOUBLE')) {
            self::markTestSkipped('The real WP-CLI is loaded; the recording double is not in use.');
        }
        \WP_CLI::reset();
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function tear_down(): void
    {
        array_map('wp_delete_file', $this->files);
        $this->files = [];
        wp_set_current_user(0);
        parent::tear_down();
    }

    private function command(): ActionMailCommand
    {
        $this->printed = [];

        return new ActionMailCommand(
            print: function (string $format, array $items): void {
                $this->printed[] = [$format, $items];
            }
        );
    }

    private function file(string $content): string
    {
        $path = (string) wp_tempnam('kalaka-mail-');
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a temp file
        file_put_contents($path, $content);
        $this->files[] = $path;

        return $path;
    }

    private function fails(\Closure $run): string
    {
        try {
            $run();
            self::fail('The command should have ended in an error.');
        } catch (\RuntimeException $e) {
            self::assertSame('WP_CLI::error', $e->getMessage());
        }
        $calls = array_values(array_filter(\WP_CLI::$calls, static fn (array $call): bool => $call[0] === 'error'));
        self::assertCount(1, $calls);

        return $calls[0][1][0];
    }

    /** @return array<string, mixed> the settings row of the mail */
    private function state(int $event, string $mail): array
    {
        foreach ((new ActionMailSettings())->ofEvent($event)->toArray()['mails'] as $row) {
            if ($row['mail'] === $mail) {
                return $row;
            }
        }
        self::fail('No such mail.');
    }

    private function postCount(): int
    {
        return count(get_posts(['post_type' => MailPosts::POST_TYPE, 'post_status' => 'any', 'numberposts' => -1]));
    }

    private function accountsEvent(): int
    {
        global $wpdb;
        $event = $this->published();
        $wpdb->update($wpdb->prefix . 'kalaka_events', ['account_mode' => 'mandatory'], ['id' => $event]);
        ObjectCache::clear();

        return $event;
    }

    private function withLink(): string
    {
        return $this->paragraph('Jelszó: <span data-kalaka-value="account.password_link">link</span>');
    }

    public function testListPrintsEveryMailAndShowPrintsOneWithItsBody(): void
    {
        $event = $this->create();
        $body = $this->document($event, $this->paragraph('Szia'), 'Tárgy');
        $this->switchOn($event, 'waitlisted', $body);

        $this->command()->listMails([(string) $event], []);

        self::assertSame('table', $this->printed[0][0]);
        self::assertSame(
            ['registered', 'waitlisted', 'payment_recorded', 'confirmed', 'link_regenerated'],
            array_column($this->printed[0][1], 'mail')
        );
        self::assertSame(
            ['mail' => 'waitlisted', 'label' => 'Jelentkezés várólistára helyezve', 'enabled' => 'yes',
                'body_id' => (string) $body, 'layout' => 'yes'],
            $this->printed[0][1][1]
        );
        self::assertSame('no', $this->printed[0][1][0]['enabled']);
        self::assertSame('', $this->printed[0][1][0]['body_id']);

        $this->command()->show([(string) $event, 'waitlisted'], []);
        $shown = json_decode(\WP_CLI::$calls[0][1], true);
        self::assertTrue($shown['enabled']);
        self::assertSame($body, $shown['body_id']);
        $expected = ['subject' => 'Tárgy', 'status' => 'publish', 'content' => $this->paragraph('Szia')];
        self::assertSame($expected, $shown['body']);

        \WP_CLI::reset();
        $this->command()->show([(string) $event, 'confirmed'], []);
        self::assertNull(json_decode(\WP_CLI::$calls[0][1], true)['body']);
    }

    public function testABodyIsSavedPublishedAssignedAndTheMailSwitchedOnAndOff(): void
    {
        $event = $this->create();
        $content = $this->paragraph('Szia {{ nem jelölő }} <strong>Teszt</strong>');
        $file = $this->file($content);

        $this->command()->body([(string) $event, 'waitlisted'], ['file' => $file, 'title' => 'Várólista']);

        $state = $this->state($event, 'waitlisted');
        self::assertFalse($state['enabled']);
        $post = get_post($state['body_id']);
        self::assertInstanceOf(\WP_Post::class, $post);
        self::assertSame('publish', $post->post_status);
        self::assertSame('Várólista', $post->post_title);
        self::assertSame($content, $post->post_content);
        self::assertSame($event, (new MailPosts())->eventOf($post->ID));

        $this->command()->on([(string) $event, 'waitlisted'], []);
        self::assertTrue($this->state($event, 'waitlisted')['enabled']);
        $this->command()->off([(string) $event, 'waitlisted'], []);
        self::assertFalse($this->state($event, 'waitlisted')['enabled']);
        self::assertCount(3, $this->entriesOf('kalaka/update-action-mail'));
    }

    public function testABodyIsStoredByteForByteEvenWhereKsesWouldChangeIt(): void
    {
        $event = $this->create();
        $content = $this->paragraph(
            'Teszt <iframe src="https://example.org/x"></iframe> <b style="color:red" onclick="x()">k</b>'
        );
        kses_init_filters();
        try {
            // The filters are on, as for a user without `unfiltered_html`: WordPress would change this content.
            self::assertNotSame($content, apply_filters('content_save_pre', wp_slash($content)));

            $options = ['file' => $this->file($content), 'title' => 'Tárgy'];
            $this->command()->body([(string) $event, 'confirmed'], $options);
        } finally {
            kses_remove_filters();
        }

        self::assertSame($content, get_post($this->state($event, 'confirmed')['body_id'])->post_content);
    }

    public function testAFirstBodyTheAssignmentRefusesLeavesNoPostBehind(): void
    {
        global $wpdb;
        $event = $this->create();
        // The event is gone between the save and the assignment: the use case refuses with "not found".
        $vanish = static function () use ($wpdb, $event): void {
            $wpdb->delete($wpdb->prefix . 'kalaka_events', ['id' => $event]);
            ObjectCache::clear();
        };
        add_action('wp_insert_post', $vanish);
        try {
            $this->fails(fn () => $this->command()->body(
                [(string) $event, 'confirmed'],
                ['file' => $this->file($this->paragraph('Szia')), 'title' => 'Tárgy']
            ));
        } finally {
            remove_action('wp_insert_post', $vanish);
        }

        self::assertSame(0, $this->postCount());
    }

    public function testASecondBodyUpdatesTheSamePostAndKeepsTheSubject(): void
    {
        $event = $this->create();
        $first = $this->file($this->paragraph('Egy'));
        $this->command()->body([(string) $event, 'confirmed'], ['file' => $first, 'title' => 'Tárgy']);
        $id = $this->state($event, 'confirmed')['body_id'];
        $posts = $this->postCount();

        $this->command()->body([(string) $event, 'confirmed'], ['file' => $this->file($this->paragraph('Kettő'))]);

        self::assertSame($id, $this->state($event, 'confirmed')['body_id']);
        self::assertSame($posts, $this->postCount());
        self::assertSame($this->paragraph('Kettő'), get_post($id)->post_content);
        self::assertSame('Tárgy', get_post($id)->post_title);
    }

    public function testAFirstBodyNeedsItsSubject(): void
    {
        $event = $this->create();

        $text = $this->fails(fn () => $this->command()->body(
            [(string) $event, 'confirmed'],
            ['file' => $this->file($this->paragraph('Szia'))]
        ));

        self::assertStringContainsString('--title', $text);
        self::assertSame(0, $this->postCount());
    }

    public function testABodyTheSaveCheckRefusesFailsWithItsErrorAndSavesNothing(): void
    {
        $event = $this->create();
        $content = $this->paragraph('Szia <span data-kalaka-value="registration.nincs_ilyen">x</span>');

        $text = $this->fails(fn () => $this->command()->body(
            [(string) $event, 'registered'],
            ['file' => $this->file($content), 'title' => 'Tárgy']
        ));

        self::assertStringStartsWith('A sablon szövegét így nem lehet menteni:', $text);
        self::assertSame(0, $this->postCount());
        self::assertNull($this->state($event, 'registered')['body_id']);
    }

    public function testInAnEventWithAccountsTheRegisteredBodyNeedsTheLinkAndTheMailStaysOn(): void
    {
        $event = $this->accountsEvent();

        $text = $this->fails(fn () => $this->command()->body(
            [(string) $event, 'registered'],
            ['file' => $this->file($this->paragraph('Köszönjük')), 'title' => 'Tárgy']
        ));
        self::assertSame(AccountLinkRule::NEEDS_LINK, $text);
        self::assertSame(0, $this->postCount());

        \WP_CLI::reset();
        $this->command()->body(
            [(string) $event, 'registered'],
            ['file' => $this->file($this->withLink()), 'title' => 'Tárgy']
        );
        $this->command()->on([(string) $event, 'registered'], []);
        self::assertTrue($this->state($event, 'registered')['enabled']);

        \WP_CLI::reset();
        $text = $this->fails(fn () => $this->command()->off([(string) $event, 'registered'], []));
        self::assertStringEndsWith(AccountLinkRule::NO_SWITCH_OFF, $text);
        self::assertTrue($this->state($event, 'registered')['enabled']);

        \WP_CLI::reset();
        $text = $this->fails(fn () => $this->command()->body(
            [(string) $event, 'registered'],
            ['file' => $this->file($this->paragraph('Köszönjük'))]
        ));
        self::assertSame(AccountLinkRule::NEEDS_LINK, $text);
        self::assertSame($this->withLink(), get_post($this->state($event, 'registered')['body_id'])->post_content);
    }

    public function testSwitchingOnWithoutABodyFailsWithTheUseCasesError(): void
    {
        $event = $this->create();

        $text = $this->fails(fn () => $this->command()->on([(string) $event, 'confirmed'], []));

        self::assertStringEndsWith('Előbb add meg a sablon szövegét.', $text);
        self::assertFalse($this->state($event, 'confirmed')['enabled']);
    }

    public function testTheLayoutIsSetFromAFileAndARefusedOneChangesNothing(): void
    {
        $event = $this->create();
        $html = "<div class=\"keret\">{{content}}</div>\n";

        $this->command()->layout([(string) $event], ['file' => $this->file($html)]);
        self::assertSame($html, (new ActionMailSettings())->ofEvent($event)->layoutHtml);

        \WP_CLI::reset();
        $bad = $this->file('<p>nincs</p>');
        $text = $this->fails(fn () => $this->command()->layout([(string) $event], ['file' => $bad]));
        self::assertStringEndsWith('pontosan egyszer kell szerepelnie.', $text);
        self::assertSame($html, (new ActionMailSettings())->ofEvent($event)->layoutHtml);
    }

    public function testArgumentsAreChecked(): void
    {
        $event = $this->create();

        $text = $this->fails(fn () => $this->command()->on(['abc', 'registered'], []));
        self::assertStringContainsString('event ID', $text);
        \WP_CLI::reset();
        $text = $this->fails(fn () => $this->command()->on([(string) $event, 'nincs'], []));
        self::assertStringContainsString('must be one of', $text);
        \WP_CLI::reset();
        $text = $this->fails(fn () => $this->command()->layout([(string) $event], []));
        self::assertStringContainsString('--file', $text);
        \WP_CLI::reset();
        self::assertStringContainsString('cannot be read', $this->fails(
            fn () => $this->command()->layout([(string) $event], ['file' => '/nincs/ilyen/fajl'])
        ));
        \WP_CLI::reset();
        self::assertStringContainsString('empty', $this->fails(
            fn () => $this->command()->layout([(string) $event], ['file' => $this->file("\n")])
        ));
        \WP_CLI::reset();
        $missing = (string) ($event + 999);
        self::assertNotSame('', $this->fails(fn () => $this->command()->show([$missing, 'registered'], [])));
    }

    public function testARefusedUserLearnsNothingAboutWhetherAnEventExists(): void
    {
        $event = $this->create();
        wp_set_current_user(self::factory()->user->create());

        $existing = $this->fails(fn () => $this->command()->listMails([(string) $event], []));
        \WP_CLI::reset();
        $missing = $this->fails(fn () => $this->command()->listMails([(string) ($event + 999)], []));
        \WP_CLI::reset();
        $existingShow = $this->fails(fn () => $this->command()->show([(string) $event, 'confirmed'], []));
        \WP_CLI::reset();
        $missingShow = $this->fails(fn () => $this->command()->show([(string) ($event + 999), 'confirmed'], []));

        self::assertSame($existing, $missing);
        self::assertSame($existing, $existingShow);
        self::assertSame($existing, $missingShow);
    }

    public function testAUserWithoutFullAccessChangesNothingAndOneWithItDoes(): void
    {
        $event = $this->create();
        $file = $this->file($this->paragraph('Szia'));
        wp_set_current_user(self::factory()->user->create());

        $options = ['file' => $file, 'title' => 'Tárgy'];
        $this->fails(fn () => $this->command()->body([(string) $event, 'confirmed'], $options));
        self::assertSame(0, $this->postCount());
        \WP_CLI::reset();
        $this->fails(fn () => $this->command()->layout([(string) $event], ['file' => $this->file('{{content}}!')]));
        self::assertSame('{{content}}', (new ActionMailSettings())->ofEvent($event)->layoutHtml);

        \WP_CLI::reset();
        $this->fails(fn () => $this->command()->listMails([(string) $event], []));
        self::assertSame([], $this->printed);
        \WP_CLI::reset();
        $this->fails(fn () => $this->command()->show([(string) $event, 'confirmed'], []));

        $user = self::factory()->user->create();
        get_userdata($user)->add_cap(AdminAccess::FULL_ACCESS);
        wp_set_current_user($user);
        \WP_CLI::reset();
        $this->command()->listMails([(string) $event], []);
        self::assertCount(1, $this->printed);
        \WP_CLI::reset();
        $this->command()->body([(string) $event, 'confirmed'], $options);
        $this->command()->on([(string) $event, 'confirmed'], []);
        self::assertTrue($this->state($event, 'confirmed')['enabled']);
        $entries = $this->entriesOf('kalaka/update-action-mail');
        self::assertSame($user, (int) $entries[count($entries) - 1]['actor_user_id']);
    }
}
