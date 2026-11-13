<?php

declare(strict_types=1);

namespace Kalaka\Forms\Value;

use Kalaka\Forms\Api\ValueContext;
use Kalaka\Foundation\Api\Error\SystemFailure;

/**
 * Fills the inline values of rendered HTML: in every `<span data-kalaka-value="key">label</span>`
 * the label's text becomes the value's text and the attribute goes; the element stays. The HTML
 * API escapes the text.
 *
 * A span with no text in it has nothing to fill (the HTML API has no way to insert a text node
 * there) and one with markup in it keeps its tags, the text going into the first text node.
 */
final class InlineValues
{
    private const ATTRIBUTE = 'data-kalaka-value';

    /**
     * On a page, a span naming a key the context does not declare is emptied and logged.
     *
     * @throws SystemFailure when a span names a key the context does not declare, outside a page
     */
    public function fill(string $html, ValueContext $context, bool $page = false): string
    {
        $processor = new \WP_HTML_Tag_Processor($html);
        $depth = 0;
        $text = '';
        $filled = false;
        while ($processor->next_token()) {
            $token = $processor->get_token_type();
            if ($token === '#text' && $depth > 0) {
                $processor->set_modifiable_text($filled ? '' : $text);
                $filled = true;
            } elseif ($token === '#tag' && $processor->get_tag() === 'SPAN') {
                if ($processor->is_tag_closer()) {
                    $depth = max(0, $depth - 1);
                } elseif ($depth > 0) {
                    ++$depth;
                } else {
                    $key = $processor->get_attribute(self::ATTRIBUTE);
                    if ($key !== null) {
                        $text = $this->text($context, is_string($key) ? $key : '', $page);
                        $processor->remove_attribute(self::ATTRIBUTE);
                        $depth = 1;
                        $filled = false;
                    }
                }
            }
        }

        return $processor->get_updated_html();
    }

    /** @throws SystemFailure */
    private function text(ValueContext $context, string $key, bool $page): string
    {
        try {
            return $context->get($key)->text;
        } catch (SystemFailure $e) {
            if (!$page) {
                throw $e;
            }
            RenderStack::reportOnPage($e);

            return '';
        }
    }
}
