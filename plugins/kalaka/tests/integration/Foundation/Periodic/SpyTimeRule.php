<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation\Periodic;

use Kalaka\Foundation\Periodic\TimeRule;
use Kalaka\Foundation\Periodic\TimeRuleInput;
use Kalaka\Registrations\Api\RegistrationKey;

/** A time rule the test steers: it records the inputs it is asked about and answers with its closure. */
final class SpyTimeRule implements TimeRule
{
    /** @var list<TimeRuleInput> */
    public array $inputs = [];

    private static int $count = 0;

    private readonly string $name;

    /** @param \Closure(TimeRuleInput): list<RegistrationKey> $answer */
    public function __construct(private readonly \Closure $answer, ?string $name = null)
    {
        $this->name = $name ?? 'spy-' . ++self::$count;
    }

    public function name(): string
    {
        return $this->name;
    }

    /** A rule that names the same registration codes in every event it is asked about. */
    public static function naming(string ...$codes): self
    {
        return new self(static fn (TimeRuleInput $input): array => array_map(
            static fn (string $code): RegistrationKey => new RegistrationKey($input->event->id, $code),
            array_values($codes)
        ));
    }

    public function due(TimeRuleInput $input): array
    {
        $this->inputs[] = $input;

        return ($this->answer)($input);
    }

    /** @return list<int> */
    public function askedEvents(): array
    {
        return array_map(static fn (TimeRuleInput $input): int => $input->event->id, $this->inputs);
    }
}
