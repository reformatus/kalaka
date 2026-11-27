<?php

declare(strict_types=1);

namespace Kalaka\Forms\Engine\Value;

use Kalaka\Forms\Api\ValueContext;
use Kalaka\Foundation\Api\Error\SystemFailure;

/**
 * Renders block markup with a value context: the chains resolved, then the bindings and inline values
 * filled. The placeholders typed as `{{key}}` are filled in the blocks' own HTML before anything renders, so a
 * value's text is never read for one, and an unknown typed key is a failure everywhere: a document (a mail body)
 * stops at the first failure, a page fails as a whole (owner 2026-11-27, walkthrough 8.11 a). A page renders
 * only the placeholders inside Kaláka blocks, and a failing chain, binding or inline value as nothing, logged.
 * [UNAPPROVED N1180]
 */
final class Renderer
{
    public function __construct(
        private readonly ChainPass $chains = new ChainPass(),
        private readonly InlineValues $inline = new InlineValues(),
        private readonly Placeholders $placeholders = new Placeholders(),
    ) {
    }

    /** @throws SystemFailure only when it is not a page */
    public function render(string $content, ValueContext $context, bool $page = false): string
    {
        RenderStack::push($context, $page);
        try {
            $html = '';
            $blocks = $this->chains->resolve(array_values(parse_blocks($content)), $context, $page);
            foreach ($this->placeholders->fillBlocks($blocks, $context, $page) as $block) {
                $html .= render_block($block);
            }

            return $this->inline->fill($html, $context, $page);
        } finally {
            RenderStack::pop();
        }
    }
}
