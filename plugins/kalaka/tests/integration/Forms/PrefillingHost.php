<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Forms;

use Kalaka\Forms\Api\AccountStep;
use Kalaka\Forms\Api\AnswerSet;
use Kalaka\Forms\Api\DraftClaimChoice;
use Kalaka\Forms\Api\Form;
use Kalaka\Forms\Api\FormDraft;
use Kalaka\Forms\Api\FormHost;
use Kalaka\Forms\Api\RequestOptions;
use Kalaka\Forms\Api\ValueContext;
use Kalaka\Foundation\Api\Actor\Actor;

/** The event host with stored values to open with. */
final class PrefillingHost implements FormHost
{
    /** @param array<string, string> $stored */
    public function __construct(private readonly FormHost $inner, private readonly array $stored)
    {
    }

    public function factTypes(Form $form): array
    {
        return $this->inner->factTypes($form);
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

    public function draft(Form $form): FormDraft
    {
        return $this->inner->draft($form);
    }

    public function saveDraft(
        Form $form,
        int $version,
        AnswerSet $answers,
        int $page,
        int $claims,
        Actor $actor
    ): void {
        $this->inner->saveDraft($form, $version, $answers, $page, $claims, $actor);
    }

    public function resolveDraftClaim(Form $form, DraftClaimChoice $choice, Actor $actor): void
    {
        $this->inner->resolveDraftClaim($form, $choice, $actor);
    }

    public function requestOptions(Form $form, int $setId): ?RequestOptions
    {
        return $this->inner->requestOptions($form, $setId);
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
