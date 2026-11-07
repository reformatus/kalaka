<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Foundation;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Repository\Repository;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Foundation\Decision\Decision;
use Kalaka\Foundation\Decision\DecisionInput;
use Kalaka\Foundation\Decision\DecisionRecord;
use Kalaka\Foundation\Decision\Transition;
use Kalaka\Foundation\Evaluation\EvaluationFailure;
use Kalaka\Registrations\Api\RegistrationKey;
use PHPUnit\Framework\TestCase;

/** What a rule is handed and returns is plain, immutable data: foundation.md, "Facts, decisions and computed values". */
final class DecisionShapesTest extends TestCase
{
    private const ALLOWED = [\DateTimeImmutable::class, RegistrationKey::class, Subject::class, Decision::class];

    /** @return iterable<string, array{class-string}> */
    public static function shapes(): iterable
    {
        yield 'DecisionInput' => [DecisionInput::class];
        yield 'Decision' => [Decision::class];
        yield 'Transition' => [Transition::class];
    }

    /**
     * @param class-string $class
     * @dataProvider shapes
     */
    public function testTheShapeIsAFinalReadonlyClassOfPlainValues(string $class): void
    {
        $reflection = new \ReflectionClass($class);

        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->isReadOnly());
        self::assertNotSame([], $reflection->getProperties());
        foreach ($reflection->getProperties() as $property) {
            $type = $property->getType();
            self::assertInstanceOf(\ReflectionNamedType::class, $type, $class . '::$' . $property->getName());
            $name = $type->getName();
            if ($type->isBuiltin()) {
                continue;
            }
            self::assertContains($name, self::ALLOWED, $class . '::$' . $property->getName());
            $forbiddenTypes = [
                Repository::class,
                Context::class,
                Runner::class,
                'wpdb',
                DecisionRecord::class,
                EvaluationFailure::class,
            ];
            foreach ($forbiddenTypes as $forbidden) {
                self::assertFalse(is_a($name, $forbidden, true), $class . '::$' . $property->getName());
            }
        }
    }
}
