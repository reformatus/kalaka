<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Audit;

use Kalaka\Registrations\Api\RegistrationKey;

/**
 * A record an audit entry is about, identified by record type, event ID and key:
 * foundation.md, "Audit".
 */
final readonly class Subject
{
    /**
     * @param string $type a lowercase identifier of at most 50 characters, e.g. `registration`
     * @param ?int $eventId null for a record outside an event
     * @param string $key 1 to 64 characters
     * @throws \InvalidArgumentException
     */
    public function __construct(public string $type, public ?int $eventId, public string $key)
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $type) !== 1 || strlen($type) > 50) {
            throw new \InvalidArgumentException('A subject type is a lowercase identifier of at most 50 characters.');
        }
        $length = mb_strlen($key, 'UTF-8');
        if ($length < 1 || $length > 64) {
            throw new \InvalidArgumentException('A subject key is 1 to 64 characters.');
        }
        if ($eventId !== null && $eventId < 1) {
            throw new \InvalidArgumentException('A subject event ID is null or positive.');
        }
    }

    public static function registration(RegistrationKey $key): self
    {
        return new self('registration', $key->eventId, $key->code);
    }

    public static function event(int $eventId): self
    {
        return new self('event', $eventId, (string) $eventId);
    }
}
