<?php

declare(strict_types=1);

namespace Kalaka\Forms\Engine\Value;

use Kalaka\Forms\Api\ValueContext;
use Kalaka\Foundation\Api\Error\SystemFailure;

/**
 * Renders block markup with a value context: the chains resolved, then the bindings and inline values
 * filled. A document (a mail body) stops at the first failure; a page renders the failing chain or value
 * as nothing and logs it.
 */
final class Renderer
{
    public function __construct(
        private readonly ChainPass $chains = new ChainPass(),
        private readonly InlineValues $inline = new InlineValues(),
    ) {
    }

    /** @throws SystemFailure only when it is not a page */
    public function render(string $content, ValueContext $context, bool $page = false): string
    {
        RenderStack::push($context, $page);
        try {
            $html = '';
            foreach ($this->chains->resolve(array_values(parse_blocks($content)), $context, $page) as $block) {
                $html .= render_block($block);
            }

            return $this->inline->fill($html, $context, $page);
        } finally {
            RenderStack::pop();
        }
    }
}
