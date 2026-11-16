<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Forms;

use Kalaka\Events\PublishEvent;
use Kalaka\Forms\Api\AnswerSet;
use Kalaka\Forms\Api\FormCaching;
use Kalaka\Forms\DraftHandoff;
use Kalaka\Forms\Engine\Rest\FormRoutes;
use Kalaka\Foundation\Api\Actor\AccountActor;
use Kalaka\Foundation\Api\Actor\GuestActor;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Registrations\Api\DraftClaim;
use Kalaka\Registrations\Api\Drafts;

/**
 * S74 on the form's page: the form opens with the person's draft; the page carries the autosave route for a
 * logged-in person and the claim route while a link's answers wait; the routes keep the autosave and the
 * choice; the handoff of `kalaka[<key>]` keeps the accepted answers as a guest draft, sets the cookie and
 * answers 303, and a logged-in person's page claims it.
 */
final class DraftPageTest extends FormsTestCase
{
    private const SIZES = [['value' => 's', 'label' => 'Kicsi'], ['value' => 'm', 'label' => 'Nagy']];

    private Runner $previous;

    private int $event;

    private int $form;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function set_up(): void
    {
        parent::set_up();
        $this->previous = Runner::current();
        Runner::publish($this->runner);
        $GLOBALS['wp_rest_server'] = null;

        $this->event = $this->create();
        $this->update($this->event);
        $this->transition(new PublishEvent(), $this->event);
        $this->form = $this->insertForm(
            $this->slotFields() . $this->field('choice', 'size', ['options' => self::SIZES])
                . $this->field('text', 'note'),
            ['kalaka_event_id' => $this->event]
        );
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function tear_down(): void
    {
        Runner::publish($this->previous);
        $GLOBALS['wp_rest_server'] = null;
        unset($_COOKIE[DraftClaim::COOKIE]);
        wp_set_current_user(0);
        parent::tear_down();
    }

    private function render(): string
    {
        return render_block([
            'blockName' => 'kalaka/form',
            'attrs' => ['formId' => $this->form],
            'innerBlocks' => [],
            'innerHTML' => '',
            'innerContent' => [],
        ]);
    }

    /** @return array<string, mixed> the page's script context */
    private function context(string $html): array
    {
        self::assertSame(1, preg_match("/data-wp-context='([^']*)'/", $html, $match));
        $context = json_decode(html_entity_decode($match[1], ENT_QUOTES), true);
        self::assertIsArray($context);

        return $context;
    }

    private function version(): int
    {
        return $this->readForm($this->form)->version;
    }

    private function logIn(): int
    {
        $user = self::factory()->user->create();
        wp_set_current_user($user);

        return $user;
    }

    /** @param array<array-key, mixed> $body */
    private function post(string $what, array $body): \WP_REST_Response
    {
        $request = new \WP_REST_Request('POST', '/' . FormRoutes::NAMESPACE . '/forms/' . $this->form . '/' . $what);
        $request->set_header('Content-Type', 'application/json');
        $request->set_body((string) wp_json_encode($body));

        return rest_do_request($request);
    }

    private function page(): \WP_Post
    {
        $id = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_content' => '<!-- wp:paragraph --><p>Jelentkezés</p><!-- /wp:paragraph -->'
                . '[kalaka_form id="' . $this->form . '"]',
        ]);
        $post = get_post($id);
        self::assertInstanceOf(\WP_Post::class, $post);

        return $post;
    }

    /** @return list<array<string, ?string>> */
    private function draftRows(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$wpdb->prefix}kalaka_drafts WHERE event_id = %d ORDER BY id", $this->event),
            ARRAY_A
        );
        self::assertIsArray($rows);

        return $rows;
    }

    public function testALoggedInPersonsFormOpensWithTheirDraftAndCarriesTheAutosaveRoute(): void
    {
        $user = $this->logIn();
        (new Drafts($this->runner))->save(
            $this->event,
            $this->form,
            $this->version(),
            new AnswerSet(['name' => 'Teszt Anna', 'size' => 'm', 'note' => 'félkész']),
            0,
            new AccountActor($user)
        );

        $html = $this->render();

        self::assertMatchesRegularExpression('#name="name"[^>]* value="Teszt Anna"#', $html);
        self::assertMatchesRegularExpression('#name="size"\s+value="m"[^>]* checked#', $html);
        self::assertMatchesRegularExpression('#name="note"[^>]* value="félkész"#', $html);
        $context = $this->context($html);
        self::assertStringEndsWith('/kalaka-portal/v1/forms/' . $this->form . '/draft', (string) $context['draftUrl']);
        self::assertArrayNotHasKey('draftClaimUrl', $context);
    }

    public function testADraftAnswerTheFormNoLongerAcceptsOpensEmpty(): void
    {
        $user = $this->logIn();
        (new Drafts($this->runner))->save(
            $this->event,
            $this->form,
            $this->version(),
            new AnswerSet(['size' => 'x', 'gone' => 'régi']),
            0,
            new AccountActor($user)
        );

        $html = $this->render();

        self::assertStringNotContainsString(' checked', $html);
        self::assertStringNotContainsString('régi', $html);
    }

    public function testAGuestHasNoAutosaveRoute(): void
    {
        self::assertArrayNotHasKey('draftUrl', $this->context($this->render()));
    }

    public function testTheHandoffKeepsTheAcceptedAnswersAsAGuestDraftAndAnswers303(): void
    {
        $redirect = (new DraftHandoff())->handle(
            $this->page(),
            ['size' => 'm', 'note' => 'a kvízből', 'email' => 'nem e-mail', 'unknown' => 'x', 'name' => ['a']],
            new GuestActor()
        );

        self::assertTrue($redirect);
        $rows = $this->draftRows();
        self::assertCount(1, $rows);
        self::assertNull($rows[0]['user_id']);
        self::assertSame(
            ['size' => 'm', 'note' => 'a kvízből'],
            AnswerSet::fromJson((string) $rows[0]['answers'])->answers
        );
        $token = DraftClaim::current();
        self::assertNotNull($token);
        self::assertSame(DraftClaim::hash($token), $rows[0]['claim_hash']);

        // The guest's page opens with them; there is no autosave.
        $html = $this->render();
        self::assertMatchesRegularExpression('#name="size"\s+value="m"[^>]* checked#', $html);
        self::assertArrayNotHasKey('draftUrl', $this->context($html));
    }

    public function testNoHandoffWhileTheFormCannotBeUsedOrForAPageWithoutIt(): void
    {
        $other = get_post(self::factory()->post->create(['post_type' => 'page', 'post_content' => '<p>x</p>']));
        self::assertInstanceOf(\WP_Post::class, $other);
        self::assertFalse((new DraftHandoff())->handle($other, ['note' => 'x'], new GuestActor()));

        $this->update($this->event, ['registration_opens_at' => '2026-12-01 08:00']);
        self::assertFalse((new DraftHandoff())->handle($this->page(), ['note' => 'x'], new GuestActor()));

        self::assertSame([], $this->draftRows());
    }

    public function testALoggedInPagesClaimMakesTheGuestDraftThePersonsAndClearsTheCookie(): void
    {
        (new DraftHandoff())->handle($this->page(), ['note' => 'a kvízből'], new GuestActor());
        $user = $this->logIn();

        self::assertFalse((new DraftHandoff())->handle($this->page(), null, new AccountActor($user)));

        $rows = $this->draftRows();
        self::assertCount(1, $rows);
        self::assertSame((string) $user, $rows[0]['user_id']);
        self::assertNull(DraftClaim::current());
    }

    public function testAConflictKeepsTheCookieAndThePageCarriesTheClaimRouteUntilTheChoice(): void
    {
        $user = $this->logIn();
        (new Drafts($this->runner))->save(
            $this->event,
            $this->form,
            $this->version(),
            new AnswerSet(['name' => 'Teszt Anna', 'note' => 'saját']),
            0,
            new AccountActor($user)
        );
        (new DraftHandoff())->handle($this->page(), ['note' => 'a kvízből'], new AccountActor($user));
        (new DraftHandoff())->handle($this->page(), null, new AccountActor($user));

        self::assertNotNull(DraftClaim::current());
        $context = $this->context($this->render());
        self::assertStringEndsWith('/forms/' . $this->form . '/draft/claim', (string) $context['draftClaimUrl']);

        $response = $this->post('draft/claim', ['choice' => 'merge']);

        self::assertSame(200, $response->get_status());
        self::assertSame(['resolved' => true], $response->get_data());
        $rows = $this->draftRows();
        self::assertCount(1, $rows);
        self::assertSame(
            ['name' => 'Teszt Anna', 'note' => 'a kvízből'],
            AnswerSet::fromJson((string) $rows[0]['answers'])->answers
        );
        self::assertNull(DraftClaim::current());
    }

    public function testTheAutosaveRouteKeepsTheFormsOwnKeysForALoggedInPerson(): void
    {
        $user = $this->logIn();

        $response = $this->post('draft', [
            'version' => $this->version(),
            'answers' => ['name' => 'Teszt Anna', 'stranger' => 'x'],
            'page' => 0,
        ]);

        self::assertSame(200, $response->get_status());
        self::assertSame(['saved' => true], $response->get_data());
        $rows = $this->draftRows();
        self::assertCount(1, $rows);
        self::assertSame((string) $user, $rows[0]['user_id']);
        self::assertSame(['name' => 'Teszt Anna'], AnswerSet::fromJson((string) $rows[0]['answers'])->answers);
        self::assertSame(FormCaching::CACHE_CONTROL, $response->get_headers()['Cache-Control'] ?? null);
    }

    public function testTheAutosaveRouteRefusesAGuestAndATooLargeBody(): void
    {
        $guest = $this->post('draft', ['version' => $this->version(), 'answers' => ['name' => 'x'], 'page' => 0]);
        self::assertGreaterThanOrEqual(400, $guest->get_status());

        $this->logIn();
        $large = $this->post('draft', [
            'version' => $this->version(),
            'answers' => ['note' => str_repeat('x', FormRoutes::DRAFT_LIMIT)],
            'page' => 0,
        ]);
        self::assertSame(400, $large->get_status());
        self::assertSame([], $this->draftRows());
    }
}
