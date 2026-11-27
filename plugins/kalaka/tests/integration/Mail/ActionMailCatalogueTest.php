<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Mail;

use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\PermissionDenied;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Mail\Api\ActionMail;
use Kalaka\Mail\Api\ActionMailSettings;
use Kalaka\Mail\Catalogue\SetEventMailLayout;
use Kalaka\Mail\Catalogue\MailBody;
use Kalaka\Mail\Catalogue\UpdateActionMail;

/** The per-event action-mail catalogue and the event's layout. mail.md, "Configuration". */
final class ActionMailCatalogueTest extends MailBodyTestCase
{
    public function testEveryActionIsListedInOrderOffWithoutABodyAndWrapped(): void
    {
        $event = $this->create();

        $settings = (new ActionMailSettings())->ofEvent($event)->toArray();

        self::assertSame($event, $settings['event_id']);
        self::assertSame('{{content}}', $settings['layout_html']);
        self::assertSame(
            ['registered', 'waitlisted', 'payment_recorded', 'confirmed', 'link_regenerated'],
            array_column($settings['mails'], 'mail')
        );
        self::assertSame('Jelentkezés rögzítve', $settings['mails'][0]['label']);
        foreach ($settings['mails'] as $mail) {
            self::assertFalse($mail['enabled']);
            self::assertNull($mail['body_id']);
        }
    }

    public function testAMailIsSwitchedOnWithItsBodyAndAudited(): void
    {
        $event = $this->create();
        $body = $this->document($event, $this->paragraph('Szia'));

        $settings = $this->switchOn($event, 'waitlisted', $body);

        self::assertSame(
            [
                'mail' => 'waitlisted',
                'label' => 'Jelentkezés várólistára helyezve',
                'enabled' => true,
                'body_id' => $body,
            ],
            $settings['mails'][1]
        );
        $entries = $this->entriesOf('kalaka/update-action-mail');
        self::assertCount(1, $entries);
        self::assertSame('succeeded', $entries[0]['outcome']);
    }

    public function testAMailIsNotSwitchedOnWithoutABody(): void
    {
        $event = $this->create();

        try {
            $this->updateMail(['event_id' => $event, 'mail' => 'registered', 'enabled' => true]);
            self::fail('Expected a refusal.');
        } catch (RuleViolation $e) {
            self::assertSame('A művelet nem hajtható végre. Előbb add meg a sablon szövegét.', $e->getMessage());
        }
        self::assertFalse((new ActionMailSettings())->ofEvent($event)->toArray()['mails'][0]['enabled']);
    }

    public function testUnassigningTheBodyOfAMailThatIsOnSwitchesItOff(): void
    {
        $event = $this->create();
        $this->switchOn($event, 'registered', $this->document($event, $this->paragraph('Szia')));

        $settings = $this->updateMail(['event_id' => $event, 'mail' => 'registered', 'body_id' => null]);

        self::assertSame([false, null], [$settings['mails'][0]['enabled'], $settings['mails'][0]['body_id']]);
    }

    public function testADraftBodyIsAssignedAndOnlyAPublishedOneSwitchesTheMailOn(): void
    {
        $event = $this->create();
        $draft = $this->document($event, $this->paragraph('Szia'));
        wp_update_post(['ID' => $draft, 'post_status' => 'draft']);

        // A point that is off may hold a draft: the assignment is not refused, it switches the point off.
        $settings = $this->updateMail(['event_id' => $event, 'mail' => 'registered', 'body_id' => $draft]);
        self::assertSame([false, $draft], [$settings['mails'][0]['enabled'], $settings['mails'][0]['body_id']]);

        // Only a published body switches it on.
        try {
            $this->updateMail(['event_id' => $event, 'mail' => 'registered', 'enabled' => true]);
            self::fail('Expected a refusal.');
        } catch (RuleViolation $e) {
            self::assertSame('kalaka_point_template_unpublished', $e->errorCode());
            self::assertSame(
                'A művelet nem hajtható végre. A küldési pont csak közzétett sablonnal kapcsolható be.',
                $e->getMessage()
            );
        }
        self::assertSame([false, $draft], [
            (new ActionMailSettings())->ofEvent($event)->toArray()['mails'][0]['enabled'],
            (new ActionMailSettings())->ofEvent($event)->toArray()['mails'][0]['body_id'],
        ]);

        // A mail switched off keeps a body that is then unpublished, and is switched on again only once the
        // body is published again.
        $body = $this->document($event, $this->paragraph('Szia'));
        $this->switchOn($event, 'registered', $body);
        $this->updateMail(['event_id' => $event, 'mail' => 'registered', 'enabled' => false]);
        wp_update_post(['ID' => $body, 'post_status' => 'draft']);
        $this->expectException(RuleViolation::class);
        $this->updateMail(['event_id' => $event, 'mail' => 'registered', 'enabled' => true]);
    }

    public function testASaveThatChangesNothingOfAMailThatIsOnPassesWhateverItsBodysStatus(): void
    {
        global $wpdb;
        $event = $this->create();
        $body = $this->document($event, $this->paragraph('Szia'));
        $this->switchOn($event, 'registered', $body);
        // Written around BodyInUse, straight in the table.
        $wpdb->update($wpdb->posts, ['post_status' => 'draft'], ['ID' => $body]);
        clean_post_cache($body);

        $this->updateMail(['event_id' => $event, 'mail' => 'registered', 'enabled' => true]);

        $mail = (new ActionMailSettings())->ofEvent($event)->toArray()['mails'][0];
        self::assertTrue($mail['enabled']);
    }

    public function testWithoutThePreReadBodyAnAssignmentOrASwitchOnIsRefused(): void
    {
        $event = $this->create();
        $body = $this->document($event, $this->paragraph('Szia'));
        $this->switchOn($event, 'registered', $body);
        $this->updateMail(['event_id' => $event, 'mail' => 'registered', 'enabled' => false]);

        // A run of the use case alone, as a generic dispatch makes it, cannot read the body in its transaction.
        $changes = [['body_id' => $body], ['enabled' => true], ['body_id' => $body, 'enabled' => true]];
        foreach ($changes as $change) {
            try {
                $this->exec(new UpdateActionMail(), ['event_id' => $event, 'mail' => 'registered'] + $change);
                self::fail('Expected a refusal.');
            } catch (SystemFailure $e) {
                self::assertSame('Váratlan hiba történt. Próbáld újra később.', $e->getMessage());
                self::assertInstanceOf(\LogicException::class, $e->getPrevious());
            }
        }
        // A body of another post's facts does not count either.
        try {
            $this->exec(
                new UpdateActionMail(new MailBody($body + 1, $event, true)),
                ['event_id' => $event, 'mail' => 'registered', 'enabled' => true]
            );
            self::fail('Expected a refusal.');
        } catch (SystemFailure) {
            self::assertFalse((new ActionMailSettings())->ofEvent($event)->toArray()['mails'][0]['enabled']);
        }

        // What needs no body facts passes.
        $this->exec(new UpdateActionMail(), ['event_id' => $event, 'mail' => 'registered', 'enabled' => false]);
        $this->exec(new UpdateActionMail(), ['event_id' => $event, 'mail' => 'registered', 'body_id' => null]);
        self::assertNull((new ActionMailSettings())->ofEvent($event)->toArray()['mails'][0]['body_id']);
    }

    public function testAStoredRowWithAnUnknownKeyIsSkippedAndLogged(): void
    {
        global $wpdb;
        $event = $this->create();
        $this->switchOn($event, 'registered', $this->document($event, $this->paragraph('Szia')));
        $wpdb->insert($wpdb->prefix . 'kalaka_action_mails', [
            'event_id' => $event,
            'mail' => 'checked_in',
            'version' => 1,
            'enabled' => 1,
            'body_id' => null,
        ]);

        $log = $this->capturedErrorLog(function () use ($event): void {
            $mails = (new ActionMailSettings())->ofEvent($event)->toArray()['mails'];
            self::assertSame(
                array_column(ActionMail::cases(), 'value'),
                array_column($mails, 'mail')
            );
            self::assertTrue($mails[0]['enabled']);
        });

        self::assertStringContainsString('A stored action mail with an unknown key: checked_in', $log);
    }

    public function testABodyServesOneMailOnly(): void
    {
        $event = $this->create();
        $body = $this->document($event, $this->paragraph('Szia'));
        $this->switchOn($event, 'registered', $body);
        $this->updateMail(['event_id' => $event, 'mail' => 'waitlisted', 'enabled' => false]);

        try {
            $this->updateMail(['event_id' => $event, 'mail' => 'waitlisted', 'body_id' => $body]);
            self::fail('Expected a refusal.');
        } catch (RuleViolation $e) {
            self::assertSame(
                'A művelet nem hajtható végre. Ezt a levélsablont már egy másik levél használja.',
                $e->getMessage()
            );
        }
        self::assertNull((new ActionMailSettings())->ofEvent($event)->toArray()['mails'][1]['body_id']);

        // The same mail may be given its own body again.
        $this->updateMail(['event_id' => $event, 'mail' => 'registered', 'body_id' => $body]);
    }

    public function testABodyOfAnotherEventOrAnotherPostTypeIsRefused(): void
    {
        $event = $this->create();
        $other = $this->create('Másik');
        $page = self::factory()->post->create(['post_type' => 'page']);

        foreach ([$this->document($other, $this->paragraph('Szia')), $page] as $body) {
            try {
                $this->updateMail(['event_id' => $event, 'mail' => 'registered', 'body_id' => $body]);
                self::fail('Expected a refusal.');
            } catch (InvalidInput) {
                self::assertNull((new ActionMailSettings())->ofEvent($event)->toArray()['mails'][0]['body_id']);
            }
        }
    }

    public function testAnUnknownMailOrEventIsRefused(): void
    {
        $event = $this->create();
        try {
            $this->updateMail(['event_id' => $event, 'mail' => 'checked_in', 'enabled' => false]);
            self::fail('Expected a refusal.');
        } catch (InvalidInput) {
        }

        $this->expectException(NotFound::class);
        $this->updateMail(['event_id' => $event + 100, 'mail' => 'registered', 'enabled' => false]);
    }

    public function testOnlyFullAccessChangesTheSettings(): void
    {
        $event = $this->create();
        $user = self::factory()->user->create(['role' => 'editor']);

        $this->expectException(PermissionDenied::class);
        $this->updateMail(['event_id' => $event, 'mail' => 'registered', 'enabled' => false], new UserActor($user));
    }

    public function testALayoutWithOneContentPlaceholderIsStoredAsWritten(): void
    {
        $event = $this->create();
        $html = '<html><body style="color:#333"><div class="keret">{{content}}</div><script>x()</script></body></html>';

        $this->setLayout($event, $html);
        self::assertSame($html, (new ActionMailSettings())->ofEvent($event)->layoutHtml);

        $this->setLayout($event, '{{content}}');
        self::assertSame('{{content}}', (new ActionMailSettings())->ofEvent($event)->layoutHtml);
        self::assertCount(2, $this->entriesOf('kalaka/set-event-mail-layout'));
    }

    public function testALayoutWithoutExactlyOnePlaceholderIsRefused(): void
    {
        $event = $this->create();

        foreach (['', '<p>Nincs helye</p>', '{{content}}<hr>{{content}}', '{{ content }}'] as $html) {
            try {
                $this->exec(new SetEventMailLayout(), ['event_id' => $event, 'html' => $html]);
                self::fail('Expected a refusal.');
            } catch (RuleViolation $e) {
                self::assertSame(
                    'A művelet nem hajtható végre. ' . SetEventMailLayout::NO_PLACEHOLDER,
                    $e->getMessage()
                );
            }
        }
        self::assertSame('{{content}}', (new ActionMailSettings())->ofEvent($event)->layoutHtml);
    }

    public function testALayoutWithAnotherPlaceholderIsRefused(): void
    {
        $event = $this->create();

        foreach (['{{content}} {{name}}', '<p>{{ nev }}</p>{{content}}', "{{content}}{{\n}}"] as $html) {
            try {
                $this->exec(new SetEventMailLayout(), ['event_id' => $event, 'html' => $html]);
                self::fail('Expected a refusal.');
            } catch (RuleViolation $e) {
                self::assertSame(
                    'A művelet nem hajtható végre. A levélkeretben a {{content}} jelölőn kívül más jelölő nem'
                        . ' szerepelhet.',
                    $e->getMessage()
                );
            }
        }
        self::assertSame('{{content}}', (new ActionMailSettings())->ofEvent($event)->layoutHtml);

        // Braces that are not a placeholder are kept as written.
        $html = '<style>a{color:red}</style>{{content}}';
        $this->exec(new SetEventMailLayout(), ['event_id' => $event, 'html' => $html]);
        self::assertSame($html, (new ActionMailSettings())->ofEvent($event)->layoutHtml);
    }

    public function testOnlyFullAccessSetsTheLayout(): void
    {
        $event = $this->create();
        $user = self::factory()->user->create(['role' => 'administrator']);

        $this->expectException(PermissionDenied::class);
        $this->exec(
            new SetEventMailLayout(),
            ['event_id' => $event, 'html' => '{{content}}'],
            new UserActor($user)
        );
    }
}
