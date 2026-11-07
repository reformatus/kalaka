<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation;

use Kalaka\Foundation\Api\Actor\SystemActor;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\UseCase\Context;

final class RunnerInputTest extends RunnerTestCase
{
    private const SCHEMA = [
        'type' => 'object',
        'properties' => ['count' => ['type' => 'integer']],
        'required' => ['count'],
    ];

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidInputs(): iterable
    {
        yield 'missing' => [[]];
        yield 'wrong type' => [['count' => 'many']];
    }

    /**
     * @dataProvider invalidInputs
     * @param array<string, mixed> $input
     */
    public function testInvalidInputIsRefusedBeforeExecute(array $input): void
    {
        $useCase = new AdminProbe(function (): void {
            $this->insertRow('a');
        }, self::SCHEMA);

        try {
            $this->runner->run($useCase, $input, new SystemActor());
            self::fail('The run should have thrown.');
        } catch (InvalidInput $caught) {
            self::assertSame(InvalidInput::class, $caught::class);
            $wpError = $caught->toWpError();
            self::assertSame('kalaka_invalid_input', $wpError->get_error_code());
            self::assertSame(['status' => 400], $wpError->get_error_data());
            self::assertSame('Hibás vagy hiányzó adat.', $wpError->get_error_message());
            self::assertNotNull($caught->getPrevious());
        }
        self::assertSame(0, $useCase->calls);
        self::assertSame([], $this->rows());
    }

    public function testValidInputReachesExecuteSanitized(): void
    {
        $received = null;
        $useCase = new AdminProbe(function (array $input) use (&$received): void {
            $received = $input;
        }, self::SCHEMA);

        $this->runner->run($useCase, ['count' => '5'], new SystemActor());

        self::assertSame(['count' => 5], $received);
    }

    public function testANestedRunWithInvalidInputEndsTheWholeRunWithASystemFailure(): void
    {
        $nested = new AdminProbe(function (): void {
            $this->insertRow('nested');
        }, self::SCHEMA);
        $parent = new AdminProbe(function (array $input, Context $context) use ($nested): void {
            $this->insertRow('parent');
            try {
                $context->run($nested, ['count' => 'many']);
            } catch (SystemFailure) {
                // Swallowed, but the run is already doomed.
            }
        });

        try {
            $this->runner->run($parent, [], new SystemActor());
            self::fail('The run should have thrown.');
        } catch (SystemFailure $caught) {
            self::assertSame(SystemFailure::class, $caught::class);
            self::assertInstanceOf(InvalidInput::class, $caught->getPrevious());
        }
        self::assertSame(0, $nested->calls);
        self::assertSame([], $this->rows());
    }

    public function testANestedRunsValidInputIsSanitizedToo(): void
    {
        $received = null;
        $nested = new AdminProbe(function (array $input) use (&$received): void {
            $received = $input;
        }, self::SCHEMA);
        $parent = new AdminProbe(function (array $input, Context $context) use ($nested): void {
            $context->run($nested, ['count' => '7']);
        });

        $this->runner->run($parent, [], new SystemActor());

        self::assertSame(['count' => 7], $received);
    }

    public function testAnInvalidInputRaisedByANestedExecuteBecomesASystemFailure(): void
    {
        $nested = new AdminProbe(function (): void {
            throw new InvalidInput();
        });
        $parent = new AdminProbe(function (array $input, Context $context) use ($nested): void {
            $context->run($nested, []);
        });

        try {
            $this->runner->run($parent, [], new SystemActor());
            self::fail('The run should have thrown.');
        } catch (SystemFailure $caught) {
            self::assertSame(SystemFailure::class, $caught::class);
            self::assertInstanceOf(InvalidInput::class, $caught->getPrevious());
        }
    }

    public function testAnInvalidInputThrownByTheOutermostUseCaseStaysInvalidInput(): void
    {
        $useCase = new AdminProbe(function (): void {
            throw new InvalidInput();
        });

        $this->expectException(InvalidInput::class);
        $this->runner->run($useCase, [], new SystemActor());
    }
}
