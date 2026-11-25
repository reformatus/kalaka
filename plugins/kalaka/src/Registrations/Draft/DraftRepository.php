<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Draft;

use Kalaka\Forms\Api\AnswerSet;
use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Repository\EntityRepository;
use Kalaka\Foundation\Api\Time\Instant;
use Kalaka\Foundation\Api\UseCase\Context;

/**
 * The only writer of `kalaka_drafts` (S74). A draft is the audit subject `draft` (event, ID). Its answers
 * and its claim hashes (`claim_hash`, `resolved_claim`) are secret columns: the audit records that they
 * changed, never their values, so no answer a link or the quiz handed over reaches the audit. [UNAPPROVED N4]
 *
 * The lookups lock with `FOR UPDATE` by their own condition (`findWhere()`), not by key: a draft is found
 * by its person or its claim hash, never by its ID. Every writer locks a guest draft before the person's
 * own, so a claim and a submit of the same person cannot wait on each other.
 *
 * @extends EntityRepository<array{eventId: int, id: int}, Draft>
 */
final class DraftRepository extends EntityRepository
{
    /** The person's draft of the form, `FOR UPDATE` when a run is given. */
    public function ofUser(int $eventId, int $formId, int $userId, ?Context $forUpdate = null): ?Draft
    {
        return $this->findWhere(
            'event_id = %d AND form_id = %d AND user_id = %d',
            [$eventId, $formId, $userId],
            $forUpdate
        )[0] ?? null;
    }

    /** The guest draft under the claim token's hash, `FOR UPDATE` when a run is given. */
    public function ofClaim(string $claimHash, ?Context $forUpdate = null): ?Draft
    {
        return $this->findWhere('claim_hash = %s AND kind = %s', [$claimHash, Draft::KIND_DRAFT], $forUpdate)[0]
            ?? null;
    }

    /** The one-shot prefill under the token's hash, `FOR UPDATE`; never a draft (N676). */
    public function ofPrefill(string $claimHash, Context $forUpdate): ?Draft
    {
        return $this->findWhere(
            'claim_hash = %s AND kind = %s',
            [$claimHash, Draft::KIND_PREFILL],
            $forUpdate
        )[0] ?? null;
    }

    /** @return list<int> the events that have drafts; a plain read */
    public function events(): array
    {
        $rows = $this->statements()->select(
            $this->statements()->prepare('SELECT DISTINCT event_id FROM %i', [$this->prefixedTable()])
        );

        return array_map(static fn (array $row): int => (int) $row['event_id'], $rows);
    }

    /** @return list<Draft> the event's drafts, `FOR UPDATE` */
    public function ofEvent(int $eventId, Context $forUpdate): array
    {
        return $this->findWhere('event_id = %d', [$eventId], $forUpdate);
    }

    /** @return list<Draft> the guest drafts expired at the moment, `FOR UPDATE` */
    public function expired(\DateTimeImmutable $now, Context $forUpdate): array
    {
        return $this->findWhere('expires_at <= %s', [Instant::toStorage($now)], $forUpdate);
    }

    /**
     * Inserts a new draft unless its event, form and person already hold one (two tabs' first saves).
     *
     * @return ?Draft the stored draft, or null when the key was taken
     */
    public function insertNew(Draft $draft, Context $context): ?Draft
    {
        return $this->insertUnlessDuplicate($draft, $context);
    }

    public static function subjectOf(Draft $draft): Subject
    {
        return new Subject('draft', $draft->eventId, (string) $draft->id);
    }

    protected function table(): string
    {
        return 'kalaka_drafts';
    }

    protected function keyColumns(): array
    {
        return ['event_id', 'id'];
    }

    protected function autoIncrement(): bool
    {
        return true;
    }

    protected function keyToColumns(mixed $key): array
    {
        return ['event_id' => $key['eventId'], 'id' => $key['id']];
    }

    protected function toRow(object $entity): array
    {
        return [
            'event_id' => $entity->eventId,
            'id' => $entity->id,
            'version' => $entity->version,
            'claims' => $entity->claims,
            'kind' => $entity->kind,
            'form_id' => $entity->formId,
            'form_version' => $entity->formVersion,
            'user_id' => $entity->userId,
            'claim_hash' => $entity->claimHash,
            'resolved_claim' => $entity->resolvedClaim,
            'resolved_choice' => $entity->resolvedChoice,
            'answers' => $entity->answers->toJson(),
            'page' => $entity->page,
            'created_at' => Instant::toStorage($entity->createdAt),
            'updated_at' => Instant::toStorage($entity->updatedAt),
            'expires_at' => $entity->expiresAt === null ? null : Instant::toStorage($entity->expiresAt),
        ];
    }

    protected function fromRow(array $row): object
    {
        return new Draft(
            (int) $row['event_id'],
            (int) $row['form_id'],
            (int) $row['form_version'],
            $row['user_id'] === null ? null : (int) $row['user_id'],
            $row['claim_hash'],
            AnswerSet::fromJson((string) $row['answers']),
            (int) $row['page'],
            Instant::fromStorage((string) $row['created_at']),
            Instant::fromStorage((string) $row['updated_at']),
            $row['expires_at'] === null ? null : Instant::fromStorage((string) $row['expires_at']),
            (int) $row['id'],
            (int) $row['version'],
            (int) $row['claims'],
            $row['resolved_claim'],
            $row['resolved_choice'],
            (string) $row['kind'],
        );
    }

    protected function secretColumns(): array
    {
        return ['answers', 'claim_hash', 'resolved_claim'];
    }

    protected function subject(object $record): Subject
    {
        return self::subjectOf($record);
    }
}
