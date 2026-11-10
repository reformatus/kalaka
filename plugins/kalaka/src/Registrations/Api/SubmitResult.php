<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

/**
 * What a submit tells the page.
 *
 * - A new registration: its key, its status (`registered` or `waitlisted`) and the after-submit content.
 * - A repeat (the same token with the same answers, N6): only that it was a repeat, and the after-submit
 *   content; nothing of the registration (no code, no status), since the sender cannot be told apart from
 *   someone else holding the same page.
 *
 * `afterSubmit` is the HTML that replaces the form after a successful submit (events-and-forms.md, "Forms
 * implementation"): `Forms::afterSubmit()`, empty until the after-submit section is defined. [UNAPPROVED N10]
 */
final readonly class SubmitResult
{
    private function __construct(
        public ?RegistrationKey $registration,
        public ?RegistrationStatus $status,
        public bool $repeated,
        public string $afterSubmit = '',
    ) {
    }

    public static function created(RegistrationKey $registration, RegistrationStatus $status): self
    {
        return new self($registration, $status, false);
    }

    public static function repeat(): self
    {
        return new self(null, null, true);
    }

    /** The same result with the after-submit content. */
    public function withAfterSubmit(string $html): self
    {
        return new self($this->registration, $this->status, $this->repeated, $html);
    }

    /**
     * @return array{repeated: true, after_submit: string}
     *     |array{event: int, code: string, status: string, repeated: false, after_submit: string}
     */
    public function toArray(): array
    {
        if ($this->registration === null || $this->status === null) {
            return ['repeated' => true, 'after_submit' => $this->afterSubmit];
        }

        return [
            'event' => $this->registration->eventId,
            'code' => $this->registration->code,
            'status' => $this->status->value,
            'repeated' => false,
            'after_submit' => $this->afterSubmit,
        ];
    }
}
