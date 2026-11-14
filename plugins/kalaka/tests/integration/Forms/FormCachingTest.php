<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Forms;

use Kalaka\Forms\Api\FormCaching;
use WP_UnitTestCase;

/** N6: a page that places a form is never cached, so no visitor gets another's submit token. */
final class FormCachingTest extends WP_UnitTestCase
{
    public function testThePlacesOfAFormAreTheBlockAndTheShortcode(): void
    {
        self::assertTrue(FormCaching::placesForm('<!-- wp:kalaka/form {"id":12} /-->'));
        self::assertTrue(FormCaching::placesForm('[et_pb_text][kalaka_form id="12"][/et_pb_text]'));
        self::assertFalse(FormCaching::placesForm('<!-- wp:paragraph --><p>Teszt</p><!-- /wp:paragraph -->'));
    }

    public function testPreventingCachingDefinesDoNotCachePage(): void
    {
        FormCaching::preventCaching();
        FormCaching::preventCaching();

        self::assertTrue(defined('DONOTCACHEPAGE'));
        self::assertTrue(constant('DONOTCACHEPAGE'));
    }
}
