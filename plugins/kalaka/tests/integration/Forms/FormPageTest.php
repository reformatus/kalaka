<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Forms;

use Kalaka\Forms\Api\FormPage;
use WP_UnitTestCase;

/** N6: a page that places a form is never cached, so no visitor gets another's submit token. */
final class FormPageTest extends WP_UnitTestCase
{
    public function testThePlacesOfAFormAreTheBlockAndTheShortcode(): void
    {
        self::assertTrue(FormPage::placesForm('<!-- wp:kalaka/form {"id":12} /-->'));
        self::assertTrue(FormPage::placesForm('[et_pb_text][kalaka_form id="12"][/et_pb_text]'));
        self::assertFalse(FormPage::placesForm('<!-- wp:paragraph --><p>Teszt</p><!-- /wp:paragraph -->'));
    }

    public function testPreventingCachingDefinesDoNotCachePage(): void
    {
        FormPage::preventCaching();
        FormPage::preventCaching();

        self::assertTrue(defined('DONOTCACHEPAGE'));
        self::assertTrue(constant('DONOTCACHEPAGE'));
    }
}
