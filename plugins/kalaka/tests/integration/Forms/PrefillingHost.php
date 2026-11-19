<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Forms;

use Kalaka\Forms\Api\AccountStep;
use Kalaka\Forms\Api\Form;
use Kalaka\Forms\Api\FormHost;
use Kalaka\Forms\Api\ValueContext;
use Kalaka\Foundation\Api\Actor\Actor;

/** The event host with stored values to open with. */
final class PrefillingHost implements FormHost
{
    /** @param array<string, string> $stored */
    public function __construct(private readonly FormHost $inner, private readonly array $stored)
    {
    }

    public function factTypes(): array
    {
        return $this->inner->factTypes();
    }

    public function facts(Form $form): ?ValueContext
    {
        return $this->inner->facts($form);
    }

    public function refusals(Form $form, \DateTimeImmutable $now, array $roles, bool $passwordGiven): array
    {
        return $this->inner->refusals($form, $now, $roles, $passwordGiven);
    }

    public function window(Form $form): array
    {
        return $this->inner->window($form);
    }

    public function keyProblem(Form $form, string $content): ?array
    {
        return $this->inner->keyProblem($form, $content);
    }

    public function prefill(Form $form): array
    {
        return $this->stored;
    }

    public function submitToken(): string
    {
        return $this->inner->submitToken();
    }

    public function submit(
        int $formId,
        int $version,
        array $answers,
        string $token,
        array $roles,
        bool $passwordGiven,
        Actor $actor
    ): array {
        return $this->inner->submit($formId, $version, $answers, $token, $roles, $passwordGiven, $actor);
    }

    public function afterSubmitTypes(): array
    {
        return $this->inner->afterSubmitTypes();
    }

    public function accountStep(Form $form): ?AccountStep
    {
        return $this->inner->accountStep($form);
    }
}
