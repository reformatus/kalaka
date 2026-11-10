<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Submission;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Error\SystemFailure;
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
            'answers' => self::encode($entity->answers),
            'submitted_at' => Instant::toStorage($entity->submittedAt),
        ];
    }

    protected function fromRow(array $row): object
    {
        $answers = json_decode((string) $row['answers'], true);
        if (!is_array($answers)) {
            $error = new SystemFailure(null, new \UnexpectedValueException('Stored answers are not a JSON object.'));
            throw $error;
        }

        return new Submission(
            new RegistrationKey((int) $row['event_id'], (string) $row['code']),
            (int) $row['number'],
            (int) $row['form_id'],
            (int) $row['form_version'],
            $answers,
            Instant::fromStorage((string) $row['submitted_at']),
        );
    }

    protected function subject(object $record): Subject
    {
        return Subject::registration($record->registration);
    }

    protected function registrationOf(object $record): RegistrationKey
    {
        return $record->registration;
    }

    /**
     * A JSON object, never `wp_json_encode()`, which would replace what it cannot encode.
     *
     * @param array<string, mixed> $answers
     */
    private static function encode(array $answers): string
    {
        try {
            $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

            // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- wp_json_encode() would repair invalid UTF-8
            return json_encode((object) $answers, $flags);
        } catch (\JsonException $e) {
            $error = new SystemFailure(null, $e);
            throw $error;
        }
    }
}
