<?php

declare(strict_types=1);

namespace Kalaka\Pricing\Api;

/**
 * A fee component (money.md, "Materializer"; modules-and-extensions.md, "Contracts"): an inside point. It
 * says what a registration owes for one thing, per payer, from its input and its own current settings, and
 * writes nothing. Core composes the components stage by stage, and the materializer records the
 * difference. No tariff snapshot: the settings are read as they are now.
 */
interface FeeComponent
{
    /** `[a-z][a-z0-9_]*`, at most 50 characters, unique: the source key of its `charge` lines. */
    public function id(): string;

    public function stage(): FeeStage;

    /**
     * What each payer owes for this component now. A payer it leaves out owes zero.
     *
     * @throws \Throwable anything, which the materializer turns into `FeeComponentFailed`
     */
    public function targets(FeeComponentInput $input): FeeTargets;
}
