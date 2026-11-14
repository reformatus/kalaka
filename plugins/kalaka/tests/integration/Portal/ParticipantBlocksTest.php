<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Portal;

use Kalaka\Foundation\Api\LoadFailure;
use Kalaka\Foundation\Api\LoadState;
use Kalaka\Portal\ParticipantBlocks;
use Kalaka\Portal\StatusMarkup;

/** The blocks and their shortcodes render the same markup, never cached, with the structural stylesheet. */
final class ParticipantBlocksTest extends PortalTestCase
{
    public function testTheBlocksAndTheShortcodesRenderTheSameAndAreNeverCached(): void
    {
        $key = $this->stored('12A3');
        $this->charge($key, '48000');
        $this->activate($key);

        $block = do_blocks('<!-- wp:kalaka/status /--><!-- wp:kalaka/transfer /-->');
        $shortcode = do_shortcode('[kalaka_status][kalaka_transfer]');

        self::assertSame($this->statusBlock() . $this->transferBlock(), $shortcode);
        self::assertSame(trim($shortcode), trim(str_replace("\n", '', $block)));
        self::assertStringContainsString('<section class="kalaka-transfer">', $block);
        self::assertTrue(defined('DONOTCACHEPAGE'));
        self::assertTrue(wp_style_is(ParticipantBlocks::STYLE, 'enqueued'));
        $style = wp_styles()->registered[ParticipantBlocks::STYLE];
        self::assertStringEndsWith('src/Portal/portal.css', (string) $style->src);
    }

    public function testTheBlocksAreRegisteredWithTheSpecsTitles(): void
    {
        $registry = \WP_Block_Type_Registry::get_instance();

        self::assertSame('Jelentkezésem állapota', $registry->get_registered('kalaka/status')?->title);
        self::assertSame('Átutalási adatok', $registry->get_registered('kalaka/transfer')?->title);
    }

    public function testAPageIsFoundToHoldABlockAlsoInsideABuildersShortcodes(): void
    {
        self::assertTrue(ParticipantBlocks::placedIn('<!-- wp:kalaka/status /-->'));
        self::assertTrue(ParticipantBlocks::placedIn('<!-- wp:kalaka/transfer /-->'));
        self::assertTrue(ParticipantBlocks::placedIn('[et_pb_text][kalaka_status][/et_pb_text]'));
        self::assertTrue(ParticipantBlocks::placedIn('[et_pb_text][kalaka_transfer][/et_pb_text]'));
        self::assertFalse(ParticipantBlocks::placedIn('<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->'));
    }

    public function testWhileTheSiteIsClosedTheStatusBlockSaysSoAndTheTransferBlockIsEmpty(): void
    {
        $key = $this->stored('12A3');
        $this->charge($key, '48000');
        $this->activate($key);

        $this->closeSite(function (): void {
            $status = ParticipantBlocks::status();
            self::assertStringNotContainsString('12A3', $status);
            self::assertStringNotContainsString(StatusMarkup::NO_ACTIVE, $status);
            self::assertSame('', ParticipantBlocks::transfer());
        });
    }

    private function closeSite(\Closure $work): void
    {
        try {
            LoadState::record([new LoadFailure('x', 'y')]);
            $work();
        } finally {
            LoadState::record([]);
        }
    }
}
