<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Forms\Engine\Value\DocumentCheck;
use Kalaka\Forms\Engine\Value\Renderer;

/** The value API's facade: renders a document's block markup with a context's values. */
final class Values
{
    public function __construct(
        private readonly Renderer $renderer = new Renderer(),
        private readonly DocumentCheck $check = new DocumentCheck(),
    ) {
    }

    /**
     * Checks a document's block markup (a mail body) when it is saved, against the keys and types its
     * context will declare: every chain's placement and condition, in every branch, and every binding and
     * inline value; and it holds no field block and no form-only block. What passes renders for any context
     * with those keys.
     *
     * @param array<string, ValueType> $declared
     * @throws SystemFailure `<code> <subject>`: a misplaced branch, an invalid condition, an undeclared key
     */
    public function check(string $content, array $declared): void
    {
        $this->check->check($content, $declared);
    }

    /**
     * The keys a document places as values (a `kalaka/value` binding or an inline value), found
     * by the same walk the save check and the render use; a key that only a condition names is not among
     * them. The mail's save check asks it for the set-password link.
     *
     * @return list<string>
     */
    public function placedKeys(string $content): array
    {
        return $this->check->placedKeys($content);
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
