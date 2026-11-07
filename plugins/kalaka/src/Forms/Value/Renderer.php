<?php

declare(strict_types=1);

namespace Kalaka\Forms\Value;

use Kalaka\Forms\Api\ValueContext;
use Kalaka\Foundation\Api\Error\SystemFailure;

/** Renders block markup with a value context: the chains resolved, then the bindings and inline values filled. */
final class Renderer
{
    public function __construct(
        private readonly ChainPass $chains = new ChainPass(),
        private readonly InlineValues $inline = new InlineValues(),
    ) {
    }

    /** @throws SystemFailure */
    public function render(string $content, ValueContext $context): string
    {
        RenderStack::push($context);
        try {
            $html = '';
            foreach ($this->chains->resolve(array_values(parse_blocks($content)), $context) as $block) {
                $html .= render_block($block);
            }

            return $this->inline->fill($html, $context);
        } finally {
            RenderStack::pop();
        }
    }
}
