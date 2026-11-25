<?php

declare(strict_types=1);

namespace Kalaka\Payments;

use Kalaka\Foundation\Api\Time\Instant;
use Kalaka\Ledger\Api\LineKind;
use Kalaka\Payments\Api\OnlineRefs;
use Kalaka\Payments\Api\PaidRequest;

/**
 * Records a paid payment request: one negative `payment` line per item, source `<provider>_order`, key
 * `online:<provider>:order:<request>:<item>`, `paidAt` the request's. Run as the system by `OnlinePayments`,
 * which builds the input from the report (`inputOf()`). Returns how many lines it wrote.
 */
final class RecordOnlinePayment extends RecordOnline
{
    public static function name(): string
    {
        return 'kalaka/record-online-payment';
    }

    /**
     * @return array<string, mixed>
     */
    public static function inputOf(PaidRequest $paid): array
    {
        return [
            ...self::reportInput($paid->providerId, $paid->requestRef, $paid->items),
            'paid_at' => Instant::toStorage($paid->paidAt),
        ];
    }

    /** @return array{properties: array<string, array<string, mixed>>, required: list<string>} */
    protected function extraSchema(): array
    {
        return [
            'properties' => ['paid_at' => ['type' => 'string', 'pattern' => OnlineRefs::INSTANT_SCHEMA]],
            'required' => ['paid_at'],
        ];
    }

    protected function kind(): LineKind
    {
        return LineKind::Payment;
    }

    /** @param array<string, mixed> $input */
    protected function keyPart(array $input): string
    {
        return 'order:' . $input['request'];
    }

    /** @param array<string, mixed> $input */
    protected function at(array $input): \DateTimeImmutable
    {
        return Instant::fromStorage((string) $input['paid_at']);
    }
}
