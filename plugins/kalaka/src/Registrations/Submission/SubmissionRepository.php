<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Submission;

use Kalaka\Forms\Api\AnswerSet;
use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Repository\AppendOnlyRepository;
use Kalaka\Foundation\Api\Time\Instant;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * The only writer of `kalaka_submissions`; a submission is inserted once and never changed.
 *
 * @extends AppendOnlyRepository<array{eventId: int, code: string, number: int}, Submission>
 */
final class SubmissionRepository extends AppendOnlyRepository
{
    /**
     * The submission of the event that carries the submit token, by the unique key; a plain read. The
     * token is compared in SQL: unlike the secret link it grants nothing, it only names an earlier submit.
     */
    public function withToken(int $eventId, string $token): ?Submission
    {
        return $this->findWhere('event_id = %d AND submit_token = %s', [$eventId, $token])[0] ?? null;
    }

    protected function table(): string
    {
        return 'kalaka_submissions';
    }

    protected function keyColumns(): array
    {
        return ['event_id', 'code', 'number'];
    }

    protected function autoIncrement(): bool
    {
        return false;
    }

    protected function keyToColumns(mixed $key): array
    {
        return ['event_id' => $key['eventId'], 'code' => $key['code'], 'number' => $key['number']];
    }

    protected function toRow(object $entity): array
    {
        return [
            'event_id' => $entity->registration->eventId,
            'code' => $entity->registration->code,
            'number' => $entity->number,
            'form_id' => $entity->formId,
            'form_version' => $entity->formVersion,
            'answers' => (new AnswerSet($entity->answers))->toJson(),
            'submitted_at' => Instant::toStorage($entity->submittedAt),
            'submit_token' => $entity->token,
            'answers_hash' => $entity->answersHash,
        ];
    }

    protected function fromRow(array $row): object
    {
        return new Submission(
            new RegistrationKey((int) $row['event_id'], (string) $row['code']),
            (int) $row['number'],
            (int) $row['form_id'],
            (int) $row['form_version'],
            AnswerSet::fromJson((string) $row['answers'])->answers,
            Instant::fromStorage((string) $row['submitted_at']),
            $row['submit_token'],
            $row['answers_hash'],
        );
    }

    protected function secretColumns(): array
    {
        return ['submit_token'];
    }

    protected function subject(object $record): Subject
    {
        return Subject::registration($record->registration);
    }

    protected function registrationOf(object $record): RegistrationKey
    {
        return $record->registration;
    }
}
