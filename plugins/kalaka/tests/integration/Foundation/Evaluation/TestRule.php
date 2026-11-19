<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation\Evaluation;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Decision\DecisionDefault;
use Kalaka\Foundation\Api\Decision\DecisionInput;
use Kalaka\Foundation\Api\Decision\DecisionRule;
use Kalaka\Foundation\Api\Decision\Transition;

/** A rule the test steers: it answers with `answer`, or throws `throws`, and records the inputs it gets. */
final class TestRule implements DecisionRule
{
    /** @var list<DecisionInput> */
    public array $inputs = [];

    public ?string $answer = null;

    public ?\Throwable $throws = null;

    /** @var ?\Closure(DecisionInput): void */
    public ?\Closure $onDecide = null;

    /**
     * @param array<string, string> $states state => label
     * @param ?\ArrayObject<int, string> $asked shared list that gets the kind each time the rule is asked
     */
    public function __construct(
        private readonly string $kind,
        private readonly array $states = ['on' => 'Be', 'off' => 'Ki'],
        private readonly ?\ArrayObject $asked = null,
        private readonly string $label = 'Teszt',
        private readonly ?DecisionDefault $default = null,
    ) {
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function states(): array
    {
        return $this->states;
    }

    public function defaultState(): ?DecisionDefault
    {
        return $this->default;
    }

    public function decide(DecisionInput $input): ?Transition
    {
        $this->inputs[] = $input;
        $this->asked?->append($this->kind);
        if ($this->onDecide !== null) {
            ($this->onDecide)($input);
        }
        if ($this->throws !== null) {
            throw $this->throws;
        }

        return $this->answer === null ? null : new Transition(
            $this->answer,
            new \DateTimeImmutable('2026-11-29 23:59:59', new \DateTimeZone('Europe/Budapest')),
            new Subject('test_record', 12, '7'),
        );
    }
}
