<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Forms;

use Kalaka\Forms\Api\ThemeAdaptation;
use Kalaka\Forms\Api\ThemeAdapter;
use Kalaka\Forms\Api\ThemeAdapterInput;

/** A theme adapter that returns what the test hands it, or throws it. */
final class TestThemeAdapter implements ThemeAdapter
{
    public ?ThemeAdapterInput $askedWith = null;

    /** @param ThemeAdaptation|\Closure(): ThemeAdaptation|null $returns */
    public function __construct(private readonly ThemeAdaptation|\Closure|null $returns)
    {
    }

    public function adapt(ThemeAdapterInput $input): ?ThemeAdaptation
    {
        $this->askedWith = $input;

        return $this->returns instanceof \Closure ? ($this->returns)() : $this->returns;
    }
}
