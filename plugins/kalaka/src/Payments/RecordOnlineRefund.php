<?php

declare(strict_types=1);

namespace Kalaka\Payments;

use Kalaka\Foundation\Api\Time\Instant;
use Kalaka\Ledger\Api\LineKind;
use Kalaka\Payments\Api\OnlineRefs;
use Kalaka\Payments\Api\RefundedRequest;

/**
 * Records a refund of a paid request: one positive `refund` line per item, source `<provider>_order` (the
 * request), key `online:<provider>:refund:<refund>:<item>`, `paidAt` the refund's. Run as the system by
 * `OnlinePayments`, which builds the input from the report (`inputOf()`). Returns how many lines it wrote. A
 * refund never unconfirms: confirmation reads payments only through its own rule.
 */
final class RecordOnlineRefund extends RecordOnline
{
    public static function name(): string
    {
        return 'kalaka/record-online-refund';
    }

    /**
     * @return array<string, mixed>
     */
    public static function inputOf(RefundedRequest $refund): array
    {
        return [
            ...self::reportInput($refund->providerId, $refund->requestRef, $refund->items),
            'refund' => $refund->refundRef,
            'refunded_at' => Instant::toStorage($refund->refundedAt),
            'allocated' => $refund->allocated,
        ];
    }

    /** @return array{properties: array<string, array<string, mixed>>, required: list<string>} */
    protected function extraSchema(): array
    {
        return [
            'properties' => [
                'refund' => ['type' => 'string', 'pattern' => OnlineRefs::REF_SCHEMA],
                'refunded_at' => ['type' => 'string', 'pattern' => OnlineRefs::INSTANT_SCHEMA],
                'allocated' => ['type' => 'boolean'],
            ],
            'required' => ['refund', 'refunded_at'],
        ];
    }

    protected function kind(): LineKind
    {
        return LineKind::Refund;
    }

    /** @param array<string, mixed> $input */
    protected function keyPart(array $input): string
    {
        return 'refund:' . $input['refund'];
    }

    /** @param array<string, mixed> $input */
    protected function allocated(array $input): bool
    {
        return (bool) ($input['allocated'] ?? false);
    }

    /** @param array<string, mixed> $input */
    protected function at(array $input): \DateTimeImmutable
    {
        return Instant::fromStorage((string) $input['refunded_at']);
    }
}
