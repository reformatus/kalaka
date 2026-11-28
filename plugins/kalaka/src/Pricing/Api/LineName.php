<?php

declare(strict_types=1);

namespace Kalaka\Pricing\Api;

/**
 * How an owed line is named (the admin's „Tételek”, the participant's money table): a `type` (the
 * discriminator of the „Típus” column), the Hungarian word of it, the title the registrant sees (a manual line
 * only) and who recorded it (a manual line only). The stage is the one its fee component composes in, null for
 * a source of a component that is not registered: it tells the participant's money table the base price (the
 * tier) from any other line.
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
        public ?FeeStage $stage = null,
    ) {
    }
}
