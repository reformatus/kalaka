<?php

declare(strict_types=1);

namespace Kalaka\Events\Api;

use Kalaka\Events\EventQuery;
use Kalaka\Events\EventRepository;
use Kalaka\Events\UpdateEvent;
use Kalaka\Forms\Api\Forms;
use Kalaka\Foundation\Api\Actor\Actor;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Mail\Api\AccountMail;

final class Events
{
    public function __construct(
        private readonly EventRepository $repository = new EventRepository(),
        private readonly EventQuery $query = new EventQuery(),
        private readonly ?Runner $runner = null,
    ) {
    }

    /** A plain read, without a lock. */
    public function find(int $id): ?Event
    {
        return $this->repository->find($id)?->toEvent();
    }

    /**
     * The events whose stored state is `published`, ascending by id, in one statement.
     *
     * @return list<Event>
     */
    public function published(): array
    {
        return $this->query->published();
    }

    /**
     * Every event in any state, ascending by id, in one statement.
     *
     * @return list<Event>
     */
    public function all(): array
    {
        return $this->query->all();
    }

    /**
     * Replaces the editable record of an event (`kalaka/update-event`; the input is passed on as given). When
     * the input gives an account mode with accounts, the event's published registration forms are read first
     * and passed in, so the use case refuses a mode that would leave one without its account block; then the
     * "registered" mail is read too, which must be on with the set-password link. After
     * the run the broken-form list is refreshed when the mode was given, as it reads the mode. With `preview: true`
     * the run checks everything and saves nothing, and the list is left as it is.
     *
     * @param array<string, mixed> $input
     * @throws \Kalaka\Foundation\Api\Error\KalakaException anything the run throws
     */
    public function update(array $input, Actor $actor): void
    {
        $mode = is_string($input['account_mode'] ?? null) ? AccountMode::tryFrom($input['account_mode']) : null;
        $forms = new Forms();
        $lacking = $mode === null || $mode === AccountMode::Off || !is_int($input['id'] ?? null)
            ? []
            : $forms->withoutAccountBlock($input['id'], $mode);

        $withAccounts = $mode !== null && $mode !== AccountMode::Off && is_int($input['id'] ?? null);
        $mailProblem = $lacking === [] && $withAccounts ? (new AccountMail())->problem($input['id']) : null;

        ($this->runner ?? Runner::current())->run(
            new UpdateEvent(formsWithoutAccountBlock: $lacking, accountMailProblem: $mailProblem),
            $input,
            $actor
        );

        if ($mode !== null && ($input['preview'] ?? false) !== true) {
            $forms->refreshBrokenList();
        }
    }
}
