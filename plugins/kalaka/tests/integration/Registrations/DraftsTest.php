<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Registrations;

use Kalaka\Events\ArchiveEvent;
use Kalaka\Events\UnpublishEvent;
use Kalaka\Forms\Api\AnswerSet;
use Kalaka\Forms\Api\DraftClaimChoice;
use Kalaka\Foundation\Api\Actor\AccountActor;
use Kalaka\Foundation\Api\Actor\Actor;
use Kalaka\Foundation\Api\Actor\GuestActor;
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
 * S74: drafts. The autosave keeps a logged-in person's or a guest's answers; a handoff keeps a link's answers as a
 * guest draft under a claim token; a logged-in person claims it, or chooses when it meets their own draft;
 * the submit deletes the draft; the periodic step deletes the drafts of closed (or archived) events; a
 * draft has no lifetime of its own. The audit never holds a draft's answers.
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
        );
    }

    /** After the registration closes (2026-12-15 20:00 Budapest). */
    private function afterClose(): void
    {
        $this->useClock(new TestClock(new \DateTimeImmutable('2026-12-16 08:00:00', new \DateTimeZone('UTC'))));
        Runner::publish($this->runner);
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
        self::assertSame('2', $rows[0]['page']);
        self::assertSame(self::ANSWERS, AnswerSet::fromJson((string) $rows[0]['answers'])->answers);

        $opening = $this->drafts->opening($this->event, $this->form, $anna->userId, null);
        self::assertSame(self::ANSWERS, $opening->answers->answers);
        self::assertTrue($opening->autosave);
        self::assertFalse($opening->claimConflict);
    }

    /** Owner ruling 2026-11-26: a guest's save is the guest draft the handoff writes: created, then updated. */
    public function testAGuestsSaveCreatesTheGuestDraftAndALaterOneUpdatesIt(): void
    {
        $token = $this->drafts->save(
            $this->event,
            $this->form,
            $this->version(),
            new AnswerSet(['name' => 'Teszt Anna']),
            0,
            0,
            new GuestActor()
        );
        self::assertNotNull($token);
        self::assertTrue($this->drafts->holdsOf($this->form, $token));

        $again = $this->drafts->save(
            $this->event,
            $this->form,
            $this->version(),
            new AnswerSet(['name' => 'Teszt Anna', 'size' => 'm']),
            0,
            0,
            new GuestActor(),
            $token
        );

        self::assertSame($token, $again);
        $rows = $this->draftRows();
        self::assertCount(1, $rows);
        self::assertNull($rows[0]['user_id']);
        self::assertSame(DraftClaim::hash($token), $rows[0]['claim_hash']);
        $opening = $this->drafts->opening($this->event, $this->form, null, $token);
        self::assertSame(['name' => 'Teszt Anna', 'size' => 'm'], $opening->answers->answers);
        self::assertTrue($opening->autosave);
        self::assertFalse($opening->guestStart);
        self::assertTrue($this->drafts->opening($this->event, $this->form, null, null)->guestStart);
        $entry = $this->entries()[array_key_last($this->entries())];
        self::assertStringNotContainsString('Teszt Anna', (string) $entry['changes']);
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

    public function testAHandoffKeepsAGuestDraftUnderTheTokensHash(): void
    {
        $token = $this->handOver(['note' => 'a kvízből']);

        self::assertTrue(DraftClaim::isValid($token));
        $rows = $this->draftRows();
        self::assertCount(1, $rows);
        self::assertNull($rows[0]['user_id']);
        self::assertSame(DraftClaim::hash($token), $rows[0]['claim_hash']);

        $opening = $this->drafts->opening($this->event, $this->form, null, $token);
        self::assertSame(['note' => 'a kvízből'], $opening->answers->answers);
        self::assertTrue($opening->autosave);
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

    /** A guest's typed draft: returns its claim token. @param array<string, mixed> $answers */
    private function typed(array $answers, int $claims = 0, ?int $form = null): string
    {
        $form ??= $this->form;
        $token = $this->drafts->save(
            $this->event,
            $form,
            $this->readForm($form)->version,
            new AnswerSet($answers),
            2,
            $claims,
            new GuestActor()
        );
        self::assertNotNull($token);

        return $token;
    }

    /** More typing into the guest draft under the token, from a page that opened with that claim count. */
    private function typeMore(string $token, array $answers, int $claims): void
    {
        $this->drafts->save(
            $this->event,
            $this->form,
            $this->version(),
            new AnswerSet($answers),
            0,
            $claims,
            new GuestActor(),
            $token
        );
    }

    private function settle(string $handoff, ?string $draft, ?Actor $actor = null, ?int $form = null): DraftClaimOutcome
    {
        return $this->drafts->settleHandoff($form ?? $this->form, $handoff, $draft, $actor ?? new GuestActor());
    }

    /** @return array<string, ?string> the row of the draft under the token */
    private function rowOf(string $token): array
    {
        foreach ($this->draftRows() as $row) {
            if ($row['claim_hash'] === DraftClaim::hash($token)) {
                return $row;
            }
        }
        self::fail('No draft under that token.');
    }

    /** N1106: the handoff, the quiz's cross-site POST included, never reads or changes the draft the browser holds. */
    public function testAHandoffLeavesTheGuestsTypedDraftAloneAndWaitsAsItsOwnDraft(): void
    {
        $typed = $this->typed(['name' => 'Gépelt Anna', 'note' => 'gépelt']);
        $before = $this->rowOf($typed);

        $handoff = $this->handOver(['note' => 'linkből', 'email' => 'link@example.org']);

        self::assertCount(2, $this->draftRows());
        self::assertSame($before, $this->rowOf($typed));
        self::assertSame(
            ['note' => 'linkből', 'email' => 'link@example.org'],
            AnswerSet::fromJson((string) $this->rowOf($handoff)['answers'])->answers
        );
    }

    public function testAHandoffWithNothingTypedBecomesTheBrowsersDraft(): void
    {
        $handoff = $this->handOver(['note' => 'linkből']);

        self::assertSame(DraftClaimOutcome::Claimed, $this->settle($handoff, null));
        self::assertCount(1, $this->draftRows());
        $opening = $this->drafts->opening($this->event, $this->form, null, $handoff);
        self::assertSame(['note' => 'linkből'], $opening->answers->answers);
    }

    public function testAHandoffMeetingADraftWithNoAnswersOrOfAnotherFormGivesTheEarlierDraftWay(): void
    {
        $empty = $this->typed([]);
        $handoff = $this->handOver(['note' => 'linkből']);
        self::assertSame(DraftClaimOutcome::Claimed, $this->settle($handoff, $empty));
        self::assertCount(1, $this->draftRows());
        self::assertSame(DraftClaim::hash($handoff), $this->draftRows()[0]['claim_hash']);

        $other = $this->insertForm($this->slotFields(), ['kalaka_event_id' => $this->event]);
        $elsewhere = $this->typed(['name' => 'x'], 0, $other);
        $second = $this->handOver(['note' => 'újabb']);
        self::assertSame(DraftClaimOutcome::Claimed, $this->settle($second, $elsewhere));
        self::assertFalse($this->drafts->holds($elsewhere));
    }

    public function testAHandoffThatChangesNothingInTheTypedDraftSettlesSilentlyAsKeepDraft(): void
    {
        $typed = $this->typed(['name' => 'Gépelt Anna', 'note' => 'ugyanaz']);
        $handoff = $this->handOver(['note' => 'ugyanaz']);

        self::assertSame(DraftClaimOutcome::Settled, $this->settle($handoff, $typed));

        $rows = $this->draftRows();
        self::assertCount(1, $rows);
        self::assertSame(DraftClaim::hash($typed), $rows[0]['claim_hash']);
        self::assertSame(['name' => 'Gépelt Anna', 'note' => 'ugyanaz'], $this->onlyAnswers());
        self::assertSame('0', $rows[0]['claims']);
        self::assertSame(DraftClaimChoice::KeepDraft->value, $rows[0]['resolved_choice']);
        self::assertSame(
            DraftClaimChoice::KeepDraft,
            $this->drafts->settledGuestChoice($this->form, $typed, $handoff)
        );
    }

    public function testAHandoffThatChangesAValueWaitsForTheGuestsChoiceAndWritesNothing(): void
    {
        $typed = $this->typed(['name' => 'Gépelt Anna', 'note' => 'gépelt']);
        $handoff = $this->handOver(['note' => 'linkből', 'name' => 'Gépelt Anna']);
        $before = $this->draftRows();

        self::assertSame(DraftClaimOutcome::Conflict, $this->settle($handoff, $typed));

        self::assertSame($before, $this->draftRows());
        $opening = $this->drafts->opening($this->event, $this->form, null, $typed, $handoff);
        self::assertTrue($opening->claimConflict);
        self::assertTrue($opening->guestChoice);
        self::assertSame(1, $opening->linkChanges);
        self::assertSame(0, $opening->claims);
        self::assertSame(['name' => 'Gépelt Anna', 'note' => 'gépelt'], $opening->answers->answers);
        self::assertFalse($this->drafts->opening($this->event, $this->form, null, $typed)->claimConflict);
    }

    /** @return iterable<string, array{DraftClaimChoice, array<string, string>, int}> */
    public static function guestChoices(): iterable
    {
        yield 'merge: the link wins where both answer, the rest is kept' => [
            DraftClaimChoice::Merge,
            ['name' => 'Gépelt Anna', 'note' => 'linkből', 'email' => 'link@example.org'],
            1,
        ];
        yield 'keep the draft' => [DraftClaimChoice::KeepDraft, ['name' => 'Gépelt Anna', 'note' => 'gépelt'], 0];
        yield 'keep the link' => [DraftClaimChoice::KeepLink, ['note' => 'linkből', 'email' => 'link@example.org'], 1];
    }

    /**
     * @dataProvider guestChoices
     * @param array<string, string> $expected
     */
    public function testTheGuestsChoiceSettlesOnTheirOwnDraftWhichKeepsItsTokenAndDropsTheHandoff(
        DraftClaimChoice $choice,
        array $expected,
        int $claims
    ): void {
        $typed = $this->typed(['name' => 'Gépelt Anna', 'note' => 'gépelt']);
        $handoff = $this->handOver(['note' => 'linkből', 'email' => 'link@example.org']);
        self::assertSame(DraftClaimOutcome::Conflict, $this->settle($handoff, $typed));

        $this->drafts->resolveGuest($this->form, $typed, $handoff, $choice, new GuestActor());

        $rows = $this->draftRows();
        self::assertCount(1, $rows);
        self::assertSame(DraftClaim::hash($typed), $rows[0]['claim_hash']);
        self::assertNull($rows[0]['user_id']);
        self::assertSame((string) $claims, $rows[0]['claims']);
        self::assertSame($choice->value, $rows[0]['resolved_choice']);
        $answers = AnswerSet::fromJson((string) $rows[0]['answers'])->answers;
        ksort($answers);
        ksort($expected);
        self::assertSame($expected, $answers);
        self::assertSame($choice, $this->drafts->settledGuestChoice($this->form, $typed, $handoff));
        self::assertFalse($this->drafts->opening($this->event, $this->form, null, $typed, $handoff)->claimConflict);
        self::assertSame(DraftClaimOutcome::Gone, $this->settle($handoff, $typed));
    }

    public function testASaveFromTheGuestsPageBeforeTheirChoiceIsRefusedAndTheChoiceKept(): void
    {
        $typed = $this->typed(['note' => 'gépelt']);
        $handoff = $this->handOver(['note' => 'linkből']);
        $this->drafts->resolveGuest($this->form, $typed, $handoff, DraftClaimChoice::KeepLink, new GuestActor());

        try {
            $this->typeMore($typed, ['note' => 'régi lap'], 0);
            self::fail('A save from before the choice went in.');
        } catch (VersionConflict) {
            // Refused.
        }
        self::assertSame(['note' => 'linkből'], $this->onlyAnswers());

        $this->typeMore($typed, ['note' => 'új'], 1);
        self::assertSame(['note' => 'új'], $this->onlyAnswers());
    }

    public function testAKeepDraftChoiceKeepsTheCountSoTheGuestsOpenPageStillSaves(): void
    {
        $typed = $this->typed(['note' => 'gépelt']);
        $handoff = $this->handOver(['note' => 'linkből']);
        $this->drafts->resolveGuest($this->form, $typed, $handoff, DraftClaimChoice::KeepDraft, new GuestActor());

        $this->typeMore($typed, ['note' => 'tovább gépelt'], 0);

        self::assertSame(['note' => 'tovább gépelt'], $this->onlyAnswers());
    }

    public function testTheGuestsSameChoiceAgainAnswersTheSameAndAnotherIsRefused(): void
    {
        $typed = $this->typed(['note' => 'gépelt']);
        $handoff = $this->handOver(['note' => 'linkből']);
        $this->drafts->resolveGuest($this->form, $typed, $handoff, DraftClaimChoice::Merge, new GuestActor());
        $after = $this->draftRows();

        $this->drafts->resolveGuest($this->form, $typed, $handoff, DraftClaimChoice::Merge, new GuestActor());
        self::assertSame($after, $this->draftRows());

        $this->expectException(DraftClaimRefused::class);
        $this->drafts->resolveGuest($this->form, $typed, $handoff, DraftClaimChoice::KeepLink, new GuestActor());
    }

    public function testAGuestsChoiceOnAHandoffTheSweepDeletedOrOfAnotherFormIsRefusedAndChangesNothing(): void
    {
        $typed = $this->typed(['note' => 'gépelt']);
        $handoff = $this->handOver(['note' => 'linkből']);
        $other = $this->insertForm($this->slotFields(), ['kalaka_event_id' => $this->event]);
        $before = $this->draftRows();

        foreach ([[$this->form, 'nincs-ilyen-token-1234'], [$other, $handoff]] as [$form, $token]) {
            try {
                $this->drafts->resolveGuest($form, $typed, $token, DraftClaimChoice::Merge, new GuestActor());
                self::fail('The choice was applied.');
            } catch (DraftClaimRefused) {
                // Refused.
            }
        }
        self::assertSame($before, $this->draftRows());
    }

    public function testAHandoffOfAnotherFormStaysForThatFormAndASweptOneIsGone(): void
    {
        $other = $this->insertForm($this->slotFields(), ['kalaka_event_id' => $this->event]);
        $handoff = $this->handOver(['note' => 'linkből']);

        self::assertSame(DraftClaimOutcome::OtherForm, $this->settle($handoff, null, null, $other));

        $this->afterClose();
        (new DraftSweep($this->runner))->run();
        self::assertSame(DraftClaimOutcome::Gone, (new Drafts($this->runner))->settleHandoff(
            $this->form,
            $handoff,
            null,
            new GuestActor()
        ));
    }

    public function testALoggedInPersonsHandoffWithNoClaimWaitingBecomesTheBrowsersDraftToClaim(): void
    {
        $anna = $this->person();
        $this->save($anna, self::ANSWERS + ['note' => 'saját']);
        $handoff = $this->handOver(['note' => 'a kvízből']);

        self::assertSame(DraftClaimOutcome::Claimed, $this->settle($handoff, null, $anna));
        self::assertSame(DraftClaimOutcome::Conflict, $this->drafts->claim($this->form, $handoff, $anna));
    }

    public function testAHandoffWithNoEarlierDraftKeepsOnlyTheLinksAnswers(): void
    {
        $token = $this->handOver(['note' => 'linkből']);

        $rows = $this->draftRows();
        self::assertCount(1, $rows);
        self::assertSame(DraftClaim::hash($token), $rows[0]['claim_hash']);
        self::assertSame(['note' => 'linkből'], AnswerSet::fromJson((string) $rows[0]['answers'])->answers);
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

    public function testAChoiceOnAGuestDraftTheSweepDeletedIsRefused(): void
    {
        $anna = $this->person();
        $token = $this->conflict($anna);
        $this->afterClose();
        (new DraftSweep($this->runner))->run();

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

    public function testAGuestDraftOfAnotherFormOrOneTheSweepDeletedIsNotClaimed(): void
    {
        $anna = $this->person();
        $token = $this->handOver(['note' => 'x']);
        $other = $this->insertForm($this->slotFields(), ['kalaka_event_id' => $this->published()]);

        self::assertSame(DraftClaimOutcome::OtherForm, $this->drafts->claim($other, $token, $anna));

        $this->afterClose();
        (new DraftSweep($this->runner))->run();
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
            new SubmitRequest(
                $this->form,
                $this->version(),
                self::ANSWERS,
                SubmitToken::generate(),
                [],
                false,
                draftClaim: $token
            ),
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
            new SubmitRequest(
                $this->form,
                $this->version(),
                self::ANSWERS,
                SubmitToken::generate(),
                [],
                false,
                draftClaim: $token
            ),
            new GuestActor()
        );

        $rows = $this->draftRows();
        self::assertCount(1, $rows);
        self::assertSame((string) $anna->userId, $rows[0]['user_id']);
    }

    public function testTheSweepDeletesTheDraftsOfAnEventWhoseRegistrationClosed(): void
    {
        $this->save($this->person(), self::ANSWERS);

        $this->afterClose();
        (new DraftSweep($this->runner))->run();

        self::assertSame([], $this->draftRows());
    }

    public function testAGuestDraftLivesPastSevenDaysUntilTheRegistrationClosesAndTheSweepThenDeletesIt(): void
    {
        $token = $this->handOver(['note' => 'a kvízből']);

        // Handed over 2026-11-30; 15 days later the registration is still open (it closes 2026-12-15 20:00 Budapest).
        $this->useClock(new TestClock(new \DateTimeImmutable('2026-12-15 18:00:00', new \DateTimeZone('UTC'))));
        Runner::publish($this->runner);
        (new DraftSweep($this->runner))->run();
        self::assertTrue($this->drafts->holds($token));
        $opening = $this->drafts->opening($this->event, $this->form, null, $token);
        self::assertSame(['note' => 'a kvízből'], $opening->answers->answers);

        $this->afterClose();
        (new DraftSweep($this->runner))->run();
        self::assertSame([], $this->draftRows());
        self::assertFalse($this->drafts->holds($token));
    }

    public function testTheSweepDeletesTheGuestDraftsOfAnEventWhoseAccountModeIsOffAfterTheClose(): void
    {
        $off = $this->published();
        $offForm = $this->insertForm($this->slotFields() . $this->field('text', 'note'), ['kalaka_event_id' => $off]);
        $version = $this->readForm($offForm)->version;
        $answers = new AnswerSet(['note' => 'x']);
        $token = $this->drafts->handOver($off, $offForm, $version, $answers, null, new GuestActor());
        self::assertTrue($this->drafts->holds($token));

        $this->afterClose();
        (new DraftSweep($this->runner))->run();

        self::assertFalse($this->drafts->holds($token));
    }

    public function testTheSweepKeepsOpenAndUnpublishedDraftsAndDeletesArchivedOnes(): void
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
        self::assertCount(2, $rows, 'the open event keeps its user draft and its guest draft');
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
