<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Mail;

use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Foundation\ObjectCache;
use Kalaka\Mail\Api\ActionMail;
use Kalaka\Mail\Api\ActionMailSettings;
use Kalaka\Mail\Api\MailTemplates;

/**
 * The template of a sending point (küldési pont; owner 2026-11-27, walkthrough 8): a draft may be assigned to a
 * point that is off, only a published one switches it on, unassigning or swapping switches the point off and a
 * save of the template does not, "new template" makes the draft and assigns it, and the points read says why a
 * point cannot be switched on and which points work.
 */
final class MailPointTemplateTest extends MailAdminTestCase
{
    /** @return array<string, mixed> */
    private function point(int $event, string $mail): array
    {
        foreach ((new ActionMailSettings())->ofEvent($event)->toArray()['mails'] as $row) {
            if ($row['mail'] === $mail) {
                return $row;
            }
        }
        self::fail('No such point.');
    }

    private function draft(int $event, string $title = 'Vázlat'): int
    {
        $id = wp_insert_post([
            'post_type' => 'kalaka_mail',
            'post_status' => 'draft',
            'post_title' => $title,
            'meta_input' => ['kalaka_event_id' => $event],
        ]);
        self::assertIsInt($id);

        return $id;
    }

    private function codeOf(callable $call): string
    {
        try {
            $call();
        } catch (RuleViolation $refused) {
            return $refused->errorCode();
        }
        self::fail('Not refused.');
    }

    public function testAnOffPointMayHoldADraftAndOnlyAPublishedTemplateSwitchesItOn(): void
    {
        $event = $this->published();
        $draft = $this->draft($event);

        $this->updateMail(['event_id' => $event, 'mail' => 'waitlisted', 'body_id' => $draft]);
        self::assertSame([false, $draft], [
            $this->point($event, 'waitlisted')['enabled'], $this->point($event, 'waitlisted')['body_id'],
        ]);
        self::assertSame('kalaka_point_template_unpublished', $this->codeOf(
            fn () => $this->updateMail(['event_id' => $event, 'mail' => 'waitlisted', 'enabled' => true])
        ));

        wp_update_post(['ID' => $draft, 'post_status' => 'publish']);
        $this->updateMail(['event_id' => $event, 'mail' => 'waitlisted', 'enabled' => true]);
        self::assertTrue($this->point($event, 'waitlisted')['enabled']);
    }

    public function testUnassigningOrSwappingTheTemplateSwitchesThePointOffAndASaveOfTheTemplateDoesNot(): void
    {
        $event = $this->published();
        $first = $this->document($event, $this->paragraph('Egy'), 'Egy');
        $second = $this->document($event, $this->paragraph('Kettő'), 'Kettő');
        $this->switchOn($event, 'waitlisted', $first);

        // A save of the template itself leaves the point on.
        wp_update_post(['ID' => $first, 'post_content' => $this->paragraph('Egy, újra')]);
        self::assertTrue($this->point($event, 'waitlisted')['enabled']);

        // A swap switches the point off.
        $settings = $this->updateMail(['event_id' => $event, 'mail' => 'waitlisted', 'body_id' => $second]);
        self::assertSame([false, $second], [$settings['mails'][1]['enabled'], $settings['mails'][1]['body_id']]);

        // So does an unassignment.
        $this->updateMail(['event_id' => $event, 'mail' => 'waitlisted', 'enabled' => true]);
        $this->updateMail(['event_id' => $event, 'mail' => 'waitlisted', 'body_id' => null]);
        self::assertSame(
            [false, null],
            [$this->point($event, 'waitlisted')['enabled'], $this->point($event, 'waitlisted')['body_id']]
        );

        // A call that says `enabled` with the new template is the caller's word: on, with a published template.
        $this->updateMail(['event_id' => $event, 'mail' => 'waitlisted', 'body_id' => $first, 'enabled' => true]);
        $this->updateMail(['event_id' => $event, 'mail' => 'waitlisted', 'body_id' => $second, 'enabled' => true]);
        self::assertSame(
            [true, $second],
            [$this->point($event, 'waitlisted')['enabled'], $this->point($event, 'waitlisted')['body_id']]
        );
    }

    public function testTheRegisteredPointOfAnEventWithAccountsStaysOnWhenItsTemplateIsSwapped(): void
    {
        global $wpdb;
        $event = $this->published();
        $wpdb->update($wpdb->prefix . 'kalaka_events', ['account_mode' => 'mandatory'], ['id' => $event]);
        ObjectCache::clear();
        $link = '<span data-kalaka-value="account.password_link">link</span>';
        $first = $this->document($event, $this->paragraph($link), 'Egy');
        $second = $this->document($event, $this->paragraph($link), 'Kettő');
        $draft = $this->draft($event);
        $this->switchOn($event, 'registered', $first);

        $this->updateMail(['event_id' => $event, 'mail' => 'registered', 'body_id' => $second]);
        self::assertSame(
            [true, $second],
            [$this->point($event, 'registered')['enabled'], $this->point($event, 'registered')['body_id']]
        );
        // A draft cannot be the template of a point that cannot go off.
        self::assertSame('kalaka_point_template_unpublished', $this->codeOf(
            fn () => $this->updateMail(['event_id' => $event, 'mail' => 'registered', 'body_id' => $draft])
        ));
        self::assertSame($second, $this->point($event, 'registered')['body_id']);
    }

    public function testTheNewTemplateIsAnEmptyDraftAssignedToThePointWhichIsOff(): void
    {
        $event = $this->published();
        $old = $this->document($event, $this->paragraph('Régi'), 'Régi');
        $this->switchOn($event, 'waitlisted', $old);
        $this->as($this->admin->userId);

        $response = $this->rest('POST', '/events/' . $event . '/mail/points/waitlisted/template');

        self::assertSame(201, $response->get_status());
        $data = $response->get_data();
        self::assertSame(['id', 'edit_url'], array_keys($data));
        $post = get_post($data['id']);
        self::assertInstanceOf(\WP_Post::class, $post);
        self::assertSame(['kalaka_mail', 'draft', ''], [$post->post_type, $post->post_status, $post->post_content]);
        self::assertSame($event, (int) get_post_meta($post->ID, 'kalaka_event_id', true));
        self::assertStringEndsWith('post.php?post=' . $post->ID . '&action=edit', $data['edit_url']);
        self::assertSame(
            [false, $post->ID],
            [$this->point($event, 'waitlisted')['enabled'], $this->point($event, 'waitlisted')['body_id']]
        );
        self::assertCount(2, $this->entriesOf('kalaka/update-action-mail'));
    }

    public function testTheNewTemplateIsForFullAccessAndRefusesAnUnknownEventOrPoint(): void
    {
        $event = $this->published();
        $plain = self::factory()->user->create(['role' => 'subscriber']);
        $count = (int) wp_count_posts('kalaka_mail')->draft;

        foreach ([0, $plain, $this->holder($event)] as $user) {
            $this->as($user);
            self::assertContains(
                $this->rest('POST', '/events/' . $event . '/mail/points/waitlisted/template')->get_status(),
                [401, 403]
            );
        }
        $this->as($this->admin->userId);
        self::assertSame(404, $this->rest('POST', '/events/99999/mail/points/waitlisted/template')->get_status());
        self::assertSame(
            400,
            $this->rest('POST', '/events/' . $event . '/mail/points/nincs_ilyen/template')->get_status()
        );
        self::assertSame($count, (int) wp_count_posts('kalaka_mail')->draft);
    }

    public function testAnImpossibleNewTemplateLeavesNoPostBehind(): void
    {
        global $wpdb;
        $event = $this->published();
        $wpdb->update($wpdb->prefix . 'kalaka_events', ['account_mode' => 'mandatory'], ['id' => $event]);
        ObjectCache::clear();
        $first = $this->document($event, $this->paragraph('<span data-kalaka-value="account.password_link">l</span>'));
        $this->switchOn($event, 'registered', $first);
        $count = (int) wp_count_posts('kalaka_mail')->draft;

        try {
            (new MailTemplates())->createFor($event, 'registered', $this->admin);
            self::fail('Expected a refusal.');
        } catch (RuleViolation $refused) {
            self::assertSame('kalaka_point_template_unpublished', $refused->errorCode());
        }

        self::assertSame($count, (int) wp_count_posts('kalaka_mail')->draft);
        self::assertSame($first, $this->point($event, 'registered')['body_id']);
    }

    public function testThePointsReadSaysWhatEachHoldsWhyItCannotBeSwitchedOnAndWhichPointsWork(): void
    {
        $event = $this->published();
        $draft = $this->draft($event, 'Vázlat');
        $this->usesFrame($draft, false);
        $live = $this->document($event, $this->paragraph('Szia'), 'Éles');
        $this->updateMail(['event_id' => $event, 'mail' => 'waitlisted', 'body_id' => $draft]);
        $this->switchOn($event, 'confirmed', $live);
        $this->as($this->admin->userId);

        $mails = $this->rest('GET', '/events/' . $event . '/mail')->get_data()['mails'];
        $byKey = array_column($mails, null, 'mail');

        self::assertSame(
            ['no_template', null],
            [$byKey['registered']['enable_unavailable'], $byKey['registered']['template']]
        );
        self::assertSame('template_draft', $byKey['waitlisted']['enable_unavailable']);
        self::assertSame(
            ['id', 'status', 'title', 'subject', 'modified', 'uses_frame', 'edit_url'],
            array_keys($byKey['waitlisted']['template'])
        );
        self::assertSame([$draft, 'draft', 'Vázlat', 'Vázlat', false], [
            $byKey['waitlisted']['template']['id'],
            $byKey['waitlisted']['template']['status'],
            $byKey['waitlisted']['template']['title'],
            $byKey['waitlisted']['template']['subject'],
            $byKey['waitlisted']['template']['uses_frame'],
        ]);
        self::assertSame(get_edit_post_link($draft, 'raw'), $byKey['waitlisted']['template']['edit_url']);
        self::assertFalse($byKey['waitlisted']['can']['enable']);
        // A point that is on has nothing to say about switching it on.
        self::assertNull($byKey['confirmed']['enable_unavailable']);
        self::assertSame(
            ['id', 'status', 'title', 'subject', 'modified', 'uses_frame', 'edit_url'],
            array_keys($byKey['confirmed']['template'])
        );
        self::assertTrue($byKey['confirmed']['template']['uses_frame']);
        self::assertSame(
            [
                'registered' => true,
                'waitlisted' => true,
                'payment_recorded' => true,
                'confirmed' => true,
                'link_regenerated' => false,
            ],
            array_map(static fn (array $mail): bool => $mail['wired'], $byKey)
        );
        foreach (ActionMail::cases() as $case) {
            self::assertSame($byKey[$case->value]['wired'], $case->isWired());
        }
    }

    public function testTheUnassignedTemplateIsAnEmptyDraftOfTheEventAndNoPointChanges(): void
    {
        $event = $this->published();
        $live = $this->document($event, $this->paragraph('Élő'), 'Élő');
        $this->switchOn($event, 'waitlisted', $live);
        $before = [$this->point($event, 'waitlisted'), $this->point($event, 'registered')];
        $entries = count($this->entriesOf('kalaka/update-action-mail'));
        $this->as($this->admin->userId);

        $response = $this->rest('POST', '/events/' . $event . '/mail/templates');

        self::assertSame(201, $response->get_status());
        $data = $response->get_data();
        self::assertIsArray($data);
        self::assertSame(['id', 'edit_url'], array_keys($data));
        $id = (int) $data['id'];
        $post = get_post($id);
        self::assertInstanceOf(\WP_Post::class, $post);
        self::assertSame(['kalaka_mail', 'draft', ''], [$post->post_type, $post->post_status, $post->post_content]);
        self::assertSame($event, (int) get_post_meta($id, 'kalaka_event_id', true));
        self::assertStringEndsWith('post.php?post=' . $id . '&action=edit', (string) $data['edit_url']);

        // The event's own bodies list it with no point, and no point changed: nothing went through the use case.
        $bodies = array_column($this->rest('GET', '/events/' . $event . '/mail')->get_data()['bodies'], null, 'id');
        self::assertArrayHasKey($id, $bodies);
        self::assertSame(['draft', null], [$bodies[$id]['status'], $bodies[$id]['used_by']]);
        self::assertSame($before, [$this->point($event, 'waitlisted'), $this->point($event, 'registered')]);
        self::assertCount($entries, $this->entriesOf('kalaka/update-action-mail'));
    }

    public function testTheUnassignedTemplateIsForFullAccessAndRefusesAnUnknownEvent(): void
    {
        $event = $this->published();
        $plain = self::factory()->user->create(['role' => 'subscriber']);
        $drafts = (int) wp_count_posts('kalaka_mail')->draft;

        foreach ([0, $plain, $this->holder($event)] as $user) {
            $this->as($user);
            self::assertContains(
                $this->rest('POST', '/events/' . $event . '/mail/templates')->get_status(),
                [401, 403]
            );
        }
        $this->as($this->admin->userId);
        self::assertSame(404, $this->rest('POST', '/events/99999/mail/templates')->get_status());
        self::assertSame($drafts, (int) wp_count_posts('kalaka_mail')->draft);
    }
}
