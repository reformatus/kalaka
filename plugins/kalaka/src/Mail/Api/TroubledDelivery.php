<?php

declare(strict_types=1);

namespace Kalaka\Mail\Api;

/**
 * One delivery that needs a look (mail.md, "Failures"): its registration code, never an address or a name, and
 * the error as stored.
 */
final readonly class TroubledDelivery
{
    /**
     * @param string $status `pending` (with the error of an earlier attempt), `sending` (left by a crash) or `failed`
     * @param ?int $batchId the manual mail's send batch; null for any other cause
     * @param ?\DateTimeImmutable $lastAttemptAt when it was last claimed
     */
    public function __construct(
        public int $id,
        public string $code,
        public string $mail,
        public ?ActionMail $action,
        public MailKind $kind,
        public string $status,
        public int $attempts,
        public \DateTimeImmutable $queuedAt,
        public ?\DateTimeImmutable $lastAttemptAt,
        public ?string $error,
        public ?int $batchId,
    ) {
    }
}
