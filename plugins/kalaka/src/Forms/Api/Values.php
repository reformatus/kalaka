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
     * Renders a document's block markup (a mail body): every chain is resolved first and only the
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

    /**
     * Renders an ordinary page's block markup (anything outside a form and a mail). Its context is the
     * visitor's active registration and its event; null when there is none, and then no chain renders a
     * branch and bindings and inline values stay as authored, as when WordPress renders the page. A chain
     * or value that fails (a key the context does not declare, an invalid condition, a misplaced branch)
     * renders nothing and is logged, never a fatal (#53 N4, owner 2026-11-13).
     *
     * The seam: nothing reads the active registration yet, so WordPress renders every page without a
     * context (no branch shows). The slice that adds `ActiveRegistration::current()` builds the context
     * and calls this for the page.
     */
    public function renderPage(string $content, ?ValueContext $context): string
    {
        return $context === null ? do_blocks($content) : $this->renderer->render($content, $context, true);
    }
}
