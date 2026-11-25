<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation;

use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * The one rule for every preview (foundation.md, "Audit"; [UNAPPROVED N534]): a top-level run that is a preview
 * writes nothing, so the runner writes no audit entry for it when it succeeds; a real run, even an unchanged
 * one, is audited; a refused preview is audited like any refusal; a preview that writes fails.
 */
final class RunnerPreviewTest extends RepositoryTestCase
{
    public function testASuccessfulPreviewWritesNoEntryAndARealRunStillDoes(): void
    {
        $useCase = new PreviewProbe(static fn (): string => 'answer');

        self::assertSame('answer', $this->runner->run($useCase, ['preview' => true], new CliActor(null)));
        self::assertSame([], $this->entries());

        self::assertSame('answer', $this->runner->run($useCase, [], new CliActor(null)));
        self::assertCount(1, $this->entries());
        self::assertSame('answer', $this->runner->run($useCase, ['preview' => false], new CliActor(null)));
        self::assertCount(2, $this->entries());
    }

    public function testARefusedPreviewIsAuditedLikeAnyRefusal(): void
    {
        $useCase = new PreviewProbe(static function (): never {
            throw new RuleViolation();
        });

        try {
            $this->runner->run($useCase, ['preview' => true], new CliActor(null));
            self::fail('Refused.');
        } catch (RuleViolation) {
            self::assertCount(1, $this->entries());
            self::assertNotNull($this->onlyEntry()['error']);
        }
    }

    public function testAPreviewThatWritesARegistrationFailsAndLeavesNothing(): void
    {
        $key = new RegistrationKey(12, '00A0');
        $this->seedRegistrations($key);
        $useCase = new PreviewProbe(function (array $input, Context $context) use ($key): void {
            $this->lockRegistrations($context, $key);
            $this->items->upsert(new TestItem('written', regEvent: 12, regCode: '00A0'), $context);
        });

        try {
            $this->runner->run($useCase, ['preview' => true], new CliActor(null));
            self::fail('A preview must not write.');
        } catch (SystemFailure) {
            self::assertCount(1, $this->entries());
            self::assertNotNull($this->onlyEntry()['error']);
        }
        global $wpdb;
        self::assertSame(0, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}kalaka_test_items"));
    }
}
