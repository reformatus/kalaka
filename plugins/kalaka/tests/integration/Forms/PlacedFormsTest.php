<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Forms;

use Kalaka\Forms\PlacedForms;
use WP_UnitTestCase;

/** The forms a page places (S74's handoff): the block and the shortcode, never an escaped shortcode. */
final class PlacedFormsTest extends WP_UnitTestCase
{
    public function testTheBlockAndTheShortcodePlaceForms(): void
    {
        $content = '<!-- wp:kalaka/form {"formId":12} /-->[et_pb_text][kalaka_form id="14"][/et_pb_text]';

        self::assertSame([12, 14], PlacedForms::in($content));
    }

    public function testAnEscapedShortcodeBesideARealOnePlacesOneForm(): void
    {
        self::assertSame([14], PlacedForms::in('Teszt: [[kalaka_form id="5"]] [kalaka_form id="14"]'));
    }

    public function testAnEscapedShortcodeAlonePlacesNoForm(): void
    {
        self::assertSame([], PlacedForms::in('Teszt: [[kalaka_form id="5"]]'));
    }
}
