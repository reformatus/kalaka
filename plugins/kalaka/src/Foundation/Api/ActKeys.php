<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api;

use Kalaka\Foundation\ActKeys\ActKey;
use Kalaka\Foundation\ActKeys\ActKeyRepository;
use Kalaka\Foundation\Api\Error\IdempotencyConflict;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * A retry key for a staff act (foundation.md, "An act's retry key"). The use case holds the registration's
 * lock, calls `recall()` before it changes anything and, when it returns null, `remember()` with its
 * result before it returns: a repeat with the same key and input returns the stored result and changes
 * nothing; the same key with other input is an `IdempotencyConflict`. A preview never stores and never
 * recalls.
 */
final class ActKeys
{
    /** The same pattern as the ledger's manual entry key: the longest caller key. */
    public const KEY_PATTERN = '^[a-z0-9_:.-]{1,100}\\z';

    public function __construct(private readonly ActKeyRepository $keys = new ActKeyRepository())
    {
    }

    /**
     * The key's part of a use case's input schema: merge `properties` into the schema's and `required` into
     * its `required` list.
     *
     * @return array{properties: array<string, array<string, string>>, required: list<string>}
     */
    public static function schemaFragment(bool $required): array
    {
        return [
            'properties' => ['idempotency_key' => ['type' => 'string', 'pattern' => self::KEY_PATTERN]],
            'required' => $required ? ['idempotency_key'] : [],
        ];
    }

    /**
     * What the key holds for this input, inside the use case's transaction.
     *
     * @param array<string, mixed> $input the act's input; the key and `preview` are left out of the hash
     * @return ?array<mixed> the stored result, or null when the key is not held (or there is none, or a preview)
     * @throws IdempotencyConflict when the key is held with a different input
     */
    public function recall(
        Context $context,
        RegistrationKey $registration,
        string $act,
        ?string $key,
        array $input,
    ): ?array {
        $context->assertOpen();
        if ($key === null || $this->isPreview($input)) {
            return null;
        }
        $held = $this->keys->held($registration, $key);
        if ($held === null) {
            return null;
        }
        if ($held->act !== $act || !hash_equals($held->inputHash, self::hashOf($input))) {
            throw new IdempotencyConflict();
        }

        return json_decode($held->result, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Stores the result under the key; does nothing for a null key or a preview.
     *
     * @param array<string, mixed> $input
     * @param array<mixed> $result
     */
    public function remember(
        Context $context,
        RegistrationKey $registration,
        string $act,
        ?string $key,
        array $input,
        array $result,
    ): void {
        $context->assertOpen();
        if ($key === null || $this->isPreview($input)) {
            return;
        }
        $this->keys->insert(
            new ActKey(
                $registration,
                $act,
                $key,
                self::hashOf($input),
                self::encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $context->now(),
            ),
            $context
        );
    }

    /**
     * The sha256 of the input without the key and `preview`, keys sorted at every level.
     *
     * @param array<string, mixed> $input
     */
    public static function hashOf(array $input): string
    {
        unset($input['idempotency_key'], $input['preview']);

        return hash('sha256', self::encode(
            self::sorted($input),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        ));
    }

    /** @param array<string, mixed> $input */
    private function isPreview(array $input): bool
    {
        return ($input['preview'] ?? false) === true;
    }

    private static function encode(mixed $value, int $flags): string
    {
        $json = wp_json_encode($value, $flags);
        if ($json === false) {
            $error = new SystemFailure(null, new \LogicException('An act key value is not JSON.'));
            throw $error;
        }

        return $json;
    }

    private static function sorted(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::sorted(...), $value);
        }
        ksort($value, SORT_STRING);

        return array_map(self::sorted(...), $value);
    }
}
