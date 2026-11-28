<?php

declare(strict_types=1);

namespace Kalaka\Pricing\Api;

/**
 * How the admin names an owed line: a `type` (the discriminator of the „Típus” column), the Hungarian word of
 * it, the title the registrant sees (a manual line only) and who recorded it (a manual line only).
 */
final readonly class LineName
{
    /** The fixed price, and any component that names nothing. */
    public const FEE = 'fee';

    public const MANUAL_CHARGE = 'manual_charge';

    public const MANUAL_DISCOUNT = 'manual_discount';

    /** A component that names its lines; the label is the component's own word. */
    public const COMPONENT = 'component';

    public function __construct(
        public string $type,
        public string $label,
        public ?string $title = null,
        public ?int $recordedBy = null,
    ) {
    }
}
