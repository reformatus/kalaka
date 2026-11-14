<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Forms;

use Kalaka\Events\PublishEvent;
use Kalaka\Forms\FormPosts;
use Kalaka\Forms\Rest\FormRoutes;
use Kalaka\Foundation\Api\Audit\Correlation;
use Kalaka\Foundation\Api\LoadFailure;
use Kalaka\Foundation\Api\LoadState;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Registrations\Api\RegistrationSubmit;
use Kalaka\Registrations\Api\SubmitToken;

/**
 * S12: the public routes of a form's page, through `rest_do_request()`: the availability re-fetch and the
 * submit, their refusals and their `Cache-Control`.
 */
final class FormRoutesTest extends FormsTestCase
{
    private const ANSWERS = ['name' => 'Teszt Elek', 'email' => 'teszt@example.org'];

    private const WAITLISTED_TEXT = '<p>' . RegistrationSubmit::DEFAULT_WAITLISTED . '</p>';

    private const REGISTERED_TEXT = '<p>' . RegistrationSubmit::DEFAULT_REGISTERED . '</p>';

    private Runner $previous;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function set_up(): void
    {
        parent::set_up();
        // The routes use the published runner: the test's, with its clock.
        $this->previous = Runner::current();
        Runner::publish($this->runner);
        $GLOBALS['wp_rest_server'] = null;
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function tear_down(): void
    {
        Runner::publish($this->previous);
        $GLOBALS['wp_rest_server'] = null;
        wp_set_current_user(0);
        parent::tear_down();
    }

    /** @return array<string, mixed> */
    private function when(string $key, string $value): array
    {
        return ['match' => 'all', 'items' => [['key' => $key, 'operator' => 'equals', 'value' => $value]]];
    }

    /** @param array<string, mixed> $attributes */
    private function page(string $inner, array $attributes = []): string
    {
        return '<!-- wp:kalaka/form-page' . ($attributes === [] ? '' : ' ' . wp_json_encode($attributes)) . ' -->'
            . $inner . '<!-- /wp:kalaka/form-page -->';
    }

    /** A choice of track, and a choice of module whose PRO option shows only for the PRO track. */
    private function tracks(): string
    {
        return $this->field('choice', 'track', ['options' => [
            ['value' => 'ifi', 'label' => 'Ifi'],
            ['value' => 'pro', 'label' => 'PRO'],
        ]]) . $this->field('choice', 'module', ['options' => [
            ['value' => 'ifi_a', 'label' => 'Ifi A'],
            ['value' => 'pro_a', 'label' => 'PRO A', 'condition' => $this->when('track', 'pro')],
        ]]);
    }

    /** A choice of fee, and a choice of modules whose PRO options show only for the fee `extra`. */
    private function feeAndModules(): string
    {
        $extra = $this->when('fee', 'extra');

        return $this->field('choice', 'fee', ['options' => [
            ['value' => 'basic', 'label' => 'Alap'],
            ['value' => 'extra', 'label' => 'Támogatott'],
        ]]) . '<!-- wp:kalaka/if ' . wp_json_encode(['condition' => $extra]) . ' -->'
            . $this->field('text', 'supporter') . '<!-- /wp:kalaka/if -->'
            . $this->field('choice', 'modules', ['multiple' => true, 'options' => [
                ['value' => 'ifi_a', 'label' => 'IFI A'],
                ['value' => 'pro_a', 'label' => 'PRO A', 'condition' => $extra],
                ['value' => 'pro_b', 'label' => 'PRO B', 'condition' => $extra],
            ]]);
    }

    /** The after-submit section: a thank-you with the reference code, and a line for the waitlist. */
    private function afterSubmit(): string
    {
        return '<!-- wp:kalaka/after-submit --><!-- wp:paragraph --><p>Köszönjük! Kódod: '
            . '<span data-kalaka-value="registration.code">kód</span></p><!-- /wp:paragraph -->'
            . '<!-- wp:kalaka/if ' . wp_json_encode(['condition' => $this->when('registration.status', 'waitlisted')])
            . ' --><!-- wp:paragraph --><p>Most várólistára kerültél.</p><!-- /wp:paragraph -->'
            . '<!-- /wp:kalaka/if --><!-- /wp:kalaka/after-submit -->';
    }

    /**
     * Two pages, then a terminal page for the young: page 0 the basics and the mode, page 1 a required
     * room for camp, page 2 terminal for young.
     */
    private function pages(): string
    {
        return $this->page($this->slotFields() . $this->field('choice', 'mode', ['required' => true, 'options' => [
            ['value' => 'camp', 'label' => 'Tábor'],
            ['value' => 'young', 'label' => 'Fiatal'],
        ]]))
            . $this->page($this->field('text', 'room', ['required' => true]), [
                'condition' => $this->when('mode', 'camp'),
            ])
            . $this->page('<!-- wp:paragraph --><p>Csak 14 éves kortól.</p><!-- /wp:paragraph -->', [
                'terminal' => true,
                'condition' => $this->when('mode', 'young'),
            ]);
    }

    /**
     * A published event with the capacity, and a registration-slot form of it with the content.
     *
     * @param array<string, mixed> $meta
     */
    private function openForm(string $content = '', int $capacity = 10, array $meta = []): int
    {
        $event = $this->create();
        $this->update($event, ['capacity' => $capacity]);
        $this->transition(new PublishEvent(), $event);

        return $this->insertForm(
            $content === '' ? $this->slotFields() : $content,
            $meta + ['kalaka_event_id' => $event]
        );
    }

    /** @param array<array-key, mixed>|string $body */
    private function post(int $form, string $what, array|string $body): \WP_REST_Response
    {
        $request = new \WP_REST_Request('POST', '/' . FormRoutes::NAMESPACE . '/forms/' . $form . '/' . $what);
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(is_string($body) ? $body : (string) wp_json_encode($body));

        return rest_do_request($request);
    }

    /**
     * @param array<string, mixed> $answers
     * @return array<string, mixed>
     */
    private function submitBody(int $form, array $answers = self::ANSWERS, ?string $token = null): array
    {
        return [
            'version' => $this->readForm($form)->version,
            'answers' => $answers,
            'token' => $token ?? SubmitToken::generate(),
        ];
    }

    /**
     * @param array<string, mixed> $answers
     * @return array<string, mixed>
     */
    private function availabilityBody(int $form, array $answers = [], ?int $page = null): array
    {
        $body = ['version' => $this->readForm($form)->version, 'answers' => $answers];
        if ($page !== null) {
            $body['page'] = $page;
        }

        return $body;
    }

    /** @return array<string, mixed> */
    private function data(\WP_REST_Response $response): array
    {
        $data = $response->get_data();
        self::assertIsArray($data);

        return $data;
    }

    private function assertRefused(\WP_REST_Response $response, int $status, string $code): void
    {
        self::assertSame($status, $response->get_status());
        self::assertSame($code, $this->data($response)['code']);
        self::assertSame(FormRoutes::CACHE_CONTROL, $response->get_headers()['Cache-Control'] ?? null);
    }

    private function registrations(int $form): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test inspection; table prefix and constants only
            "SELECT COUNT(*) FROM {$wpdb->prefix}kalaka_registrations WHERE event_id = %d",
            $this->readForm($form)->eventId
        ));
    }

    public function testTheAvailabilityAnswersTheReadModelAndTheChoiceFieldsReRenderedForTheAnswers(): void
    {
        $form = $this->openForm($this->slotFields() . $this->tracks());

        $response = $this->post($form, 'availability', $this->availabilityBody($form, ['track' => 'ifi']));

        self::assertSame(200, $response->get_status());
        $data = $this->data($response);
        self::assertEquals(
            [
                'fields' => ['name' => true, 'email' => true, 'track' => true, 'module' => true],
                'chains' => [],
                'options' => ['module' => ['ifi_a']],
                'triggers' => ['track'],
                'withheld' => [],
                'pages' => [],
                'errors' => new \stdClass(),
            ],
            $data['availability']
        );
        self::assertSame(['module'], array_keys((array) $data['parts']));
        $part = (string) ((array) $data['parts'])['module'];
        self::assertStringContainsString('data-kalaka-field="module"', $part);
        self::assertStringContainsString('value="ifi_a"', $part);
        self::assertStringNotContainsString('value="pro_a"', $part);

        $pro = $this->data($this->post($form, 'availability', $this->availabilityBody($form, ['track' => 'pro'])));
        self::assertSame(['ifi_a', 'pro_a'], $pro['availability']['options']['module']);
        self::assertStringContainsString('value="pro_a"', (string) ((array) $pro['parts'])['module']);
    }

    public function testTheChoiceFieldPartIsTheFieldsBlockWithItsErrorElementAndABranchShowsForItsTrigger(): void
    {
        $form = $this->openForm($this->slotFields() . $this->feeAndModules());

        $extra = $this->data($this->post(
            $form,
            'availability',
            $this->availabilityBody($form, ['fee' => 'extra', 'supporter' => 'x'])
        ));

        self::assertSame(['fee'], $extra['availability']['triggers']);
        self::assertSame([0], $extra['availability']['chains']);
        self::assertTrue($extra['availability']['fields']['supporter']);
        self::assertSame(['modules' => ['ifi_a', 'pro_a', 'pro_b']], $extra['availability']['options']);
        self::assertSame(['modules'], array_keys((array) $extra['parts']));
        $part = (string) ((array) $extra['parts'])['modules'];
        self::assertStringStartsWith('<div class="kalaka-field wp-block-kalaka-choice-field"', trim($part));
        self::assertStringContainsString('data-kalaka-field="modules"', $part);
        self::assertStringContainsString('value="pro_b"', $part);
        self::assertStringContainsString(
            '<span id="kalaka-field-modules-error" class="kalaka-field__error" hidden></span>',
            $part
        );

        $basic = $this->data($this->post($form, 'availability', $this->availabilityBody($form, ['fee' => 'basic'])));
        self::assertSame([null], $basic['availability']['chains']);
        self::assertFalse($basic['availability']['fields']['supporter']);
        self::assertStringNotContainsString('value="pro_a"', (string) ((array) $basic['parts'])['modules']);
    }

    public function testAFormWithoutOptionConditionsHasNoPartsAndTheJsonHasObjects(): void
    {
        $form = $this->openForm();

        $response = $this->post($form, 'availability', $this->availabilityBody($form));

        self::assertSame(200, $response->get_status());
        self::assertSame(
            '{"availability":{"fields":{"name":true,"email":true},"chains":[],"options":[],"triggers":[],'
            . '"withheld":[],"pages":[],"errors":{}},"parts":{}}',
            wp_json_encode($response->get_data())
        );
    }

    public function testTheAvailabilityWritesNothing(): void
    {
        $form = $this->openForm($this->slotFields() . $this->tracks());
        $entries = count($this->entriesOf('kalaka-portal/submit-registration'));

        $statements = $this->statementsDuring(function () use ($form): void {
            $this->post($form, 'availability', $this->availabilityBody($form, self::ANSWERS + ['track' => 'pro']));
        });

        $writes = array_filter(
            $statements,
            static fn (string $query): bool => preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i', $query) === 1
                && str_contains($query, 'kalaka_')
        );
        self::assertSame([], array_values($writes));
        self::assertSame(0, $this->registrations($form));
        self::assertCount($entries, $this->entriesOf('kalaka-portal/submit-registration'));
    }

    public function testAPageAsksForThatPagesErrorsOnly(): void
    {
        $form = $this->openForm($this->pages());

        $first = $this->data($this->post($form, 'availability', $this->availabilityBody($form, [], 0)));
        self::assertSame([true, false, false], $first['availability']['pages']);
        self::assertSame(['name', 'email', 'mode'], array_keys((array) $first['availability']['errors']));

        $camp = self::ANSWERS + ['mode' => 'camp'];
        $basicsDone = $this->data($this->post($form, 'availability', $this->availabilityBody($form, $camp, 0)));
        self::assertSame([true, true, false], $basicsDone['availability']['pages']);
        self::assertEquals(new \stdClass(), $basicsDone['availability']['errors']);

        $room = $this->data($this->post($form, 'availability', $this->availabilityBody($form, $camp, 1)));
        self::assertSame(['room'], array_keys((array) $room['availability']['errors']));

        // Without a page, no error shows while someone is typing.
        $none = $this->data($this->post($form, 'availability', $this->availabilityBody($form, $camp)));
        self::assertEquals(new \stdClass(), $none['availability']['errors']);
    }

    public function testAPageOutOfRangeOrOfTheWrongTypeIsInvalidInput(): void
    {
        $paged = $this->openForm($this->pages());
        $plain = $this->openForm();

        $outOfRange = $this->post($paged, 'availability', $this->availabilityBody($paged, [], 3));
        $this->assertRefused($outOfRange, 400, 'kalaka_invalid_input');
        $noPages = $this->post($plain, 'availability', $this->availabilityBody($plain, [], 0));
        $this->assertRefused($noPages, 400, 'kalaka_invalid_input');
        $this->assertRefused(
            $this->post($paged, 'availability', ['page' => '0'] + $this->availabilityBody($paged)),
            400,
            'kalaka_invalid_input'
        );
    }

    public function testAMalformedBodyIsInvalidInputOnBothRoutes(): void
    {
        $form = $this->openForm();
        $version = $this->readForm($form)->version;

        foreach (
            [
                ['availability', '7'],
                ['availability', ['answers' => []]],
                ['availability', ['version' => (string) $version, 'answers' => []]],
                ['availability', ['version' => $version, 'answers' => 'x']],
                ['availability', ['version' => $version, 'answers' => ['undeclared' => 'x']]],
                ['submit', ['version' => $version, 'answers' => self::ANSWERS]],
                ['submit', ['version' => $version, 'answers' => self::ANSWERS, 'token' => 'short']],
                ['submit', ['version' => $version, 'token' => SubmitToken::generate()]],
                ['submit', ['answers' => self::ANSWERS, 'token' => SubmitToken::generate()]],
            ] as [$what, $body]
        ) {
            $this->assertRefused($this->post($form, $what, $body), 400, 'kalaka_invalid_input');
        }
        self::assertSame(0, $this->registrations($form));
    }

    public function testAnOutdatedVersionIsRefusedOnBothRoutes(): void
    {
        $form = $this->openForm();
        $old = $this->readForm($form)->version;
        wp_update_post(['ID' => $form, 'post_content' => $this->slotFields() . $this->field('text', 'more')]);
        self::assertNotSame($old, $this->readForm($form)->version);

        $availability = $this->post($form, 'availability', ['version' => $old, 'answers' => []]);
        $this->assertRefused($availability, 409, 'kalaka_form_outdated');
        self::assertSame(
            'Az űrlap időközben megváltozott. Töltsd újra az oldalt, és próbáld újra.',
            $this->data($availability)['message']
        );
        $submit = ['version' => $old, 'answers' => self::ANSWERS, 'token' => SubmitToken::generate()];
        $this->assertRefused($this->post($form, 'submit', $submit), 409, 'kalaka_form_outdated');
        self::assertSame(0, $this->registrations($form));
    }

    public function testAnUnknownFormIsNotFoundOnBothRoutes(): void
    {
        $post = self::factory()->post->create();
        $body = ['version' => 1, 'answers' => self::ANSWERS, 'token' => SubmitToken::generate()];

        foreach ([999999, $post] as $id) {
            $this->assertRefused($this->post($id, 'availability', $body), 404, 'kalaka_not_found');
            $this->assertRefused($this->post($id, 'submit', $body), 404, 'kalaka_not_found');
        }
    }

    public function testAClosedFormIsRefusedWithItsCodesOnBothRoutes(): void
    {
        $form = $this->openForm();
        $now = $this->runner->clock()->now()->setTimezone(wp_timezone());
        $at = static fn (int $days): string => $now->setTime(0, 0)
            ->modify(sprintf('%+d days', $days))->format('Y-m-d H:i');
        $this->update($this->readForm($form)->eventId, [
            'registration_opens_at' => $at(-10),
            'registration_closes_at' => $at(-1),
            'starts_at' => $at(30),
            'ends_at' => $at(36),
        ]);

        $bodies = ['availability' => $this->availabilityBody($form), 'submit' => $this->submitBody($form)];
        foreach ($bodies as $what => $body) {
            $response = $this->post($form, $what, $body);
            $this->assertRefused($response, 403, 'kalaka_form_refused');
            self::assertSame('Ez az űrlap most nem küldhető be.', $this->data($response)['message']);
            self::assertSame(['outside_registration_window'], $this->data($response)['data']['refusals']);
        }
        self::assertSame(0, $this->registrations($form));
    }

    public function testARoleGatedFormIsRefusedToAGuestAndOpenToAUserWithTheRole(): void
    {
        $form = $this->openForm('', 10, [FormPosts::META_REQUIRED_ROLE => 'editor']);

        $guestAvailability = $this->post($form, 'availability', $this->availabilityBody($form));
        $this->assertRefused($guestAvailability, 403, 'kalaka_form_refused');
        self::assertSame(['role_required'], $this->data($guestAvailability)['data']['refusals']);
        $guest = $this->post($form, 'submit', $this->submitBody($form));
        $this->assertRefused($guest, 403, 'kalaka_form_refused');
        self::assertSame(['role_required'], $this->data($guest)['data']['refusals']);

        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        self::assertSame(200, $this->post($form, 'availability', $this->availabilityBody($form))->get_status());
        $response = $this->post($form, 'submit', $this->submitBody($form));
        self::assertSame(200, $response->get_status());
        self::assertSame('registered', $this->data($response)['status']);
        self::assertSame(1, $this->registrations($form));
        self::assertSame('account', $this->entriesOf('kalaka-portal/submit-registration')[0]['actor_type']);
    }

    public function testASubmitRegistersThenWaitlistsWhenTheEventIsFull(): void
    {
        $form = $this->openForm('', 1);
        $event = $this->readForm($form)->eventId;

        $first = $this->post($form, 'submit', $this->submitBody($form));
        self::assertSame(200, $first->get_status());
        self::assertSame(FormRoutes::CACHE_CONTROL, $first->get_headers()['Cache-Control'] ?? null);
        $data = $this->data($first);
        self::assertSame($event, $data['event']);
        self::assertSame('registered', $data['status']);
        self::assertFalse($data['repeated']);
        self::assertSame(self::REGISTERED_TEXT, $data['after_submit']);
        self::assertMatchesRegularExpression('/\A[0-9A-Z]{4}\z/', $data['code']);
        self::assertArrayNotHasKey('html', $data);

        $second = $this->data($this->post($form, 'submit', $this->submitBody($form, [
            'name' => 'Második Elek',
            'email' => 'masodik@example.org',
        ])));
        self::assertSame('waitlisted', $second['status']);
        self::assertSame(self::WAITLISTED_TEXT, $second['after_submit']);
        self::assertSame(2, $this->registrations($form));

        $entry = $this->entriesOf('kalaka-portal/submit-registration')[0];
        self::assertSame('guest', $entry['actor_type']);
    }

    public function testARepeatAnswersTheFirstAfterSubmitAndAReusedTokenIsRefused(): void
    {
        $form = $this->openForm();
        $body = $this->submitBody($form);

        self::assertSame(200, $this->post($form, 'submit', $body)->get_status());
        $repeat = $this->post($form, 'submit', $body);

        self::assertSame(200, $repeat->get_status());
        self::assertSame(['repeated' => true, 'after_submit' => self::REGISTERED_TEXT], $this->data($repeat));

        $other = ['answers' => ['name' => 'Más Elek', 'email' => 'mas@example.org']] + $body;
        $reused = $this->post($form, 'submit', $other);
        $this->assertRefused($reused, 409, 'kalaka_token_reused');
        self::assertSame(
            'Ez az űrlap már nem küldhető be. Töltsd újra az oldalt, ha új jelentkezést szeretnél küldeni.',
            $this->data($reused)['message']
        );
        self::assertSame(1, $this->registrations($form));
    }

    public function testTheAfterSubmitSectionRendersWithTheCodeAndAWaitlistLineOnlyOnTheWaitlist(): void
    {
        $form = $this->openForm($this->slotFields() . $this->afterSubmit(), 1);

        $first = $this->data($this->post($form, 'submit', $this->submitBody($form)));
        self::assertSame('registered', $first['status']);
        self::assertStringContainsString('Köszönjük!', $first['after_submit']);
        self::assertStringContainsString('Kódod: ' . $first['code'], wp_strip_all_tags($first['after_submit']));
        self::assertStringNotContainsString('várólistára', $first['after_submit']);
        self::assertStringNotContainsString('data-kalaka-value', $first['after_submit']);

        $second = $this->data($this->post($form, 'submit', $this->submitBody($form, [
            'name' => 'Második Elek',
            'email' => 'masodik@example.org',
        ])));
        self::assertSame('waitlisted', $second['status']);
        self::assertStringContainsString('várólistára', $second['after_submit']);
    }

    public function testARepeatAnswersOnlyTheFirstRegistrationsAfterSubmitSection(): void
    {
        $form = $this->openForm($this->slotFields() . $this->afterSubmit());
        $body = $this->submitBody($form);

        $first = $this->data($this->post($form, 'submit', $body));
        $repeat = $this->data($this->post($form, 'submit', $body));

        self::assertSame(['repeated', 'after_submit'], array_keys($repeat));
        self::assertTrue($repeat['repeated']);
        self::assertSame($first['after_submit'], $repeat['after_submit']);
        self::assertStringContainsString('Kódod: ' . $first['code'], wp_strip_all_tags($repeat['after_submit']));
        self::assertSame(1, $this->registrations($form));
    }

    public function testMissingAnswersAreRefusedPerField(): void
    {
        $form = $this->openForm();

        $response = $this->post($form, 'submit', $this->submitBody($form, ['name' => 'Teszt Elek']));

        $this->assertRefused($response, 400, 'kalaka_invalid_answers');
        self::assertSame(['email'], array_keys($this->data($response)['data']['fields']));
        self::assertSame(0, $this->registrations($form));
    }

    public function testASubmitThatShowsATerminalPageIsRefusedAndWritesNothing(): void
    {
        $form = $this->openForm($this->pages());

        $availability = $this->data($this->post(
            $form,
            'availability',
            $this->availabilityBody($form, self::ANSWERS + ['mode' => 'young'])
        ));
        self::assertSame([true, false, true], $availability['availability']['pages']);

        $response = $this->post($form, 'submit', $this->submitBody($form, self::ANSWERS + ['mode' => 'young']));

        $this->assertRefused($response, 403, 'kalaka_form_terminal');
        self::assertSame('Ez az űrlap most nem küldhető be.', $this->data($response)['message']);
        self::assertSame(0, $this->registrations($form));

        $campAnswers = self::ANSWERS + ['mode' => 'camp', 'room' => 'A1'];
        $camp = $this->post($form, 'submit', $this->submitBody($form, $campAnswers));
        self::assertSame(200, $camp->get_status());
    }

    public function testAFailureInsideARouteIsOurHungarian500WithNoContext(): void
    {
        $form = $this->openForm($this->slotFields() . $this->tracks());
        $throw = static function (string $html, array $block): string {
            if (($block['blockName'] ?? null) === 'kalaka/choice-field') {
                throw new \RuntimeException('render exploded');
            }

            return $html;
        };
        add_filter('render_block', $throw, 10, 2);
        try {
            $response = null;
            $log = $this->capturedErrorLog(function () use ($form, &$response): void {
                $response = $this->post($form, 'availability', $this->availabilityBody($form));
            });
        } finally {
            remove_filter('render_block', $throw, 10);
        }

        self::assertInstanceOf(\WP_REST_Response::class, $response);
        $this->assertRefused($response, 500, 'kalaka_system_failure');
        $data = $this->data($response);
        self::assertStringStartsWith('Váratlan hiba történt. Próbáld újra később.', $data['message']);
        self::assertStringNotContainsString('exploded', $data['message']);
        self::assertSame(Correlation::id(), $data['data']['correlation']);
        self::assertStringContainsString('REST /kalaka-portal/v1/forms/(?P<id>\d+)/availability failed', $log);
        self::assertStringContainsString('render exploded', $log);
    }

    public function testAClosedSiteRefusesBothRoutes(): void
    {
        $form = $this->openForm();
        LoadState::record([new LoadFailure('x', 'y')]);
        try {
            $availability = $this->post($form, 'availability', $this->availabilityBody($form));
            $this->assertRefused($availability, 503, 'kalaka_site_closed');
            $this->assertRefused($this->post($form, 'submit', $this->submitBody($form)), 503, 'kalaka_site_closed');
        } finally {
            LoadState::record([]);
        }
        self::assertSame(0, $this->registrations($form));
    }
}
