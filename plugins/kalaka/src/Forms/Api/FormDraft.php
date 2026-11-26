<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

/**
 * What a form opens with besides its host's stored values (S74): the answers kept for the person
 * (`$answers`, as the controls send them; the engine keeps only those the form's types accept), whether
 * the page saves the person's answers as they go (`$autosave`: a person's draft, or a guest's guest draft), and whether
 * a link's answers wait for the person's choice against their own draft (`$claimConflict`: only when they
 * set a value the draft does not hold), and the draft's claim count (`$claims`), which the autosave carries
 * back so that a save from before a claim is refused.
 *
 * For the claim popup (S74, pass 2): how many fields the link's answers would change in the draft
 * (`$linkChanges`, while `$claimConflict`), and the choice that settled a claim on the request before this
 * one (`$claimNotice`), shown once on the page that loads after the choice. [UNAPPROVED N12]
 *
 * `$guestStart` (owner ruling 2026-11-26): a guest's page that holds no guest draft yet, so that the first
 * answer is saved at once and gets the cookie; a guest's page that holds one is saved on the usual rest.
 */
final readonly class FormDraft
{
    public function __construct(
        public AnswerSet $answers = new AnswerSet(),
        public bool $autosave = false,
        public bool $claimConflict = false,
        public int $claims = 0,
        public int $linkChanges = 0,
        public ?DraftClaimChoice $claimNotice = null,
        public bool $guestStart = false,
    ) {
    }
}
