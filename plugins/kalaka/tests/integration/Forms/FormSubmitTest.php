<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Forms;

use Kalaka\Events\PublishEvent;
use Kalaka\Forms\Api\InvalidAnswers;
use Kalaka\Forms\FormPage;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Registrations\Api\RegistrationSubmit;
use Kalaka\Registrations\Api\SubmitToken;

/** The form page's submit body (the S12 route stub) on the submit seam, and the page's submit token. */
final class FormSubmitTest extends FormsTestCase
{
    private const ANSWERS = ['name' => 'Teszt Elek', 'email' => 'teszt@example.org'];

    private function page(): FormPage
    {
        return new FormPage(submit: new RegistrationSubmit($this->runner));
    }

    /** The after-submit block: a thank-you with the reference code, and a line for the waitlist. */
    private function afterSubmit(): string
    {
        $waitlisted = ['match' => 'all', 'items' => [
            ['key' => 'registration.status', 'operator' => 'equals', 'value' => 'waitlisted'],
        ]];

        return '<!-- wp:kalaka/after-submit --><!-- wp:paragraph --><p>Köszönjük! Kódod: '
            . '<span data-kalaka-value="registration.code">kód</span></p><!-- /wp:paragraph -->'
            . '<!-- wp:kalaka/if ' . wp_json_encode(['condition' => $waitlisted]) . ' -->'
            . '<!-- wp:paragraph --><p>Most várólistára kerültél.</p><!-- /wp:paragraph -->'
            . '<!-- /wp:kalaka/if --><!-- /wp:kalaka/after-submit -->';
    }

    private function openForm(string $more = '', int $capacity = 10): int
    {
        // Open both for the test runner's clock (the submit) and for the site's (the page).
        $times = [$this->clock->now(), current_datetime()];
        $day = static fn (\DateTimeImmutable $at, int $offset): string => $at->setTimezone(wp_timezone())
            ->setTime(0, 0)->modify(sprintf('%+d days', $offset))->format('Y-m-d H:i');
        $event = $this->create();
        $this->update($event, [
            'capacity' => $capacity,
            'registration_opens_at' => $day(min($times), -1),
            'registration_closes_at' => $day(max($times), 10),
            'starts_at' => $day(max($times), 30),
            'ends_at' => $day(max($times), 36),
        ]);
        $this->transition(new PublishEvent(), $event);

        return $this->insertForm($this->slotFields() . $more, ['kalaka_event_id' => $event]);
    }

    private function registrations(int $event): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test inspection; table prefix and constants only
            "SELECT COUNT(*) FROM {$wpdb->prefix}kalaka_registrations WHERE event_id = %d",
            $event
        ));
    }

    /** @return array<string, mixed> */
    private function body(int $id, array $answers = self::ANSWERS, ?string $token = null): array
    {
        return [
            'version' => $this->readForm($id)->version,
            'answers' => $answers,
            'token' => $token ?? SubmitToken::generate(),
        ];
    }

    public function testASubmitCreatesARegistrationAndAnswersWithTheSeamsResultAndItsDefaultText(): void
    {
        $id = $this->openForm();
        $event = $this->readForm($id)->eventId;

        $answer = $this->page()->submit($id, $this->body($id));

        self::assertSame($event, $answer['event']);
        self::assertSame('registered', $answer['status']);
        self::assertFalse($answer['repeated']);
        self::assertMatchesRegularExpression('/\A[0-9A-Z]{4}\z/', $answer['code']);
        // Without an after-submit section, the status's default text (Backend's `RegistrationSubmit`).
        self::assertStringContainsString(RegistrationSubmit::DEFAULT_REGISTERED, $answer['after_submit']);
        self::assertArrayNotHasKey('html', $answer);
        self::assertSame(1, $this->registrations($event));
    }

    public function testTheSameTokenAnswersRepeatedWithTheFirstRegistrationsAfterSubmitContentAndNothingElse(): void
    {
        $id = $this->openForm($this->afterSubmit());
        $body = $this->body($id);

        $first = $this->page()->submit($id, $body);
        $second = $this->page()->submit($id, $body);

        self::assertSame(['repeated', 'after_submit'], array_keys($second));
        self::assertTrue($second['repeated']);
        self::assertSame($first['after_submit'], $second['after_submit']);
        self::assertStringContainsString('Kódod: ' . $first['code'], wp_strip_all_tags($second['after_submit']));
        self::assertSame(1, $this->registrations($this->readForm($id)->eventId));
    }

    public function testTheAfterSubmitBlockRendersWithTheCodeAndAWaitlistLineOnlyOnTheWaitlist(): void
    {
        $id = $this->openForm($this->afterSubmit());

        $answer = $this->page()->submit($id, $this->body($id));

        self::assertStringContainsString('Köszönjük!', $answer['after_submit']);
        self::assertStringContainsString('Kódod: ' . $answer['code'], wp_strip_all_tags($answer['after_submit']));
        self::assertStringNotContainsString('várólistára', $answer['after_submit']);
        self::assertStringNotContainsString('data-kalaka-value', $answer['after_submit']);

        $full = $this->openForm($this->afterSubmit(), 1);
        $this->page()->submit($full, $this->body($full));
        $waitlisted = $this->page()->submit($full, $this->body($full));
        self::assertSame('waitlisted', $waitlisted['status']);
        self::assertStringContainsString('várólistára', $waitlisted['after_submit']);
    }

    public function testTheAfterSubmitBlockNeverRendersWithTheForm(): void
    {
        $id = $this->openForm($this->afterSubmit());

        $html = render_block([
            'blockName' => 'kalaka/form',
            'attrs' => ['formId' => $id],
            'innerBlocks' => [],
            'innerHTML' => '',
            'innerContent' => [],
        ]);

        self::assertStringContainsString('<form', $html);
        self::assertStringNotContainsString('Köszönjük!', $html);
        self::assertStringNotContainsString('Kódod', $html);
    }

    public function testAnOlderVersionIsRefusedAsOutdated(): void
    {
        $id = $this->openForm();
        $body = $this->body($id);
        wp_update_post(['ID' => $id, 'post_content' => $this->slotFields() . $this->field('text', 'note')]);

        $this->expectException(\Kalaka\Forms\Api\FormOutdated::class);

        $this->page()->submit($id, $body);
    }

    public function testAMissingRequiredAnswerIsRefusedPerField(): void
    {
        $id = $this->openForm();

        try {
            $this->page()->submit($id, $this->body($id, ['name' => 'Teszt Elek']));
            self::fail('The submit was accepted.');
        } catch (InvalidAnswers $e) {
            self::assertArrayHasKey('email', $e->fields);
        }
    }

    public function testABodyWithoutAnswersOrToken(): void
    {
        $this->expectException(InvalidInput::class);

        $this->page()->submit($this->openForm(), ['answers' => self::ANSWERS]);
    }

    public function testABodyWithoutTheVersion(): void
    {
        $id = $this->openForm();
        $body = $this->body($id);
        unset($body['version']);

        $this->expectException(InvalidInput::class);

        $this->page()->submit($id, $body);
    }

    public function testThePageCarriesAFreshSubmitTokenInAHiddenField(): void
    {
        $id = $this->openForm();
        $render = static fn (): string => render_block([
            'blockName' => 'kalaka/form',
            'attrs' => ['formId' => $id],
            'innerBlocks' => [],
            'innerHTML' => '',
            'innerContent' => [],
        ]);

        preg_match('/<input type="hidden" data-kalaka-token value="([^"]*)">/', $render(), $first);
        preg_match('/<input type="hidden" data-kalaka-token value="([^"]*)">/', $render(), $second);

        self::assertTrue(SubmitToken::isValid($first[1] ?? ''));
        self::assertTrue(SubmitToken::isValid($second[1] ?? ''));
        self::assertNotSame($first[1], $second[1]);
    }
}
