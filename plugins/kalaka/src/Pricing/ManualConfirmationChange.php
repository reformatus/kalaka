<?php

declare(strict_types=1);

namespace Kalaka\Pricing;

use Kalaka\Foundation\Api\Actor\Actor;
use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Pricing\Api\ManualConfirmations;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\Registrations;

/**
 * The shared body of setting the manual confirmation flag and of unconfirming (money.md, "Confirmation"): one
 * registration and a required reason, which the audit entry carries. Setting a set flag or unconfirming a
 * registration that is not confirmed writes nothing and succeeds; unconfirming a confirmed one is a new act
 * every time. Carried by
 * `kalaka_confirm_manually` inside its grant's view, or full access. Setting the flag is for a `registered`
 * registration only (the owner's topic 1 ruling, H3: confirmation applies only to registered); the unconfirm
 * is not bound to a status here. [UNAPPROVED N6, N12]
 */
abstract class ManualConfirmationChange implements AdminUseCase
{
    public function __construct(
        protected readonly ManualConfirmationRepository $flags = new ManualConfirmationRepository(),
        private readonly Registrations $registrations = new Registrations(),
    ) {
    }

    /**
     * Changes the locked registration; `$flag` is its stored flag or null, as it was.
     *
     * @return bool whether anything was written
     */
    abstract protected function change(
        RegistrationKey $key,
        ?ManualConfirmationRecord $flag,
        string $reason,
        Context $context,
    ): bool;

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'event_id' => ['type' => 'integer', 'minimum' => 1],
                'code' => ['type' => 'string', 'pattern' => '^[0-9]{2}[AKBEGJTZFLRSXI][0-9]{1,13}$'],
                'reason' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 500],
            ],
            'required' => ['event_id', 'code', 'reason'],
            'additionalProperties' => false,
        ];
    }

    public function action(): string
    {
        return ManualConfirmations::CONFIRM_MANUALLY;
    }

    public function event(array $input): ?int
    {
        return (int) $input['event_id'];
    }

    public function targets(array $input): array
    {
        return [new RegistrationKey((int) $input['event_id'], (string) $input['code'])];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{changed: bool}
     */
    public function execute(array $input, Context $context): array
    {
        $key = new RegistrationKey((int) $input['event_id'], (string) $input['code']);
        $reason = trim((string) $input['reason']);
        if ($reason === '' || mb_strlen($reason, 'UTF-8') > 500) {
            throw new InvalidInput();
        }
        if (!$this->registrations->lock($key, $context)) {
            throw new NotFound();
        }

        return ['changed' => $this->change($key, $this->flags->findForUpdate($key, $context), $reason, $context)];
    }

    /** The WordPress user who acts, or null for a run without one. */
    protected static function userOf(Actor $actor): ?int
    {
        return $actor instanceof UserActor || $actor instanceof CliActor ? $actor->userId : null;
    }
}
