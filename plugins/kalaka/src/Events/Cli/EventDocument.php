<?php

declare(strict_types=1);

namespace Kalaka\Events\Cli;

use Kalaka\Events\Api\Event;
use Kalaka\Foundation\Api\Entry\CommandFailure;

/**
 * The event as one JSON document, what `wp kalaka event show --format=json` prints and `update --file` reads: the
 * editable record (name, capacity, the four core points, the custom points, the payment and account settings)
 * and, read-only, the id, version, state, full latch and currency. Points are local `YYYY-MM-DD HH:MM` in the
 * site time zone, as the use case takes them. What `show` prints, `update` reads back unchanged. The document
 * is shape only: no value is checked here, the use case's schema and rules do that and the command prints
 * their refusal.
 */
final class EventDocument
{
    /** What the file may carry but never changes: `update` refuses a value that differs from the event's. */
    private const READ_ONLY = ['id', 'version', 'state', 'is_full', 'currency'];

    private const POINTS = ['registration_opens_at', 'registration_closes_at', 'starts_at', 'ends_at'];

    private const SETTINGS = [
        'account_mode', 'payment_due_days', 'transfer_beneficiary', 'transfer_account', 'transfer_reference_prefix',
    ];

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
     * The keys of the file that change the event: the event's document with them put over it.
     *
     * @param array<string, mixed> $current `of()` of the event
     * @return array{array<string, mixed>, list<string>} the proposed document and the keys the file gave
     * @throws CommandFailure when the file is not an object, or has an unknown key
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
            $proposed[$key] = $value;
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
        foreach (self::SETTINGS as $setting) {
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
}
