<?php

declare(strict_types=1);

namespace Kalaka\Events\Cli;

use Kalaka\Events\Api\AccountMode;
use Kalaka\Events\Api\Event;
use Kalaka\Foundation\Api\Entry\CommandFailure;

/**
 * The event as one JSON document, what `wp kalaka event show --format=json` prints and `update --file` reads: the
 * editable record (name, capacity, the four core points, the custom points, the payment and account settings)
 * and, read-only, the id, version, state, full latch and currency. Points are local `YYYY-MM-DD HH:MM` in the
 * site time zone, as the use case takes them. What `show` prints, `update` reads back unchanged.
 */
final class EventDocument
{
    /** What the file may carry but never changes: `update` refuses a value that differs from the event's. */
    private const READ_ONLY = ['id', 'version', 'state', 'is_full', 'currency'];

    private const POINTS = ['registration_opens_at', 'registration_closes_at', 'starts_at', 'ends_at'];

    private const TEXTS = ['transfer_beneficiary' => 191, 'transfer_account' => 64, 'transfer_reference_prefix' => 32];

    /** @return array<string, mixed> */
    public static function of(Event $event): array
    {
        $local = static fn (?\DateTimeImmutable $at): ?string
            => $at?->setTimezone(wp_timezone())->format('Y-m-d H:i');

        return [
            'id' => $event->id,
            'version' => $event->version,
            'state' => $event->state()->value,
            'is_full' => $event->isFull,
            'currency' => $event->currency->value,
            'name' => $event->name,
            'capacity' => $event->capacity,
            'registration_opens_at' => $local($event->registrationOpens),
            'registration_closes_at' => $local($event->registrationCloses),
            'starts_at' => $local($event->start),
            'ends_at' => $local($event->end),
            'points' => array_map(
                static fn ($point): array => ['id' => $point->id, 'name' => $point->name, 'at' => $local($point->at)],
                $event->customPoints
            ),
            'account_mode' => $event->accountMode->value,
            'payment_due_days' => $event->paymentDueDays,
            'transfer_beneficiary' => $event->transferBeneficiary,
            'transfer_account' => $event->transferAccount,
            'transfer_reference_prefix' => $event->transferReferencePrefix,
        ];
    }

    /**
     * The keys of the file that change the event, checked: the event's document with them put over it.
     *
     * @param array<string, mixed> $current `of()` of the event
     * @return array{array<string, mixed>, list<string>} the proposed document and the keys the file gave
     * @throws CommandFailure when the file is not an object, has an unknown key or a bad value
     */
    public static function proposed(array $current, mixed $file): array
    {
        if (!is_array($file) || ($file !== [] && array_is_list($file))) {
            throw new CommandFailure('The file must hold one JSON object.');
        }
        $given = [];
        $proposed = $current;
        foreach ($file as $key => $value) {
            $key = (string) $key;
            if (!array_key_exists($key, $current)) {
                $error = new CommandFailure('Unknown key "' . $key . '" in the file.');
                throw $error;
            }
            if (in_array($key, self::READ_ONLY, true)) {
                if ($value !== $current[$key]) {
                    $error = new CommandFailure('"' . $key . '" cannot be changed here, or the file is out of date.');
                    throw $error;
                }
                continue;
            }
            $given[] = $key;
            $proposed[$key] = self::checked($key, $value);
        }

        return [$proposed, $given];
    }

    /**
     * The `kalaka/update-event` input: the whole editable record from the proposed document, and the settings only
     * for the keys the file gave (a setting left out stays as it is).
     *
     * @param array<string, mixed> $proposed
     * @param list<string> $given
     * @return array<string, mixed>
     */
    public static function input(array $proposed, array $given, int $id, int $version): array
    {
        $input = ['id' => $id, 'version' => $version] + array_intersect_key($proposed, array_flip([
            'name', 'capacity', ...self::POINTS, 'points',
        ]));
        foreach (['account_mode', 'payment_due_days', ...array_keys(self::TEXTS)] as $setting) {
            if (in_array($setting, $given, true)) {
                $input[$setting] = $proposed[$setting];
            }
        }

        return $input;
    }

    /**
     * What differs between two documents: key → [before, after], only for the editable keys.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return array<string, array{mixed, mixed}>
     */
    public static function changes(array $before, array $after): array
    {
        $changes = [];
        foreach ($after as $key => $value) {
            if (!in_array($key, self::READ_ONLY, true) && $value !== $before[$key]) {
                $changes[$key] = [$before[$key], $value];
            }
        }

        return $changes;
    }

    /** @throws CommandFailure */
    private static function checked(string $key, mixed $value): mixed
    {
        if ($key === 'name') {
            $name = is_string($value) ? trim($value) : '';
            if ($name === '' || mb_strlen($name, 'UTF-8') > 191) {
                throw new CommandFailure('"name" must be a text of 1 to 191 characters.');
            }

            return $name;
        }
        if ($key === 'capacity') {
            if ($value !== null && (!is_int($value) || $value < 0 || $value > 50000)) {
                throw new CommandFailure('"capacity" must be a whole number from 0 to 50000, or null.');
            }

            return $value;
        }
        if (in_array($key, self::POINTS, true)) {
            return self::local($key, $value);
        }
        if ($key === 'points') {
            return self::customPoints($value);
        }
        if ($key === 'account_mode') {
            $mode = is_string($value) ? AccountMode::tryFrom($value) : null;
            if ($mode === null) {
                $error = new CommandFailure(
                    '"account_mode" must be one of: ' . implode(', ', array_column(AccountMode::cases(), 'value')) . '.'
                );
                throw $error;
            }

            return $mode->value;
        }
        if ($key === 'payment_due_days') {
            if ($value !== null && (!is_int($value) || $value < 0 || $value > 65535)) {
                throw new CommandFailure('"payment_due_days" must be a whole number from 0 to 65535, or null.');
            }

            return $value;
        }

        return self::text($key, $value);
    }

    /** @throws CommandFailure */
    private static function text(string $key, mixed $value): ?string
    {
        if ($value !== null && !is_string($value)) {
            $error = new CommandFailure('"' . $key . '" must be a text or null.');
            throw $error;
        }
        $text = trim((string) $value);
        if (mb_strlen($text, 'UTF-8') > self::TEXTS[$key]) {
            $error = new CommandFailure('"' . $key . '" is longer than ' . self::TEXTS[$key] . ' characters.');
            throw $error;
        }

        return $text === '' ? null : $text;
    }

    /** @throws CommandFailure */
    private static function local(string $key, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}\z/', $value) !== 1) {
            $error = new CommandFailure('"' . $key . '" must be a local time like 2026-11-30 09:00, or null.');
            throw $error;
        }

        return $value;
    }

    /**
     * @return list<array{id?: int, name: string, at: string}>
     * @throws CommandFailure
     */
    private static function customPoints(mixed $value): array
    {
        if (!is_array($value) || ($value !== [] && !array_is_list($value))) {
            throw new CommandFailure('"points" must be a list of {"name", "at"} objects, "id" keeps a point.');
        }
        $points = [];
        foreach ($value as $item) {
            $valid = is_array($item) && is_string($item['name'] ?? null) && is_string($item['at'] ?? null)
                && array_diff(array_keys($item), ['id', 'name', 'at']) === []
                && (!isset($item['id']) || is_int($item['id']));
            if (!$valid) {
                $error = new CommandFailure('A point must be {"name": text, "at": time} and may carry its "id".');
                throw $error;
            }
            $point = ['name' => $item['name'], 'at' => (string) self::local('points.at', $item['at'])];
            $points[] = isset($item['id']) ? ['id' => $item['id']] + $point : $point;
        }

        return $points;
    }
}
