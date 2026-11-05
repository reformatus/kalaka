<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Registrations;

use Kalaka\Foundation\Api\Repository\AppendOnlyRepository;
use Kalaka\Registrations\Submission\SubmissionRepository;
use PHPUnit\Framework\TestCase;

/** AC10: submissions are append-only. */
final class SubmissionRepositoryTest extends TestCase
{
    public function testItExtendsTheAppendOnlyBaseAndDeclaresNoUpdate(): void
    {
        $class = new \ReflectionClass(SubmissionRepository::class);

        self::assertTrue($class->isSubclassOf(AppendOnlyRepository::class));
        foreach ($class->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() === SubmissionRepository::class) {
                self::assertDoesNotMatchRegularExpression('/upsert|update|delete/i', $method->getName());
            }
        }
        self::assertFalse($class->hasMethod('upsert'));
        self::assertFalse($class->hasMethod('update'));
        self::assertFalse($class->hasMethod('delete'));
    }
}
