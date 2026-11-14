<?php

declare(strict_types=1);

namespace Kalaka\Registrations;

use Kalaka\Foundation\Api\Actor\AccountActor;
use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\PermissionDenied;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\UseCase\PortalUseCase;
use Kalaka\Registrations\Api\AttachLinks;

/**
 * Follows the attach mail's link (S15, [UNAPPROVED]): the registration gets the logged-in account as its
 * owner, when the link is the registration's current attach link for that account and the registration has
 * no owner yet. The owner is set once: an owned registration keeps its owner, and the run answers whether
 * this account owns it now.
 *
 * An account-level action (`registrations()` names none: the account does not own the registration yet);
 * its own check is the link, verified against the registration's row under its lock.
 */
final class AttachRegistration implements PortalUseCase
{
    public function __construct(private readonly RegistrationRepository $registrations = new RegistrationRepository())
    {
    }

    public static function name(): string
    {
        return 'kalaka-portal/attach-registration';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'link' => ['type' => 'string', 'maxLength' => 128],
            ],
            'required' => ['link'],
            'additionalProperties' => false,
        ];
    }

    public function registrations(array $input): array
    {
        return [];
    }

    /**
     * @return bool whether the account owns the registration now
     * @throws PermissionDenied not an account, or a link for another account or not the current one
     * @throws NotFound no such registration
     */
    public function execute(array $input, Context $context): bool
    {
        $actor = $context->actor();
        $link = (string) $input['link'];
        $named = AttachLinks::parse($link);
        if (!$actor instanceof AccountActor || $named === null || $named[1] !== $actor->userId) {
            $error = new PermissionDenied();
            throw $error;
        }
        $registration = $this->registrations->findForUpdate($named[0], $context) ?? throw new NotFound();
        if (!AttachLinks::matches($registration, $link)) {
            $error = new PermissionDenied();
            throw $error;
        }
        $context->touch(Subject::registration($registration->key));
        if ($registration->ownerUserId === null) {
            $registration->ownerUserId = $actor->userId;
            $this->registrations->upsert($registration, $context);
        }

        return $registration->ownerUserId === $actor->userId;
    }
}
