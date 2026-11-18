<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Forms;

use Kalaka\Events\PublishEvent;
use Kalaka\Forms\Api\AbstractFieldType;
use Kalaka\Forms\Api\AnswerSet;
use Kalaka\Forms\Api\DraftClaimChoice;
use Kalaka\Forms\Api\FieldAnswer;
use Kalaka\Forms\Api\FieldTypeInput;
use Kalaka\Forms\Api\FormDefinition;
use Kalaka\Forms\Api\FormField;
use Kalaka\Forms\Api\Forms;
use Kalaka\Forms\Api\FormCaching;
use Kalaka\Forms\DraftHandoff;
use Kalaka\Forms\EventFormHost;
use Kalaka\Forms\Engine\DraftClaimTexts;
use Kalaka\Forms\Engine\FieldType\ConsentFieldType;
use Kalaka\Forms\Engine\FormEngine;
use Kalaka\Forms\Engine\Rest\FormRoutes;
use Kalaka\Forms\Module;
use Kalaka\Foundation\Api\Actor\AccountActor;
use Kalaka\Foundation\Api\Actor\GuestActor;
use Kalaka\Foundation\Api\Module\Contributions;
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
    /** A refused claim's text (N11), word for word as the owner approved it on 2026-11-17. */
    private const REFUSED = 'Ezt most nem tudjuk végrehajtani: a bejövő válaszokról már döntöttél (például egy '
        . 'másik lapon), vagy ezek a válaszok már nem érvényesek. Töltsd újra az oldalt.';

    private const SIZES = [['value' => 's', 'label' => 'Kicsi'], ['value' => 'm', 'label' => 'Nagy']];

    private const STAY = [['value' => 'igen', 'label' => 'Igen'], ['value' => 'nem', 'label' => 'Nem']];

    private const SHIRTS = [
        ['value' => 's', 'label' => 'S'],
        ['value' => 'm', 'label' => 'M'],
        ['value' => 'l', 'label' => 'L'],
    ];

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
                . $this->field('text', 'note')
                . $this->field('choice', 'szallas', ['options' => self::STAY])
                . $this->field('choice', 'polo', ['options' => self::SHIRTS])
                . $this->field('choice', 'meret', ['options' => self::SHIRTS, 'multiple' => true]),
            ['kalaka_event_id' => $this->event]
        );
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function tear_down(): void
    {
        Runner::publish($this->previous);
        $GLOBALS['wp_rest_server'] = null;
        unset($_COOKIE[DraftClaim::COOKIE]);
        DraftHandoff::forget();
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
            0,
            new AccountActor($user)
        );

        $html = $this->render();

        self::assertStringNotContainsString(' checked', $html);
        self::assertStringNotContainsString('régi', $html);
    }

    public function testAHalfTypedTextOfTheOwnDraftOpensAsTyped(): void
    {
        $user = $this->logIn();
        (new Drafts($this->runner))->save(
            $this->event,
            $this->form,
            $this->version(),
            new AnswerSet(['email' => 'teszt.anna@']),
            0,
            0,
            new AccountActor($user)
        );

        self::assertMatchesRegularExpression('#name="email"[^>]* value="teszt.anna@"#', $this->render());
    }

    public function testOnlyAPrefillOrALoggedInClaimCookieGivesAPageViewAnythingToDo(): void
    {
        self::assertFalse(DraftHandoff::hasWork(null, 0, null));
        self::assertFalse(DraftHandoff::hasWork(null, 7, null));
        self::assertFalse(DraftHandoff::hasWork(null, 0, DraftClaim::generate()));
        self::assertTrue(DraftHandoff::hasWork(null, 7, DraftClaim::generate()));
        self::assertTrue(DraftHandoff::hasWork(['note' => 'x'], 0, null));
    }

    public function testTheRedirectDropsTheWholePrefillParameter(): void
    {
        self::assertSame(
            '/jelentkezes/?x=1',
            remove_query_arg(DraftHandoff::PARAMETER, '/jelentkezes/?kalaka%5Bsize%5D=m&kalaka%5Bnote%5D=a&x=1')
        );
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

    /**
     * The quiz may send only its ranking, with none of its answers (stakeholder ruling 2026-11-17): a body
     * with one key opens the form with that answer, every other field empty, and nothing marked as an error.
     */
    public function testAOneKeyHandoffOpensTheOtherFieldsEmptyWithNoErrorShown(): void
    {
        $redirect = (new DraftHandoff())->handle($this->page(), ['size' => 'm'], new GuestActor());

        self::assertTrue($redirect);
        self::assertSame(['size' => 'm'], AnswerSet::fromJson((string) $this->draftRows()[0]['answers'])->answers);

        $html = $this->render();
        self::assertMatchesRegularExpression('#name="size"\s+value="m"[^>]* checked#', $html);
        self::assertDoesNotMatchRegularExpression('#name="(name|email|note)"[^>]* value="[^"]+"#', $html);
        self::assertStringNotContainsString('aria-invalid', $html);
        self::assertSame(
            0,
            preg_match('#<span id="kalaka-field-[a-z_]+-error"[^>]*>[^<]+</span>#', $html),
            'An error element of the opened form holds text.'
        );
        self::assertSame(
            substr_count($html, '-error"'),
            preg_match_all('#<span id="kalaka-field-[a-z_]+-error"[^>]* hidden></span>#', $html),
            'Every error element of the opened form is hidden and empty.'
        );
    }

    /**
     * Master ruling 2026-11-17: a link's value is cleaned by its type's prefill rules before the check, so a
     * multi-choice with one option the field does not offer keeps the offered ones, rather than being dropped
     * whole by the submit check. A list is read as the JSON text it is stored as, and must clean to a list.
     */
    public function testAHandoffKeepsTheOfferedOptionsOfAMultiChoiceAndDropsTheOthers(): void
    {
        $redirect = (new DraftHandoff())->handle(
            $this->page(),
            ['meret' => ['s', 'xxl', 'l'], 'polo' => 'xxl', 'note' => ['a'], 'szallas' => '["igen"]'],
            new GuestActor()
        );

        self::assertTrue($redirect);
        self::assertSame(
            ['meret' => ['s', 'l']],
            AnswerSet::fromJson((string) $this->draftRows()[0]['answers'])->answers
        );
        $html = $this->render();
        self::assertMatchesRegularExpression('#name="meret\[\]"\s+value="s"[^>]* checked#', $html);
        self::assertMatchesRegularExpression('#name="meret\[\]"\s+value="l"[^>]* checked#', $html);
        self::assertDoesNotMatchRegularExpression('#name="meret\[\]"\s+value="m"[^>]* checked#', $html);
    }

    /**
     * A value its type's prefill makes nothing of is dropped, even one the type's submit check would accept;
     * a consent is never prefilled from a link (`RefusesLinkPrefill`), though its stored `1` opens ticked.
     */
    public function testALinkValueWhosePrefillIsNullIsDropped(): void
    {
        $refusing = new class () extends AbstractFieldType {
            public function blockName(): string
            {
                return 'kalaka/test-refusing-field';
            }

            public function answer(FieldTypeInput $input): FieldAnswer
            {
                return FieldAnswer::stored(is_string($input->value) ? $input->value : '');
            }

            public function prefill(FieldTypeInput $input): mixed
            {
                return null;
            }
        };
        $contributions = new Contributions();
        $contributions->fieldType($refusing);
        $contributions->fieldType(new ConsentFieldType());
        $before = Contributions::current();
        Contributions::record($contributions);
        try {
            $definition = new FormDefinition($this->form, 1, [
                new FormField('kod', 'kalaka/test-refusing-field', ['key' => 'kod']),
                new FormField('hozzajarul', 'kalaka/consent-field', ['key' => 'hozzajarul']),
            ]);
            $sent = new AnswerSet(['kod' => 'abc', 'hozzajarul' => '1']);

            // The submit check alone accepts it.
            $engine = new FormEngine(new EventFormHost());
            self::assertSame(['kod' => 'abc'], $engine->accepted($definition, $sent)->answers);
            self::assertSame([], (new Forms())->linkAnswers($definition, $sent)->answers);
            $stored = (new ConsentFieldType())->prefill(new FieldTypeInput('1', []));
            self::assertTrue($stored, 'A stored consent opens ticked.');
        } finally {
            Contributions::record($before);
        }
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
        // The cookie stays for a repeat; the next load of the page clears it.
        self::assertNotNull(DraftClaim::current());
        (new DraftHandoff())->handle($this->page(), null, new AccountActor($user));
        self::assertNull(DraftClaim::current());
    }

    public function testTheClaimRouteAnswersARepeatAsTheFirstAndRefusesAnotherChoice(): void
    {
        $user = $this->logIn();
        $this->autosave(['name' => 'Teszt Anna', 'note' => 'saját']);
        (new DraftHandoff())->handle($this->page(), ['note' => 'a kvízből'], new AccountActor($user));

        self::assertSame(200, $this->post('draft/claim', ['choice' => 'keep_link'])->get_status());
        $again = $this->post('draft/claim', ['choice' => 'keep_link']);
        self::assertSame(200, $again->get_status());
        self::assertSame(['resolved' => true], $again->get_data());

        $other = $this->post('draft/claim', ['choice' => 'merge']);
        self::assertSame(409, $other->get_status());
        self::assertSame('kalaka_draft_claim_refused', $other->get_data()['code']);
        self::assertSame(self::REFUSED, $other->get_data()['message'], 'the owner\'s text (N11, 2026-11-17)');
        self::assertSame(['note' => 'a kvízből'], $this->storedAnswers());
    }

    public function testTheClaimRouteRefusesWithoutAClaimCookie(): void
    {
        $this->logIn();

        $response = $this->post('draft/claim', ['choice' => 'merge']);

        self::assertSame(409, $response->get_status());
        self::assertSame('kalaka_draft_claim_refused', $response->get_data()['code']);
        self::assertSame(self::REFUSED, $response->get_data()['message']);
    }

    public function testTheAutosaveRouteKeepsTheFormsOwnKeysForALoggedInPerson(): void
    {
        $user = $this->logIn();

        $response = $this->post('draft', [
            'version' => $this->version(),
            'answers' => ['name' => 'Teszt Anna', 'stranger' => 'x'],
            'page' => 0,
            'claims' => 0,
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
        $guest = $this->post(
            'draft',
            ['version' => $this->version(), 'answers' => ['name' => 'x'], 'page' => 0, 'claims' => 0]
        );
        self::assertGreaterThanOrEqual(400, $guest->get_status());

        $this->logIn();
        $large = $this->post('draft', [
            'version' => $this->version(),
            'answers' => ['note' => str_repeat('x', FormRoutes::DRAFT_LIMIT)],
            'page' => 0,
            'claims' => 0,
        ]);
        self::assertSame(400, $large->get_status());
        self::assertSame([], $this->draftRows());
    }

    /**
     * The person's own draft, kept through the autosave route as the page's script sends it.
     *
     * @param array<string, mixed> $answers
     */
    private function autosave(array $answers, int $claims = 0): \WP_REST_Response
    {
        return $this->post('draft', [
            'version' => $this->version(),
            'answers' => $answers,
            'page' => 0,
            'claims' => $claims,
        ]);
    }

    /** @return array<string, mixed> the stored answers of the only draft */
    private function storedAnswers(): array
    {
        $rows = $this->draftRows();
        self::assertCount(1, $rows);

        return AnswerSet::fromJson((string) $rows[0]['answers'])->answers;
    }

    public function testALinkThatChangesNothingInTheDraftIsSettledWithNoChoiceAndTheCookieCleared(): void
    {
        $user = $this->logIn();
        $saved = $this->autosave(['name' => 'Teszt Anna', 'szallas' => 'igen', 'polo' => 'l']);
        self::assertSame(200, $saved->get_status());

        // The link (`?kalaka[szallas]=igen&kalaka[polo]=l`) sets exactly what the draft holds.
        self::assertTrue((new DraftHandoff())->handle(
            $this->page(),
            ['szallas' => 'igen', 'polo' => 'l'],
            new AccountActor($user)
        ));
        self::assertNotNull(DraftClaim::current());
        self::assertCount(2, $this->draftRows());
        // Even before the claim settles it, the page offers no choice.
        self::assertArrayNotHasKey('draftClaimUrl', $this->context($this->render()));

        self::assertFalse((new DraftHandoff())->handle($this->page(), null, new AccountActor($user)));

        self::assertNull(DraftClaim::current());
        self::assertSame(['name' => 'Teszt Anna', 'szallas' => 'igen', 'polo' => 'l'], $this->storedAnswers());
        $context = $this->context($this->render());
        self::assertArrayNotHasKey('draftClaimUrl', $context);
        self::assertSame(0, $context['draftClaims']);
    }

    public function testALinkThatChangesOneValueOfTheDraftWaitsForTheChoice(): void
    {
        $user = $this->logIn();
        $this->autosave(['name' => 'Teszt Anna', 'szallas' => 'igen', 'polo' => 'l']);

        (new DraftHandoff())->handle($this->page(), ['szallas' => 'igen', 'polo' => 'm'], new AccountActor($user));
        (new DraftHandoff())->handle($this->page(), null, new AccountActor($user));

        self::assertNotNull(DraftClaim::current());
        self::assertCount(2, $this->draftRows());
        $context = $this->context($this->render());
        self::assertArrayHasKey('draftClaimUrl', $context);
        self::assertSame(1, $context['draftClaimChanges'], 'only polo changes; szallas is the same');
        self::assertArrayNotHasKey('draftClaimNotice', $context);
        // The popup's texts, word for word as the owner approved them on 2026-11-17, the count in merge's line.
        self::assertSame([
            'title' => 'Előre kitöltött linkből bejövő válaszok',
            'intro' => 'Ehhez az űrlaphoz már vannak mentett, még be nem küldött válaszaid. Most egy előre '
                . 'kitöltött linkből is érkeztek válaszok. Hogyan folytassuk?',
            'warning' => 'Egyesítéskor a bejövő válaszok miatt az űrlap későbbi részén kérdések tűnhetnek el vagy '
                . 'jelenhetnek meg. Egy eltűnt kérdésre adott válaszod nem kerül be a jelentkezésedbe, így az '
                . 'egyesítés a későbbi válaszaidat is megváltoztathatja. Beküldés előtt nézd át az egész űrlapot.',
            'merge' => 'Egyesítés',
            'mergeLine' => 'A bejövő válaszok felülírják a mentetteket: 1 kérdésnél változik a válaszod. '
                . 'A többi mentett válaszod megmarad.',
            'keepDraft' => 'Csak a mentett válaszok',
            'keepDraftLine' => 'A mentett válaszaid megmaradnak, a bejövő válaszokat elvetjük.',
            'keepLink' => 'Csak a bejövő válaszok',
            'keepLinkLine' => 'Csak a bejövő válaszok maradnak, az összes mentett válaszodat töröljük.',
            'confirmTitle' => 'Törlöd a mentett válaszaidat?',
            'confirmText' => 'Ha csak a bejövő válaszokat tartod meg, az összes mentett válaszodat töröljük az '
                . 'űrlap minden oldaláról. Ezt nem lehet visszavonni.',
            'confirmCancel' => 'Mégse',
            'confirm' => 'Igen, törlöm a mentett válaszaimat',
            'reload' => 'Az oldal újratöltése',
        ], $context['draftClaimTexts']);
    }

    public function testThePopupScriptLoadsOnlyWhileAChoiceWaits(): void
    {
        $user = $this->logIn();
        $this->autosave(['name' => 'Teszt Anna', 'polo' => 'l']);
        wp_dequeue_script(Module::CLAIM_SCRIPT);
        wp_dequeue_style('wp-components');
        $this->render();
        self::assertFalse(wp_script_is(Module::CLAIM_SCRIPT, 'enqueued'), 'no claim: no popup');

        (new DraftHandoff())->handle($this->page(), ['polo' => 'm'], new AccountActor($user));
        $context = $this->context($this->render());

        self::assertArrayHasKey('draftClaimTexts', $context);
        self::assertTrue(wp_script_is(Module::CLAIM_SCRIPT, 'enqueued'));
        self::assertTrue(wp_style_is('wp-components', 'enqueued'));
        self::assertSame(['wp-components', 'wp-element'], wp_scripts()->registered[Module::CLAIM_SCRIPT]->deps);
    }

    public function testThePageCountsTheFieldsTheLinkWouldChange(): void
    {
        $user = $this->logIn();
        $this->autosave(['name' => 'Teszt Anna', 'szallas' => 'igen', 'polo' => 'l']);

        $link = ['szallas' => 'nem', 'polo' => 'm', 'note' => 'a kvízből', 'name' => 'Teszt Anna'];
        (new DraftHandoff())->handle($this->page(), $link, new AccountActor($user));

        $context = $this->context($this->render());
        self::assertSame(3, $context['draftClaimChanges']);
        self::assertStringContainsString(': 3 kérdésnél változik', $context['draftClaimTexts']['mergeLine']);
    }

    public function testTheLoadAfterAChoiceCarriesItOnceForTheNotice(): void
    {
        $user = $this->logIn();
        $this->autosave(['name' => 'Teszt Anna', 'note' => 'saját']);
        (new DraftHandoff())->handle($this->page(), ['note' => 'a kvízből'], new AccountActor($user));
        self::assertArrayNotHasKey('draftClaimNotice', $this->context($this->render()));
        self::assertSame(200, $this->post('draft/claim', ['choice' => 'merge'])->get_status());

        // The reload after the choice: the cookie is cleared, and the page carries the choice.
        (new DraftHandoff())->handle($this->page(), null, new AccountActor($user));
        self::assertNull(DraftClaim::current());
        $context = $this->context($this->render());
        self::assertSame('merge', $context['draftClaimNotice']);
        self::assertSame(
            'Egyesítettük a mentett és a bejövő válaszokat. Beküldés előtt nézd át az egész űrlapot.',
            $context['draftClaimNoticeText']
        );
        self::assertArrayNotHasKey('draftClaimUrl', $context);
        self::assertArrayNotHasKey('draftClaimTexts', $context);

        // The next load: no cookie, no notice.
        DraftHandoff::forget();
        (new DraftHandoff())->handle($this->page(), null, new AccountActor($user));
        self::assertArrayNotHasKey('draftClaimNotice', $this->context($this->render()));
    }

    public function testTheNoticeOfEachChoiceIsTheOwnersText(): void
    {
        self::assertSame(
            'Egyesítettük a mentett és a bejövő válaszokat. Beküldés előtt nézd át az egész űrlapot.',
            DraftClaimTexts::notice(DraftClaimChoice::Merge)
        );
        self::assertSame(
            'Megtartottuk a mentett válaszaidat, a bejövő válaszokat elvetettük.',
            DraftClaimTexts::notice(DraftClaimChoice::KeepDraft)
        );
        self::assertSame(
            'Megtartottuk a bejövő válaszokat, a mentett válaszaidat töröltük.',
            DraftClaimTexts::notice(DraftClaimChoice::KeepLink)
        );
    }

    public function testAClaimSettledOnThePageWithNoChoiceGivesNoNotice(): void
    {
        (new DraftHandoff())->handle($this->page(), ['note' => 'a kvízből'], new GuestActor());
        $user = $this->logIn();

        (new DraftHandoff())->handle($this->page(), null, new AccountActor($user));

        self::assertArrayNotHasKey('draftClaimNotice', $this->context($this->render()));
    }

    /** @return iterable<string, array{string, int}> the choice and the status of a save from before it */
    public static function staleSaves(): iterable
    {
        yield 'merge: refused' => ['merge', 409];
        yield 'keep the link: refused' => ['keep_link', 409];
        yield 'keep the draft: the draft is unchanged, the save goes in' => ['keep_draft', 200];
    }

    /** @dataProvider staleSaves */
    public function testASaveFromBeforeAChoiceThatChangedTheDraftIsRefusedAndTheDraftKept(
        string $choice,
        int $status
    ): void {
        $user = $this->logIn();
        $this->autosave(['name' => 'Teszt Anna', 'note' => 'saját']);
        (new DraftHandoff())->handle($this->page(), ['note' => 'a kvízből'], new AccountActor($user));
        (new DraftHandoff())->handle($this->page(), null, new AccountActor($user));
        $before = $this->context($this->render());
        self::assertSame(0, $before['draftClaims']);

        self::assertSame(200, $this->post('draft/claim', ['choice' => $choice])->get_status());
        $chosen = $this->storedAnswers();

        // The page's keepalive save of the answers it showed before the choice.
        $stale = $this->autosave(['name' => 'Teszt Anna', 'note' => 'saját, gépelés közben'], $before['draftClaims']);

        self::assertSame($status, $stale->get_status());
        if ($status === 409) {
            self::assertSame('kalaka_version_conflict', $stale->get_data()['code']);
            self::assertSame($chosen, $this->storedAnswers());
            // The page as it reloads carries the new count, and its saves go in.
            $after = $this->context($this->render());
            self::assertSame(1, $after['draftClaims']);
            self::assertSame(200, $this->autosave(['note' => 'új'], $after['draftClaims'])->get_status());
            self::assertSame(['note' => 'új'], $this->storedAnswers());
        }
    }

    public function testASaveFromATabOpenedBeforeTheGuestDraftBecameThePersonsIsRefused(): void
    {
        (new DraftHandoff())->handle($this->page(), ['note' => 'a kvízből'], new GuestActor());
        $user = $this->logIn();
        (new DraftHandoff())->handle($this->page(), null, new AccountActor($user));

        $stale = $this->autosave(['note' => 'másik lapon'], 0);

        self::assertSame(409, $stale->get_status());
        self::assertSame(['note' => 'a kvízből'], $this->storedAnswers());
        self::assertSame(1, $this->context($this->render())['draftClaims']);
    }

    public function testTheAutosaveRouteNeedsTheClaimCount(): void
    {
        $this->logIn();

        $response = $this->post('draft', ['version' => $this->version(), 'answers' => ['note' => 'x'], 'page' => 0]);

        self::assertSame(400, $response->get_status());
        self::assertSame([], $this->draftRows());
    }
}
