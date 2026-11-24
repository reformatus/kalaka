<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Extras;

use Kalaka\Tests\Integration\Pricing\ShareComponent;
use Kalaka\Tests\Integration\Pricing\SupportComponent;

/** Extras are the participant's: support and the share never reduce them (money.md, "Congregation share"). */
final class ExtrasShareTest extends ExtrasTestCase
{
    public function testTheShareAndSupportCoverOnlyTheBaseAndTheParticipantPaysTheExtras(): void
    {
        $this->useComponents(new ShareComponent('99000'), new SupportComponent('8000'));
        $this->setPrice('40000');
        $this->applySettings(
            $this->extras->propose($this->event, [self::item('ebed', 'Ebéd', '3500')]),
        );

        $anna = $this->registerWith('00A0', ['ebed' => '1', 'adomany' => '5000']);

        self::assertSame([
            'fixed_price participant 40000 charge:fixed_price:participant:1',
            'share congregation 32000 charge:share:congregation:1',
            'share participant -32000 charge:share:participant:1',
            'support participant -8000 charge:support:participant:1',
            'extras:ebed participant 3500 charge:extras:ebed:participant:1',
            'donation participant 5000 charge:donation:participant:1',
        ], $this->charges($anna));
        self::assertSame('8500', $this->balance($anna));
        self::assertSame('32000', $this->balance($anna, 'congregation'));
    }
}
