<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Audit;

use Kalaka\Foundation\Api\Actor\AccountActor;
use Kalaka\Foundation\Api\Actor\Actor;
use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Actor\SecretLinkActor;
use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Database\Statements;

/**
 * Writes entries and their subjects; only the runner calls it, inside the transaction it
 * controls (foundation.md, "Audit"). It never begins or commits anything itself.
 *
 * @internal
 */
final class AuditWriter
{
    public function __construct(private readonly Statements $statements)
    {
    }

    /**
     * Writes the entry and, after it, each nested entry, so `parent_id` is known. `Statements`
     * suppresses wpdb's error output, which would log the values; the audit may hold personal data.
     *
     * @return int the id of the entry, the root of the tree
     * @throws \Throwable the first failed statement, as DatabaseError::check() makes it
     */
    public function write(AuditEntry $entry): int
    {
        return $this->writeTree($entry, null);
    }

    private function writeTree(AuditEntry $entry, ?int $parentId): int
    {
        $id = $this->insertEntry($entry, $parentId);
        foreach ($entry->subjects() as $subject) {
            $this->statements->insert($this->statements->prefixed('kalaka_audit_subjects'), [
                'entry_id' => $id,
                'event_id' => $subject->eventId,
                'record_type' => $subject->type,
                'record_key' => $subject->key,
            ], ['%d', '%d', '%s', '%s']);
        }
        foreach ($entry->children() as $child) {
            $this->writeTree($child, $id);
        }

        return $id;
    }

    private function insertEntry(AuditEntry $entry, ?int $parentId): int
    {
        $actor = $entry->actor;
        [$address, $agent] = self::origin($actor);
        $changes = $entry->changeItems();

        return $this->statements->insert($this->statements->prefixed('kalaka_audit_entries'), [
            'parent_id' => $parentId,
            'correlation_id' => Correlation::id(),
            'occurred_at' => $entry->occurredAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'side' => $entry->side->value,
            'actor_type' => $actor->type(),
            'actor_user_id' => $actor instanceof UserActor || $actor instanceof AccountActor
                || $actor instanceof CliActor ? $actor->userId : null,
            'actor_event_id' => $actor instanceof SecretLinkActor ? $actor->registration->eventId : null,
            'actor_code' => $actor instanceof SecretLinkActor ? $actor->registration->code : null,
            'action' => $entry->action,
            'ip_address' => $address,
            'user_agent' => $agent,
            'reason' => $entry->reason,
            'outcome' => $entry->failed() ? 'failed' : 'succeeded',
            'error' => $entry->error,
            'changes' => $changes === null ? null : self::json($changes),
        ], ['%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']);
    }

    /** @param list<array<string, mixed>> $changes */
    private static function json(array $changes): string
    {
        $json = wp_json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $cause = new \InvalidArgumentException('The changes of an entry are not JSON-encodable.');
            $error = new SystemFailure(null, $cause);
            throw $error;
        }

        return $json;
    }

    /**
     * The IP address and user agent of the people in a web request (decision D14): never a
     * forwarded header, and nothing for the CLI and the system.
     *
     * @return array{?string, ?string}
     */
    private static function origin(Actor $actor): array
    {
        if (!($actor instanceof UserActor || $actor instanceof SecretLinkActor || $actor instanceof AccountActor)) {
            return [null, null];
        }

        $address = null;
        if (isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR'])) {
            $candidate = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
            $address = rest_is_ip_address($candidate) === false ? null : $candidate;
        }

        $agent = null;
        if (isset($_SERVER['HTTP_USER_AGENT']) && is_string($_SERVER['HTTP_USER_AGENT'])) {
            $agent = mb_substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])), 0, 255, 'UTF-8');
            $agent = $agent === '' ? null : $agent;
        }

        return [$address, $agent];
    }
}
