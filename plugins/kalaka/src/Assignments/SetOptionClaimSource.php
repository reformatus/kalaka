<?php

declare(strict_types=1);

namespace Kalaka\Assignments;

use Kalaka\Events\Api\Event;
use Kalaka\Forms\Api\ValidatedAnswers;
use Kalaka\Registrations\Api\PoolEntry;
use Kalaka\Registrations\Api\SeatClaimSource;

/**
 * The seat claim source of the set options (modules-and-extensions.md, "Assignment sets"). A request names an
 * option but takes no seat: staff confirm it later and the confirmation takes the seat (`PlaceRegistration`), so
 * a submit claims none here. Its pools are every option of every set that has a request field, keyed by that
 * field: `seats_left.<request field key>.<option value>`. [UNAPPROVED N126]
 */
final class SetOptionClaimSource implements SeatClaimSource
{
    public function __construct(
        private readonly SetRepository $sets = new SetRepository(),
        private readonly OptionRepository $options = new OptionRepository(),
    ) {
    }

    public function claims(Event $event, ValidatedAnswers $answers): array
    {
        return [];
    }

    public function pools(Event $event): array
    {
        $fields = [];
        foreach ($this->sets->ofEvent($event->id) as $set) {
            if ($set->requestFieldKey !== null) {
                $fields[$set->id] = $set->requestFieldKey;
            }
        }
        $entries = [];
        foreach ($this->options->ofEvent($event->id) as $option) {
            if (isset($fields[$option->setId])) {
                $entries[] = new PoolEntry(
                    $fields[$option->setId],
                    $option->key,
                    new OptionSeatPool($event->id, $option->setId, $option->key)
                );
            }
        }

        return $entries;
    }
}
