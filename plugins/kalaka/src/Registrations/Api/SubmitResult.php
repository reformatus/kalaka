<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

/**
 * What a submit tells the page.
 *
 * - A new registration: its key, its status (`registered` or `waitlisted`) and the after-submit content.
 * - A repeat (the same token with the same answers, N6): only that it was a repeat and the after-submit
 *   content, rendered for the original registration (its code and status, N13). `toArray()` sends nothing
 *   else; the key and the status are kept here only to render that content.
 *
 * `afterSubmit` is the HTML that replaces the form after a successful submit: the form's after-submit
 * section (`Forms::afterSubmit()`, #156 N12), or the default text of the status (N14).
 */
final readonly class SubmitResult
{
    private function __construct(
        public RegistrationKey $registration,
        public RegistrationStatus $status,
        public bool $repeated,
        public string $afterSubmit = '',
    ) {
    }

    public static function created(RegistrationKey $registration, RegistrationStatus $status): self
    {
        return new self($registration, $status, false);
    }

    /** A repeat of the submit that created the registration, with its status as it is now. */
    public static function repeat(RegistrationKey $registration, RegistrationStatus $status): self
    {
        return new self($registration, $status, true);
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
        if ($this->repeated) {
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
