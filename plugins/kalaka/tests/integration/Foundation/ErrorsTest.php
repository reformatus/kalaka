<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation;

use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\KalakaException;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\PermissionDenied;
use Kalaka\Foundation\Api\Error\RetryableFailure;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Error\VersionConflict;
use WP_UnitTestCase;

/** The table in foundation.md, "Errors". */
final class ErrorsTest extends WP_UnitTestCase
{
    /** @return iterable<string, array{class-string<KalakaException>, string, int, string}> */
    public static function table(): iterable
    {
        yield 'InvalidInput' => [InvalidInput::class, 'kalaka_invalid_input', 400, 'Hibás vagy hiányzó adat.'];
        yield 'PermissionDenied' => [
            PermissionDenied::class, 'kalaka_permission_denied', 403, 'Ehhez nincs jogosultságod.',
        ];
        yield 'NotFound' => [NotFound::class, 'kalaka_not_found', 404, 'A keresett elem nem található.'];
        yield 'VersionConflict' => [
            VersionConflict::class,
            'kalaka_version_conflict',
            409,
            'Valaki időközben módosította ezeket az adatokat. Töltsd újra az oldalt, és próbáld újra.',
        ];
        yield 'RuleViolation' => [RuleViolation::class, 'kalaka_rule_violation', 422, 'A művelet nem hajtható végre.'];
        yield 'SystemFailure' => [
            SystemFailure::class, 'kalaka_system_failure', 500, 'Váratlan hiba történt. Próbáld újra később.',
        ];
        yield 'RetryableFailure' => [
            RetryableFailure::class, 'kalaka_retryable_failure', 500, 'Váratlan hiba történt. Próbáld újra később.',
        ];
    }

    /**
     * @dataProvider table
     * @param class-string<KalakaException> $class
     */
    public function testWpError(string $class, string $code, int $status, string $text): void
    {
        $plain = (new $class())->toWpError();
        self::assertSame($code, $plain->get_error_code());
        self::assertSame(['status' => $status], $plain->get_error_data());
        self::assertSame($text, $plain->get_error_message());

        $withContext = (new $class('Az esemény nem található.'))->toWpError();
        self::assertSame($code, $withContext->get_error_code());
        self::assertSame(['status' => $status], $withContext->get_error_data());
        self::assertSame($text . ' Az esemény nem található.', $withContext->get_error_message());
    }

    public function testOnlyRetryableFailureIsRetryable(): void
    {
        self::assertTrue((new RetryableFailure())->isRetryable());
        self::assertFalse((new SystemFailure())->isRetryable());
        self::assertFalse((new VersionConflict())->isRetryable());
    }
}
