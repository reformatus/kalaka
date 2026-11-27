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
use Kalaka\Forms\Engine\SubmitGuard;
use Kalaka\Forms\Module;
use Kalaka\Foundation\Api\Actor\AccountActor;
use Kalaka\Foundation\Api\Actor\Actor;
use Kalaka\Foundation\Api\Actor\GuestActor;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Foundation\ObjectCache;
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
        $this->accountMode('optional');
        $this->form = $this->insertForm(
            '<!-- wp:kalaka/account /-->' . $this->slotFields()
                . $this->field('choice', 'size', ['options' => self::SIZES])
                . $this->field('text', 'note')
                . $this->field('phone', 'telefon')
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
        unset($_COOKIE[DraftClaim::COOKIE], $_COOKIE[DraftClaim::HANDOFF_COOKIE]);
        DraftHandoff::forget();
        wp_set_current_user(0);
        parent::tear_down();
    }

    /** The event's account mode, set directly: the mode's own use case is S72's. */
    private function accountMode(string $mode): void
    {
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'kalaka_events', ['account_mode' => $mode], ['id' => $this->event]);
        ObjectCache::clear();
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

    /**
     * The page load the 303 of a handoff leads to (N1106): the handoff that waits in its cookie is settled
     * against the draft the draft cookie names.
     */
    private function landing(Actor $actor): void
    {
        (new DraftHandoff())->handle($this->page(), null, $actor);
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
            new AnswerSet(['telefon' => '+3630']),
            0,
            0,
            new AccountActor($user)
        );

        self::assertMatchesRegularExpression('#name="telefon"[^>]* value="\+3630"#', $this->render());
    }

    public function testOnlyAPrefillOrALoggedInClaimCookieGivesAPageViewAnythingToDo(): void
    {
        self::assertFalse(DraftHandoff::hasWork(null, 0, null));
        self::assertFalse(DraftHandoff::hasWork(null, 7, null));
        self::assertFalse(DraftHandoff::hasWork(null, 0, DraftClaim::generate()));
        self::assertTrue(DraftHandoff::hasWork(null, 7, DraftClaim::generate()));
        self::assertTrue(DraftHandoff::hasWork(['note' => 'x'], 0, null));
        // A handoff waiting in its cookie is work for a guest too (N1106).
        self::assertTrue(DraftHandoff::hasWork(null, 0, null, DraftClaim::generate()));
        self::assertFalse(DraftHandoff::hasWork(null, 0, null, null));
    }

    public function testTheRedirectDropsTheWholePrefillParameter(): void
    {
        self::assertSame(
            '/jelentkezes/?x=1',
            remove_query_arg(DraftHandoff::PARAMETER, '/jelentkezes/?kalaka%5Bsize%5D=m&kalaka%5Bnote%5D=a&x=1')
        );
    }

    /** N675: a handoff writes a row, so it counts like a submit, with the guest submit's budget. */
    public function testTheHandoffAfterTheGuestBudgetIsRefusedAndWritesNoRow(): void
    {
        $handoff = new DraftHandoff();
        for ($i = 0; $i < SubmitGuard::ADDRESS_LIMIT; $i++) {
            self::assertTrue($handoff->handle($this->page(), ['size' => 'm'], new GuestActor()));
        }
        $rows = $this->draftRows();
        self::assertCount(1, $rows);

        // A new browser: without the cookie, an allowed handoff would write a second row.
        unset($_COOKIE[DraftClaim::COOKIE], $_COOKIE[DraftClaim::HANDOFF_COOKIE]);
        self::assertTrue($handoff->handle($this->page(), ['size' => 'm'], new GuestActor()));

        self::assertSame($rows, $this->draftRows());
        self::assertNull(DraftClaim::handoff());
    }

    /**
     * Owner ruling 2026-11-26 (reverses N9 and N676): in the account mode `off` a guest keeps the same guest
     * draft the other modes do, a reload keeps the handed-over answers, and the send deletes the draft.
     */
    public function testAGuestKeepsAGuestDraftInTheAccountModeOffAndTheSendDeletesIt(): void
    {
        $this->accountMode('off');
        $handoff = new DraftHandoff();
        self::assertTrue($handoff->handle($this->page(), ['size' => 'm', 'note' => 'a kvízből'], new GuestActor()));
        $this->landing(new GuestActor());

        $rows = $this->draftRows();
        self::assertCount(1, $rows);
        self::assertNull($rows[0]['user_id']);
        $token = DraftClaim::current();
        self::assertNotNull($token);
        self::assertSame(DraftClaim::hash($token), $rows[0]['claim_hash']);

        // A reload: the form opens with them again, and the guest's own typing goes into the same draft.
        $html = $this->render();
        self::assertMatchesRegularExpression('#name="size"\s+value="m"[^>]* checked#', $html);
        self::assertStringContainsString('a kvízből', $html);
        $context = $this->context($html);
        self::assertArrayHasKey('draftUrl', $context);
        self::assertArrayNotHasKey('draftStart', $context);
        self::assertCount(1, $this->draftRows());

        (new EventFormHost())->submit(
            $this->form,
            $this->version(),
            ['name' => 'Teszt Anna', 'email' => 'teszt.anna@example.org', 'size' => 'm', 'note' => 'a kvízből'],
            (new EventFormHost())->submitToken(),
            [],
            false,
            new GuestActor()
        );

        self::assertSame([], $this->draftRows());
        self::assertNull(DraftClaim::current());
    }

    /** In `off` a logged-in person has the draft they have in the other modes: the host keeps and opens it. */
    public function testALoggedInPersonsDraftWorksInTheAccountModeOff(): void
    {
        $this->accountMode('off');
        $form = (new Forms())->form($this->form);
        self::assertNotNull($form);
        $host = new EventFormHost();
        $user = $this->logIn();

        $host->saveDraft($form, $form->version, new AnswerSet(['note' => 'félkész']), 0, 0, new AccountActor($user));

        self::assertSame('félkész', $host->draft($form)->answers->answers['note']);
    }

    /** Owner ruling 2026-11-26: a guest's page carries the autosave route; with no draft yet the first answer starts it. */
    public function testAGuestsPageCarriesTheAutosaveRouteAndStartsItOnlyWithNoDraftYet(): void
    {
        $context = $this->context($this->render());
        self::assertStringEndsWith('/kalaka-portal/v1/forms/' . $this->form . '/draft', (string) $context['draftUrl']);
        self::assertTrue($context['draftStart']);
        self::assertSame('', $context['nonce']);

        (new DraftHandoff())->handle($this->page(), ['note' => 'a kvízből'], new GuestActor());
        $this->landing(new GuestActor());
        $context = $this->context($this->render());
        self::assertArrayHasKey('draftUrl', $context);
        self::assertArrayNotHasKey('draftStart', $context);
    }

    /**
     * Owner ruling 2026-11-26: a guest's own typing autosaves into the guest draft a link's handoff writes: the
     * first save creates it and gives the cookie, later ones update the same row, a reload opens with it, and
     * the send deletes it. Any account mode: `mandatory` shows the form to a guest too.
     *
     * @dataProvider accountModes
     */
    public function testAGuestsTypingAutosavesIntoTheGuestDraftAndTheSendDeletesIt(string $mode): void
    {
        $this->accountMode($mode);
        self::assertNull(DraftClaim::current());

        $first = $this->autosave(['note' => 'T', 'stranger' => 'x']);

        self::assertSame(200, $first->get_status());
        self::assertSame(['saved' => true], $first->get_data());
        $token = DraftClaim::current();
        self::assertNotNull($token);
        $rows = $this->draftRows();
        self::assertCount(1, $rows);
        self::assertNull($rows[0]['user_id']);
        self::assertSame(DraftClaim::hash($token), $rows[0]['claim_hash']);
        self::assertSame(['note' => 'T'], $this->storedAnswers());
        self::assertSame(FormCaching::CACHE_CONTROL, $first->get_headers()['Cache-Control'] ?? null);

        // The next save, with the cookie, updates the same draft and keeps the token.
        self::assertSame(200, $this->autosave(['note' => 'Teszt jegyzet', 'size' => 'm'])->get_status());
        self::assertSame($token, DraftClaim::current());
        self::assertCount(1, $this->draftRows());
        self::assertSame(['note' => 'Teszt jegyzet', 'size' => 'm'], $this->storedAnswers());

        // A reload: the form opens with them.
        $html = $this->render();
        self::assertMatchesRegularExpression('#name="size"\s+value="m"[^>]* checked#', $html);
        self::assertStringContainsString('Teszt jegyzet', $html);
        self::assertArrayNotHasKey('draftStart', $this->context($html));

        if ($mode === 'mandatory') {
            // A guest cannot send in this mode (the account is the send's); a reload keeps their typing all the same.
            return;
        }
        (new EventFormHost())->submit(
            $this->form,
            $this->version(),
            ['name' => 'Teszt Anna', 'email' => 'teszt.anna@example.org', 'size' => 'm', 'note' => 'Teszt jegyzet'],
            (new EventFormHost())->submitToken(),
            [],
            false,
            new GuestActor()
        );
        self::assertSame([], $this->draftRows());
        self::assertNull(DraftClaim::current());
    }

    /** @return array<string, array{string}> */
    public static function accountModes(): array
    {
        return ['off' => ['off'], 'optional' => ['optional'], 'mandatory' => ['mandatory']];
    }

    /** A guest save that creates a draft counts like a handoff, per address; an update of their own draft does not. */
    public function testAGuestSaveThatCreatesADraftIsRateLimitedAndAnUpdateIsNot(): void
    {
        $last = null;
        for ($i = 0; $i < SubmitGuard::ADDRESS_LIMIT; $i++) {
            // No cookie: each save is a new browser's first one.
            unset($_COOKIE[DraftClaim::COOKIE]);
            self::assertSame(200, $this->autosave(['note' => 'a' . $i])->get_status());
            $last = DraftClaim::current();
        }
        self::assertNotNull($last);
        self::assertCount(SubmitGuard::ADDRESS_LIMIT, $this->draftRows());

        unset($_COOKIE[DraftClaim::COOKIE]);
        $refused = $this->autosave(['note' => 'túl sok']);
        self::assertSame(429, $refused->get_status());
        self::assertCount(SubmitGuard::ADDRESS_LIMIT, $this->draftRows());
        self::assertNull(DraftClaim::current());

        // The last browser keeps typing into its own draft, which takes no budget.
        $_COOKIE[DraftClaim::COOKIE] = $last;
        self::assertSame(200, $this->autosave(['note' => 'tovább'])->get_status());
        self::assertCount(SubmitGuard::ADDRESS_LIMIT, $this->draftRows());
    }

    /** A cookie naming no draft of this form (swept, sent, another form's) is replaced: one browser holds one. */
    public function testAGuestWhoseCookieNamesNoDraftOfTheFormGetsANewTokenAndTheOldOneIsDeleted(): void
    {
        $other = $this->insertForm(
            $this->field('text', 'note'),
            ['kalaka_event_id' => $this->event]
        );
        $otherForm = (new Forms())->form($other);
        self::assertNotNull($otherForm);
        $host = new EventFormHost();
        $host->saveDraft($otherForm, $otherForm->version, new AnswerSet(['note' => 'másik']), 0, 0, new GuestActor());
        $earlier = DraftClaim::current();
        self::assertNotNull($earlier);

        $this->autosave(['note' => 'ez']);

        $now = DraftClaim::current();
        self::assertNotNull($now);
        self::assertNotSame($earlier, $now);
        $rows = $this->draftRows();
        self::assertCount(1, $rows);
        self::assertSame(DraftClaim::hash($now), $rows[0]['claim_hash']);
        self::assertSame($this->form, (int) $rows[0]['form_id']);
    }

    /** The guest draft a guest typed becomes the person's draft when they log in, as a link's does. */
    public function testALoggedInPagesClaimMakesAGuestsTypedDraftThePersons(): void
    {
        $this->autosave(['note' => 'vendég']);
        $user = $this->logIn();

        (new DraftHandoff())->handle($this->page(), null, new AccountActor($user));

        $rows = $this->draftRows();
        self::assertCount(1, $rows);
        self::assertSame((string) $user, $rows[0]['user_id']);
        self::assertSame(['note' => 'vendég'], $this->storedAnswers());
        self::assertNull(DraftClaim::current());
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
        // The handoff waits in its own cookie; the draft cookie is the next load's (N1106).
        self::assertNull(DraftClaim::current());
        $waiting = DraftClaim::handoff();
        self::assertNotNull($waiting);
        self::assertSame(DraftClaim::hash($waiting), $rows[0]['claim_hash']);
        $this->landing(new GuestActor());
        self::assertSame($waiting, DraftClaim::current());
        self::assertNull(DraftClaim::handoff());

        // The guest's page opens with them, and their own typing is saved into the same draft.
        $html = $this->render();
        self::assertMatchesRegularExpression('#name="size"\s+value="m"[^>]* checked#', $html);
        self::assertArrayHasKey('draftUrl', $this->context($html));
    }

    /** N681: on a mandatory-account event a guest's quiz or link handoff is accepted and its draft written. */
    public function testAGuestsHandoffIsKeptInTheMandatoryAccountMode(): void
    {
        $this->accountMode('mandatory');

        $redirect = (new DraftHandoff())->handle($this->page(), ['note' => 'a kvízből'], new GuestActor());

        self::assertTrue($redirect);
        $rows = $this->draftRows();
        self::assertCount(1, $rows);
        self::assertNull($rows[0]['user_id']);
        self::assertSame(['note' => 'a kvízből'], AnswerSet::fromJson((string) $rows[0]['answers'])->answers);
        self::assertNotNull(DraftClaim::handoff());
        $this->landing(new GuestActor());
        self::assertNotNull(DraftClaim::current());
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
        $this->landing(new GuestActor());

        $html = $this->render();
        self::assertMatchesRegularExpression('#name="size"\s+value="m"[^>]* checked#', $html);
        self::assertDoesNotMatchRegularExpression('#name="(name|email|note|telefon)"[^>]* value="[^"]+"#', $html);
        self::assertStringNotContainsString('aria-invalid', $html);
        self::assertSame(
            0,
            preg_match('#<span id="kalaka-field-[a-z_]+-error"[^>]*>[^<]+</span>#', $html),
            'An error element of the opened form holds text.'
        );
        // The account block's own error line is not a field's.
        $fields = (string) preg_replace('#<p id="kalaka-account-error"[^>]*></p>#', '', $html);
        self::assertSame(
            substr_count($fields, '-error"'),
            preg_match_all('#<span id="kalaka-field-[a-z_]+-error"[^>]* hidden></span>#', $fields),
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
        $this->landing(new GuestActor());
        $html = $this->render();
        self::assertMatchesRegularExpression('#name="meret\[\]"\s+value="s"[^>]* checked#', $html);
        self::assertMatchesRegularExpression('#name="meret\[\]"\s+value="l"[^>]* checked#', $html);
        self::assertDoesNotMatchRegularExpression('#name="meret\[\]"\s+value="m"[^>]* checked#', $html);
    }

    /**
     * A value its type's prefill makes nothing of is dropped, even one the type's submit check would accept;
     * a consent's stored form, `1`, from a link is a ticked box.
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
            self::assertSame(['hozzajarul' => true], (new Forms())->linkAnswers($definition, $sent)->answers);
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
        $this->landing(new AccountActor($user));

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

    public function testASaveForAnAdditionalFormIsNotFoundAndKeepsNothing(): void
    {
        $additional = $this->insertForm(
            $this->field('text', 'note'),
            ['kalaka_event_id' => $this->event, 'kalaka_placement' => 'additional']
        );
        $form = (new Forms())->form($additional);
        self::assertNotNull($form);

        try {
            $actor = new AccountActor($this->logIn());
            (new EventFormHost())->saveDraft($form, $form->version, new AnswerSet(['note' => 'x']), 0, 0, $actor);
            self::fail('The save was accepted.');
        } catch (NotFound) {
            self::assertSame([], $this->draftRows());
        }
    }

    public function testTheAutosaveRouteRefusesATooLargeBodyFromAGuestToo(): void
    {
        $large = $this->post('draft', [
            'version' => $this->version(),
            'answers' => ['note' => str_repeat('x', FormRoutes::DRAFT_LIMIT)],
            'page' => 0,
            'claims' => 0,
        ]);
        self::assertSame(400, $large->get_status());
        self::assertSame([], $this->draftRows());

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
        self::assertNotNull(DraftClaim::handoff());
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
        // The popup's texts, word for word (title and intro: stand-ins T1100, T1101), the count in merge's line.
        self::assertSame([
            'title' => 'Bejelentkezés előtti válaszok',
            'intro' => 'Ehhez az űrlaphoz már vannak mentett, még be nem küldött válaszaid. Ezen az eszközön '
                . 'bejelentkezés előtt is érkeztek válaszok: amit beírtál, vagy egy előre kitöltött linkből jött. '
                . 'Hogyan folytassuk?',
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
        $this->landing(new AccountActor($user));
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
        $this->landing(new AccountActor($user));

        $context = $this->context($this->render());
        self::assertSame(3, $context['draftClaimChanges']);
        self::assertStringContainsString(': 3 kérdésnél változik', $context['draftClaimTexts']['mergeLine']);
    }

    public function testTheLoadAfterAChoiceCarriesItOnceForTheNotice(): void
    {
        $user = $this->logIn();
        $this->autosave(['name' => 'Teszt Anna', 'note' => 'saját']);
        (new DraftHandoff())->handle($this->page(), ['note' => 'a kvízből'], new AccountActor($user));
        $this->landing(new AccountActor($user));
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

    /**
     * A guest's typing, saved into their guest draft under the draft cookie; then the quiz's handoff of `$link`,
     * which as a cross-site POST carries no `Lax` cookie: the draft cookie is taken away for that request and
     * given back for the 303's load (N1106). Returns the guest's own token.
     *
     * @param array<string, mixed> $typed
     * @param array<string, mixed> $link
     */
    private function guestTypesThenAHandoffComes(array $typed, array $link): string
    {
        self::assertSame(200, $this->autosave($typed)->get_status());
        $own = DraftClaim::current();
        self::assertNotNull($own);

        unset($_COOKIE[DraftClaim::COOKIE]);
        self::assertTrue((new DraftHandoff())->handle($this->page(), $link, new GuestActor()));
        $_COOKIE[DraftClaim::COOKIE] = $own;
        $this->landing(new GuestActor());

        return $own;
    }

    /** N1106: the quiz's handoff onto a guest draft with typing that it would change asks, with the claim popup. */
    public function testAHandoffThatChangesAGuestsTypedAnswersWaitsAndThePageCarriesTheClaimRoute(): void
    {
        $own = $this->guestTypesThenAHandoffComes(
            ['name' => 'Gépelt Anna', 'note' => 'gépelt'],
            ['note' => 'a kvízből', 'size' => 'm']
        );

        // Both drafts stay, both cookies stay, and nothing of the guest's draft is overwritten.
        self::assertCount(2, $this->draftRows());
        self::assertSame($own, DraftClaim::current());
        self::assertNotNull(DraftClaim::handoff());
        $context = $this->context($this->render());
        self::assertStringEndsWith('/forms/' . $this->form . '/draft/claim', (string) $context['draftClaimUrl']);
        self::assertSame(2, $context['draftClaimChanges']);
        self::assertSame(0, $context['draftClaims']);
        self::assertArrayHasKey('draftUrl', $context);
        self::assertArrayNotHasKey('draftClaimNotice', $context);
        // The guest's own draft opens, not the link's.
        self::assertStringContainsString('gépelt', $this->render());
        // A guest's popup says nothing of a login (T1102, T1103: stand-ins); the other texts are the popup's.
        self::assertSame('Mentett és bejövő válaszok', $context['draftClaimTexts']['title']);
        self::assertStringNotContainsString('bejelentkezés', $context['draftClaimTexts']['intro']);
        self::assertSame('Egyesítés', $context['draftClaimTexts']['merge']);
        self::assertSame(DraftClaimTexts::popup(2, true), $context['draftClaimTexts']);
        self::assertTrue(wp_script_is(Module::CLAIM_SCRIPT, 'enqueued'));
    }

    /** N1106: when the link changes nothing in the guest's draft, it settles silently: no popup, the draft is kept. */
    public function testAHandoffThatChangesNothingInAGuestsTypedAnswersSettlesSilently(): void
    {
        $own = $this->guestTypesThenAHandoffComes(
            ['name' => 'Gépelt Anna', 'note' => 'ugyanaz'],
            ['note' => 'ugyanaz']
        );

        self::assertSame($own, DraftClaim::current());
        self::assertNull(DraftClaim::handoff());
        self::assertSame(['name' => 'Gépelt Anna', 'note' => 'ugyanaz'], $this->storedAnswers());
        $context = $this->context($this->render());
        self::assertArrayNotHasKey('draftClaimUrl', $context);
        self::assertArrayNotHasKey('draftClaimNotice', $context);
        self::assertSame(0, $context['draftClaims']);
    }

    /** N1106: with no typing yet, the handoff is simply the guest's draft, as before. */
    public function testAHandoffWithNothingTypedBecomesTheGuestsDraftWithNoChoice(): void
    {
        self::assertTrue((new DraftHandoff())->handle($this->page(), ['note' => 'a kvízből'], new GuestActor()));
        $this->landing(new GuestActor());

        self::assertNull(DraftClaim::handoff());
        self::assertNotNull(DraftClaim::current());
        self::assertSame(['note' => 'a kvízből'], $this->storedAnswers());
        self::assertArrayNotHasKey('draftClaimUrl', $this->context($this->render()));
    }

    /**
     * @return iterable<string, array{string, array<string, string>, int}> the choice, the draft after it, and
     *     the status of a save from the page before it
     */
    public static function guestChoices(): iterable
    {
        yield 'merge' => ['merge', ['name' => 'Gépelt Anna', 'note' => 'a kvízből', 'size' => 'm'], 409];
        yield 'keep the draft' => ['keep_draft', ['name' => 'Gépelt Anna', 'note' => 'gépelt'], 200];
        yield 'keep the link' => ['keep_link', ['note' => 'a kvízből', 'size' => 'm'], 409];
    }

    /**
     * @dataProvider guestChoices
     * @param array<string, string> $expected
     */
    public function testEachChoiceOfAGuestSettlesOnTheirOwnDraftAndTheLoadAfterItCarriesTheNoticeOnce(
        string $choice,
        array $expected,
        int $staleStatus
    ): void {
        $own = $this->guestTypesThenAHandoffComes(
            ['name' => 'Gépelt Anna', 'note' => 'gépelt'],
            ['note' => 'a kvízből', 'size' => 'm']
        );
        $before = $this->context($this->render());

        $response = $this->post('draft/claim', ['choice' => $choice]);

        self::assertSame(200, $response->get_status());
        self::assertSame(['resolved' => true], $response->get_data());
        $rows = $this->draftRows();
        self::assertCount(1, $rows);
        self::assertSame(DraftClaim::hash($own), $rows[0]['claim_hash']);
        self::assertNull($rows[0]['user_id']);
        $answers = $this->storedAnswers();
        ksort($answers);
        ksort($expected);
        self::assertSame($expected, $answers);

        // A save from the page before the choice: refused when the choice changed the draft, as for a person.
        $stale = $this->autosave(['note' => 'régi lap'], $before['draftClaims']);
        self::assertSame($staleStatus, $stale->get_status());
        if ($staleStatus === 409) {
            self::assertSame('kalaka_version_conflict', $stale->get_data()['code']);
            self::assertSame($expected, $answers);
        }

        // The repeat answers the same, another choice is refused.
        self::assertSame(200, $this->post('draft/claim', ['choice' => $choice])->get_status());
        $other = $this->post('draft/claim', ['choice' => $choice === 'merge' ? 'keep_link' : 'merge']);
        self::assertSame(409, $other->get_status());
        self::assertSame(self::REFUSED, $other->get_data()['message']);

        // The load after the choice: the handoff cookie is cleared, the guest keeps their own, and the page
        // carries the choice once.
        $this->landing(new GuestActor());
        self::assertNull(DraftClaim::handoff());
        self::assertSame($own, DraftClaim::current());
        $context = $this->context($this->render());
        self::assertSame($choice, $context['draftClaimNotice']);
        self::assertArrayNotHasKey('draftClaimUrl', $context);
        self::assertSame($staleStatus === 409 ? 1 : 0, $context['draftClaims']);
        self::assertSame(200, $this->autosave(['note' => 'új'], $context['draftClaims'])->get_status());

        DraftHandoff::forget();
        $this->landing(new GuestActor());
        self::assertArrayNotHasKey('draftClaimNotice', $this->context($this->render()));
    }

    /** A guest's claim route needs both cookies: the draft's and the handoff's. */
    public function testTheClaimRouteRefusesAGuestWithoutBothCookies(): void
    {
        $this->guestTypesThenAHandoffComes(['name' => 'Gépelt Anna', 'note' => 'gépelt'], ['note' => 'a kvízből']);
        $before = $this->draftRows();

        $handoff = DraftClaim::handoff();
        unset($_COOKIE[DraftClaim::HANDOFF_COOKIE]);
        $refused = $this->post('draft/claim', ['choice' => 'keep_link']);
        self::assertSame(409, $refused->get_status());
        self::assertSame('kalaka_draft_claim_refused', $refused->get_data()['code']);

        $_COOKIE[DraftClaim::HANDOFF_COOKIE] = (string) $handoff;
        $own = DraftClaim::current();
        unset($_COOKIE[DraftClaim::COOKIE]);
        self::assertSame(409, $this->post('draft/claim', ['choice' => 'keep_link'])->get_status());
        $_COOKIE[DraftClaim::COOKIE] = (string) $own;

        self::assertSame($before, $this->draftRows());
    }

    /** A second handoff while the first waits replaces it: one browser holds one. */
    public function testASecondHandoffReplacesTheWaitingOne(): void
    {
        $this->guestTypesThenAHandoffComes(['name' => 'Gépelt Anna', 'note' => 'gépelt'], ['note' => 'első']);
        $first = DraftClaim::handoff();

        (new DraftHandoff())->handle($this->page(), ['note' => 'második'], new GuestActor());

        self::assertCount(2, $this->draftRows());
        self::assertNotSame($first, DraftClaim::handoff());
        $this->landing(new GuestActor());
        self::assertSame(1, $this->context($this->render())['draftClaimChanges']);
    }

    public function testTheAutosaveRouteNeedsTheClaimCount(): void
    {
        $this->logIn();

        $response = $this->post('draft', ['version' => $this->version(), 'answers' => ['note' => 'x'], 'page' => 0]);

        self::assertSame(400, $response->get_status());
        self::assertSame([], $this->draftRows());
    }
}
