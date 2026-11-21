<?php

declare(strict_types=1);

namespace Kalaka\Forms;

use Kalaka\Forms\Api\AccountStep;
use Kalaka\Forms\Api\Form;
use Kalaka\Forms\Api\FormHost;
use Kalaka\Forms\Api\RequestOptions;
use Kalaka\Forms\Api\ValueContext;
use Kalaka\Foundation\Api\Actor\Actor;

/**
 * A host as another, but one set's options come without their conditions: `Forms::requestScopes()` parses a
 * live form this way to find where its request field sits, whatever the set's conditions are now (S44).
 * Used only for that read.
 */
final class RequestScopeHost implements FormHost
{
    public function __construct(private readonly FormHost $host, private readonly int $setId)
    {
    }

    public function factTypes(): array
    {
        return $this->host->factTypes();
    }

    public function facts(Form $form, \DateTimeImmutable $now): ?ValueContext
    {
        return $this->host->facts($form, $now);
    }

    public function refusals(Form $form, \DateTimeImmutable $now, array $roles, bool $passwordGiven): array
    {
        return $this->host->refusals($form, $now, $roles, $passwordGiven);
    }

    public function window(Form $form): array
    {
        return $this->host->window($form);
    }

    public function keyProblem(Form $form, string $content): ?array
    {
        return $this->host->keyProblem($form, $content);
    }

    public function prefill(Form $form): array
    {
        return $this->host->prefill($form);
    }

    public function requestOptions(Form $form, int $setId): ?RequestOptions
    {
        $options = $this->host->requestOptions($form, $setId);
        if ($options === null || $setId !== $this->setId) {
            return $options;
        }

        return new RequestOptions(
            array_map(
                static fn (array $option): array => ['value' => $option['value'], 'label' => $option['label']],
                $options->options
            ),
            $options->opens,
            $options->closes,
        );
    }

    public function submitToken(): string
    {
        return $this->host->submitToken();
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
        return $this->host->submit($formId, $version, $answers, $token, $roles, $passwordGiven, $actor);
    }

    public function afterSubmitTypes(): array
    {
        return $this->host->afterSubmitTypes();
    }

    public function accountStep(Form $form): ?AccountStep
    {
        return $this->host->accountStep($form);
    }

    public function slotRequiredKeys(Form $form): array
    {
        return $this->host->slotRequiredKeys($form);
    }
}
