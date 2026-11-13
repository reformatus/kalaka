<?php

declare(strict_types=1);

namespace Kalaka\Forms;

/** What a walk of a form over its answers found; read by `AnswerValidator` and `Api\Forms::availability()`. */
final readonly class FormWalkResult
{
    /**
     * @param array<string, bool> $shown field key → whether the field shows, every field in document order
     * @param list<int|null> $held chain number → the index of the branch that holds, null when none shows
     * @param array<string, list<string>> $options a choice field with option conditions → its available
     *     option values, in authored order; none while the field does not show
     * @param array<string, mixed> $submitted field key → the answer as sent, for the valid answers
     * @param array<string, string> $stored field key → the value to store, for the valid answers
     * @param array<string, string> $errors field key → the message, for the shown fields in error
     * @param list<bool> $pages page number → whether the page shows; none without pages
     */
    public function __construct(
        public array $shown,
        public array $held,
        public array $options,
        public array $submitted,
        public array $stored,
        public array $errors,
        public array $pages = [],
    ) {
    }
}
