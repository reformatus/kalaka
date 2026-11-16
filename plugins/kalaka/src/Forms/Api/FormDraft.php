<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

/**
 * What a form opens with besides its host's stored values (S74): the answers kept for the person
 * (`$answers`, as the controls send them; the engine keeps only those the form's types accept), whether
 * the page saves the person's answers as they go (`$autosave`, a logged-in person's draft), and whether
 * a link's answers wait for the person's choice against their own draft (`$claimConflict`: only when they
 * set a value the draft does not hold), and the draft's claim count (`$claims`), which the autosave carries
 * back so that a save from before a claim is refused.
 */
final readonly class FormDraft
{
    public function __construct(
        public AnswerSet $answers = new AnswerSet(),
        public bool $autosave = false,
        public bool $claimConflict = false,
        public int $claims = 0,
    ) {
    }
}
