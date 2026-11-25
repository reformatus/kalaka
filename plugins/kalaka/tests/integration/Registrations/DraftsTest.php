<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Registrations;

use Kalaka\Events\ArchiveEvent;
use Kalaka\Events\UnpublishEvent;
use Kalaka\Forms\Api\AnswerSet;
use Kalaka\Forms\Api\DraftClaimChoice;
use Kalaka\Foundation\Api\Actor\AccountActor;
use Kalaka\Foundation\Api\Actor\GuestActor;
use Kalaka\Foundation\Api\Error\PermissionDenied;
use Kalaka\Foundation\Api\Error\VersionConflict;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Registrations\Api\DraftClaim;
use Kalaka\Registrations\Api\DraftClaimOutcome;
use Kalaka\Registrations\Api\DraftClaimRefused;
use Kalaka\Registrations\Api\Drafts;
use Kalaka\Registrations\Api\RegistrationSubmit;
use Kalaka\Registrations\Api\SubmitRequest;
use Kalaka\Registrations\Api\SubmitToken;
use Kalaka\Registrations\Draft\DraftSweep;
use Kalaka\Tests\Integration\Forms\FormsTestCase;
use Kalaka\Tests\Integration\Foundation\TestClock;

/**
 * S74: drafts. The autosave keeps a logged-in person's answers; a handoff keeps a link's answers as a
 * guest draft under a claim token; a logged-in person claims it, or chooses when it meets their own draft;
 * the submit deletes the draft; the periodic step deletes the drafts of closed events and expired guest
 * drafts. The audit never holds a draft's answers.
 */
final class DraftsTest extends FormsTestCase
{
    private const ANSWERS = ['name' => 'Teszt Anna', 'email' => 'teszt.anna@example.org'];

    private int $event;

    private int $form;

    private Drafts $drafts;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        $this->event = $this->published();
        $this->form = $this->insertForm(
            $this->slotFields() . $this->field('text', 'note'),
            ['kalaka_event_id' => $this->event]
        );
        $this->drafts = new Drafts($this->runner);
    }

    private function person(): AccountActor
    {
        return new AccountActor(self::factory()->user->create());
    }

    private function version(): int
    {
        return $this->readForm($this->form)->version;
    }

    /** @param array<string, mixed> $answers */
    private function save(AccountActor $actor, array $answers, int $page = 0): void
    {
        $this->drafts->save($this->event, $this->form, $this->version(), new AnswerSet($answers), $page, 0, $actor);
    }

    /** A draft of another event's form. */
    private function saveFor(int $event, int $form): void
    {
        $version = $this->readForm($form)->version;
        $answers = new AnswerSet(['name' => 'x']);
        (new Drafts($this->runner))->save($event, $form, $version, $answers, 0, 0, $this->person());
    }

    /** @param array<string, mixed> $answers */
    private function handOver(array $answers, ?string $earlier = null): string
    {
        return $this->drafts->handOver(
            $this->event,
            $this->form,
            $this->version(),
            new AnswerSet($answers),
            $earlier,
            new GuestActor()
        )[0];
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

    /** @return array<string, mixed> the answers of the event's only draft */
    private function onlyAnswers(): array
    {
        $rows = $this->draftRows();
        self::assertCount(1, $rows);

        return AnswerSet::fromJson((string) $rows[0]['answers'])->answers;
    }

    public function testTheAutosaveKeepsOneDraftPerPersonAndFormAndUpdatesIt(): void
    {
        $anna = $this->person();

        $this->save($anna, ['name' => 'Teszt']);
        $this->save($anna, self::ANSWERS, 2);

        $rows = $this->draftRows();
        self::assertCount(1, $rows);
        self::assertSame((string) $anna->userId, $rows[0]['user_id']);
        self::assertNull($rows[0]['claim_hash']);
        self::assertNull($rows[0]['expires_at']);
        self::assertSame('2', $rows[0]['page']);
        self::assertSame(self::ANSWERS, AnswerSet::fromJson((string) $rows[0]['answers'])->answers);

        $opening = $this->drafts->opening($this->event, $this->form, $anna->userId, null);
        self::assertSame(self::ANSWERS, $opening->answers->answers);
        self::assertTrue($opening->autosave);
        self::assertFalse($opening->claimConflict);
    }

    public function testAGuestHasNoAutosave(): void
    {
        $this->expectException(PermissionDenied::class);

        $this->drafts->save(
            $this->event,
            $this->form,
            $this->version(),
            new AnswerSet(['name' => 'x']),
            0,
            0,
            new GuestActor()
        );
    }

    public function testTheAuditNamesTheDraftButNeverItsAnswers(): void
    {
        $this->save($this->person(), self::ANSWERS);

        $entry = $this->entries()[array_key_last($this->entries())];
        self::assertSame('kalaka-portal/save-draft', $entry['action']);
        self::assertStringNotContainsString('Teszt Anna', (string) $entry['changes']);
        self::assertStringContainsString('"masked":true', (string) $entry['changes']);
        self::assertSame(
            ['draft/' . $this->event . '/' . $this->draftRows()[0]['id']],
            $this->subjectsOf((int) $entry['id'])
        );
    }

    public function testAHandoffKeepsAGuestDraftUnderTheTokensHashForSevenDays(): void
    {
        $token = $this->handOver(['note' => 'a kvízből']);

        self::assertTrue(DraftClaim::isValid($token));
        $rows = $this->draftRows();
        self::assertCount(1, $rows);
        self::assertNull($rows[0]['user_id']);
        self::assertSame(DraftClaim::hash($token), $rows[0]['claim_hash']);
        self::assertSame('2026-12-07 08:00:00.000000', $rows[0]['expires_at']);

        $opening = $this->drafts->opening($this->event, $this->form, null, $token);
        self::assertSame(['note' => 'a kvízből'], $opening->answers->answers);
        self::assertFalse($opening->autosave);
    }

    /** @return string the token of a one-shot prefill (N676) */
    private function stash(array $answers): string
    {
        return $this->drafts->stashPrefill(
            $this->event,
            $this->form,
            $this->version(),
            new AnswerSet($answers),
            new GuestActor()
        )[0];
    }

    /** N676: the stash is a row of our table under the token's hash, and one read gives it and deletes it. */
    public function testAPrefillIsKeptUnderTheTokensHashForFiveMinutesAndOneReadDeletesIt(): void
    {
        $token = $this->stash(['note' => 'a kvízből']);

        $rows = $this->draftRows();
        self::assertCount(1, $rows);
        self::assertSame('prefill', $rows[0]['kind']);
        self::assertSame(DraftClaim::hash($token), $rows[0]['claim_hash']);
        self::assertNull($rows[0]['user_id']);
        self::assertSame('2026-11-30 08:05:00.000000', $rows[0]['expires_at']);
        self::assertStringNotContainsString($token, (string) wp_json_encode($rows));

        $taken = $this->drafts->takePrefill($this->form, $token, new GuestActor());
        self::assertNotNull($taken);
        self::assertSame(['note' => 'a kvízből'], $taken->answers);
        self::assertSame([], $this->draftRows());
        self::assertNull($this->drafts->takePrefill($this->form, $token, new GuestActor()));
    }

    public function testAPrefillAfterItsFiveMinutesIsNotGivenAndANeverReadOneIsSwept(): void
    {
        $read = $this->stash(['note' => 'a']);
        $this->stash(['note' => 'b']);
        $this->useClock(new TestClock(new \DateTimeImmutable('2026-11-30 08:06:00', new \DateTimeZone('UTC'))));
        Runner::publish($this->runner);
        $drafts = new Drafts($this->runner);

        self::assertNull($drafts->takePrefill($this->form, $read, new GuestActor()));
        self::assertCount(1, $this->draftRows());

        (new DraftSweep($this->runner))->run();
        self::assertSame([], $this->draftRows());
    }

    public function testAPrefillIsNeverADraftOfAnyListingOrClaim(): void
    {
        $token = $this->stash(['note' => 'a']);
        $person = $this->person();

        $opening = $this->drafts->opening($this->event, $this->form, $person->userId, $token);
        self::assertTrue($opening->answers->isEmpty());
        self::assertFalse($opening->claimConflict);
        self::assertSame(DraftClaimOutcome::Gone, $this->drafts->claim($this->form, $token, $person));
        self::assertTrue($this->drafts->opening($this->event, $this->form, null, $token)->answers->isEmpty());
        self::assertFalse($this->drafts->holds($token));
        self::assertCount(1, $this->draftRows());
        // Another form's page leaves it alone.
        self::assertNull($this->drafts->takePrefill($this->form + 1, $token, new GuestActor()));
        self::assertCount(1, $this->draftRows());
    }

    public function testASecondHandoffReplacesTheBrowsersEarlierGuestDraft(): void
    {
        $first = $this->handOver(['note' => 'első']);
        $second = $this->handOver(['note' => 'második'], $first);

        $rows = $this->draftRows();
        self::assertCount(1, $rows);
        self::assertSame(DraftClaim::hash($second), $rows[0]['claim_hash']);
        self::assertFalse($this->drafts->holds($first));
    }

    public function testALoggedInPersonWithNoDraftClaimsTheGuestDraft(): void
    {
        $anna = $this->person();
        $token = $this->handOver(['note' => 'a kvízből']);

        self::assertSame(DraftClaimOutcome::Claimed, $this->drafts->claim($this->form, $token, $anna));

        $rows = $this->draftRows();
        self::assertCount(1, $rows);
        self::assertSame((string) $anna->userId, $rows[0]['user_id']);
        self::assertNull($rows[0]['claim_hash']);
        self::assertNull($rows[0]['expires_at']);
        self::assertSame(DraftClaimOutcome::Gone, $this->drafts->claim($this->form, $token, $anna));
    }

    public function testAClaimAgainstAnOwnDraftIsAConflictThatChangesNothing(): void
    {
        $anna = $this->person();
        $this->save($anna, self::ANSWERS);
        $token = $this->handOver(['note' => 'a kvízből']);

        self::assertSame(DraftClaimOutcome::Conflict, $this->drafts->claim($this->form, $token, $anna));

        self::assertCount(2, $this->draftRows());
        $opening = $this->drafts->opening($this->event, $this->form, $anna->userId, $token);
        self::assertSame(self::ANSWERS, $opening->answers->answers);
        self::assertTrue($opening->claimConflict);
    }

    public function testAClaimWhoseAnswersTheOwnDraftAlreadyHoldsIsSettledAsKeepDraft(): void
    {
        $anna = $this->person();
        $this->save($anna, self::ANSWERS + ['note' => 'a kvízből']);
        $token = $this->handOver(['note' => 'a kvízből', 'name' => 'Teszt Anna']);

        self::assertSame(DraftClaimOutcome::Settled, $this->drafts->claim($this->form, $token, $anna));

        $rows = $this->draftRows();
        self::assertCount(1, $rows);
        self::assertSame((string) $anna->userId, $rows[0]['user_id']);
        self::assertSame('0', $rows[0]['claims']);
        self::assertFalse($this->drafts->holds($token));
        self::assertFalse($this->drafts->opening($this->event, $this->form, $anna->userId, $token)->claimConflict);
    }

    public function testOnlyTheKeysTheLinkSetsDifferentlyAreItsChanges(): void
    {
        $anna = $this->person();
        $this->save($anna, self::ANSWERS + ['note' => 'saját']);
        $token = $this->handOver(['note' => 'a kvízből', 'name' => 'Teszt Anna']);

        self::assertSame(
            ['note' => 'a kvízből'],
            $this->drafts->linkChanges($this->event, $this->form, $anna->userId, $token)
        );
        self::assertSame([], $this->drafts->linkChanges($this->event, $this->form, $this->person()->userId, $token));
    }

    public function testAClaimThatMakesTheGuestDraftThePersonsRefusesASaveFromBefore(): void
    {
        $anna = $this->person();
        $token = $this->handOver(['note' => 'a kvízből']);
        $this->drafts->claim($this->form, $token, $anna);

        try {
            $this->save($anna, ['note' => 'régi lap']);
            self::fail('A save from before the claim went in.');
        } catch (VersionConflict) {
            // Refused.
        }

        self::assertSame(['note' => 'a kvízből'], $this->onlyAnswers());
        $this->drafts->save($this->event, $this->form, $this->version(), new AnswerSet(['note' => 'új']), 0, 1, $anna);
        self::assertSame(['note' => 'új'], $this->onlyAnswers());
    }

    /** A conflict of Anna's own draft and a link's answer; returns the claim token. */
    private function conflict(AccountActor $anna): string
    {
        $this->save($anna, self::ANSWERS + ['note' => 'saját']);

        return $this->handOver(['note' => 'a kvízből']);
    }

    public function testTheSameChoiceAgainAfterItWorkedAnswersTheSameAndChangesNothing(): void
    {
        $anna = $this->person();
        $token = $this->conflict($anna);
        $this->drafts->resolve($this->event, $this->form, $token, DraftClaimChoice::Merge, $anna);
        $after = $this->draftRows();

        $this->drafts->resolve($this->event, $this->form, $token, DraftClaimChoice::Merge, $anna);

        self::assertSame($after, $this->draftRows());
        self::assertSame('1', $after[0]['claims']);
    }

    public function testAnotherChoiceAfterTheClaimWasSettledIsRefusedAndChangesNothing(): void
    {
        $anna = $this->person();
        $token = $this->conflict($anna);
        $this->drafts->resolve($this->event, $this->form, $token, DraftClaimChoice::KeepDraft, $anna);
        $after = $this->draftRows();

        try {
            $this->drafts->resolve($this->event, $this->form, $token, DraftClaimChoice::KeepLink, $anna);
            self::fail('Another choice was applied.');
        } catch (DraftClaimRefused $refused) {
            self::assertSame('kalaka_draft_claim_refused', $refused->errorCode());
        }

        self::assertSame($after, $this->draftRows());
    }

    public function testAChoiceOnAnExpiredGuestDraftIsRefused(): void
    {
        $anna = $this->person();
        $token = $this->conflict($anna);
        $this->useClock(new TestClock(new \DateTimeImmutable('2026-12-08 08:00:00', new \DateTimeZone('UTC'))));
        Runner::publish($this->runner);

        $this->expectException(DraftClaimRefused::class);
        (new Drafts($this->runner))->resolve($this->event, $this->form, $token, DraftClaimChoice::Merge, $anna);
    }

    public function testAChoiceForAClaimSettledOnTheFormsPageIsAnsweredFromIt(): void
    {
        $anna = $this->person();
        $this->save($anna, self::ANSWERS + ['note' => 'a kvízből']);
        $settled = $this->handOver(['note' => 'a kvízből']);
        $this->drafts->claim($this->form, $settled, $anna);

        // Settled as keep_draft: that choice again is no refusal, another one is.
        $this->drafts->resolve($this->event, $this->form, $settled, DraftClaimChoice::KeepDraft, $anna);
        $this->expectException(DraftClaimRefused::class);
        $this->drafts->resolve($this->event, $this->form, $settled, DraftClaimChoice::Merge, $anna);
    }

    public function testAGuestDraftOfAnotherFormOrAnExpiredOneIsNotClaimed(): void
    {
        $anna = $this->person();
        $token = $this->handOver(['note' => 'x']);
        $other = $this->insertForm($this->slotFields(), ['kalaka_event_id' => $this->published()]);

        self::assertSame(DraftClaimOutcome::OtherForm, $this->drafts->claim($other, $token, $anna));

        $this->useClock(new TestClock(new \DateTimeImmutable('2026-12-08 08:00:00', new \DateTimeZone('UTC'))));
        Runner::publish($this->runner);
        self::assertSame(DraftClaimOutcome::Gone, (new Drafts($this->runner))->claim($this->form, $token, $anna));
    }

    /** @return iterable<string, array{DraftClaimChoice, array<string, string>}> */
    public static function choices(): iterable
    {
        yield 'merge: the link wins where both answer, the rest is kept' => [
            DraftClaimChoice::Merge,
            ['name' => 'Teszt Anna', 'email' => 'teszt.anna@example.org', 'note' => 'a kvízből'],
        ];
        yield 'keep the draft' => [
            DraftClaimChoice::KeepDraft,
            ['name' => 'Teszt Anna', 'email' => 'teszt.anna@example.org', 'note' => 'saját'],
        ];
        yield 'keep the link' => [DraftClaimChoice::KeepLink, ['note' => 'a kvízből']];
    }

    /**
     * @dataProvider choices
     * @param array<string, string> $expected
     */
    public function testTheChoiceSettlesTheConflictAndDeletesTheGuestDraft(
        DraftClaimChoice $choice,
        array $expected
    ): void {
        $anna = $this->person();
        $this->save($anna, self::ANSWERS + ['note' => 'saját']);
        $token = $this->handOver(['note' => 'a kvízből']);

        $this->drafts->resolve($this->event, $this->form, $token, $choice, $anna);

        $rows = $this->draftRows();
        self::assertCount(1, $rows);
        self::assertSame((string) $anna->userId, $rows[0]['user_id']);
        $answers = AnswerSet::fromJson((string) $rows[0]['answers'])->answers;
        ksort($answers);
        ksort($expected);
        self::assertSame($expected, $answers);
    }

    public function testTheSubmitDeletesThePersonsDraftAndTheCookiesGuestDraft(): void
    {
        $anna = $this->person();
        $this->save($anna, self::ANSWERS);
        $token = $this->handOver(['note' => 'a kvízből']);

        (new RegistrationSubmit($this->runner))->submit(
            new SubmitRequest($this->form, $this->version(), self::ANSWERS, SubmitToken::generate(), [], false, $token),
            $anna
        );

        self::assertSame([], $this->draftRows());
    }

    public function testAGuestSubmitDeletesItsGuestDraftOnly(): void
    {
        $anna = $this->person();
        $this->save($anna, self::ANSWERS);
        $token = $this->handOver(['note' => 'a kvízből']);

        (new RegistrationSubmit($this->runner))->submit(
            new SubmitRequest($this->form, $this->version(), self::ANSWERS, SubmitToken::generate(), [], false, $token),
            new GuestActor()
        );

        $rows = $this->draftRows();
        self::assertCount(1, $rows);
        self::assertSame((string) $anna->userId, $rows[0]['user_id']);
    }

    public function testTheSweepDeletesTheDraftsOfAnEventWhoseRegistrationClosed(): void
    {
        $this->save($this->person(), self::ANSWERS);

        // After the registration closes (2026-12-15 20:00 Budapest).
        $this->useClock(new TestClock(new \DateTimeImmutable('2026-12-16 08:00:00', new \DateTimeZone('UTC'))));
        Runner::publish($this->runner);
        (new DraftSweep($this->runner))->run();

        self::assertSame([], $this->draftRows());
    }

    public function testTheSweepKeepsOpenAndUnpublishedDraftsAndDeletesArchivedAndExpiredOnes(): void
    {
        $this->save($this->person(), self::ANSWERS);
        $archived = $this->published();
        $archivedForm = $this->insertForm($this->slotFields(), ['kalaka_event_id' => $archived]);
        $this->saveFor($archived, $archivedForm);
        $this->transition(new UnpublishEvent(), $archived);
        $this->transition(new ArchiveEvent(), $archived);
        $this->handOver(['note' => 'x']);
        $paused = $this->published();
        $pausedForm = $this->insertForm($this->slotFields(), ['kalaka_event_id' => $paused]);
        $this->saveFor($paused, $pausedForm);
        $this->transition(new UnpublishEvent(), $paused);

        $this->useClock(new TestClock(new \DateTimeImmutable('2026-12-08 08:00:00', new \DateTimeZone('UTC'))));
        Runner::publish($this->runner);
        (new DraftSweep($this->runner))->run();

        $rows = $this->draftRows();
        self::assertCount(1, $rows);
        self::assertNotNull($rows[0]['user_id']);
        global $wpdb;
        self::assertSame('0', $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}kalaka_drafts WHERE event_id = %d",
            $archived
        )));
        self::assertSame('1', $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}kalaka_drafts WHERE event_id = %d",
            $paused
        )), 'an unpublished event keeps its drafts');
    }
}
