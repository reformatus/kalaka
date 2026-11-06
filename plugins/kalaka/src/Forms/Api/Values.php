<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Forms\Value\Renderer;

/** The value API's facade: renders a document's block markup with a context's values. */
final class Values
{
    public function __construct(private readonly Renderer $renderer = new Renderer())
    {
    }

    /**
     * Renders block markup (a mail body, a portal page): every chain is resolved first and only the
     * held branch's inner blocks render, with no wrapper; then `kalaka/value` bindings and inline
     * values are filled from the context. Outside this call the branch blocks render nothing and
     * bindings and inline values stay as authored.
     *
     * @throws SystemFailure a misplaced branch, an invalid condition or a key the context does not declare
     */
    public function render(string $content, ValueContext $context): string
    {
        return $this->renderer->render($content, $context);
    }
}
