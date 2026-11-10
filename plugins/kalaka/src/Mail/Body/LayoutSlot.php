<?php

declare(strict_types=1);

namespace Kalaka\Mail\Body;

use Kalaka\Foundation\Api\Error\SystemFailure;

/**
 * The place of a mail's body in a layout: the one `kalaka/mail-content` block. A layout wraps a body by
 * having the body's blocks put in that block's place, so the whole mail is one document that renders once.
 */
final class LayoutSlot
{
    public const BLOCK = 'kalaka/mail-content';

    /** How many slot blocks the markup holds, at any depth: a usable layout holds exactly one. */
    public function count(string $layout): int
    {
        return $this->countIn(parse_blocks($layout));
    }

    /**
     * The layout's markup with the body's blocks in its slot's place.
     *
     * @throws SystemFailure when the layout does not hold exactly one slot (`layout_slot`)
     */
    public function wrap(string $layout, string $body): string
    {
        $blocks = parse_blocks($layout);
        if ($this->countIn($blocks) !== 1) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a problem code, never output
            throw new SystemFailure('layout_slot');
        }

        // @phpstan-ignore argument.type (parse_blocks() output, only rearranged)
        return serialize_blocks($this->replace($blocks, parse_blocks($body)));
    }

    /** @param array<array<string, mixed>> $blocks */
    private function countIn(array $blocks): int
    {
        $count = 0;
        foreach ($blocks as $block) {
            if (($block['blockName'] ?? null) === self::BLOCK) {
                $count++;
            }
            $inner = $block['innerBlocks'] ?? [];
            $count += is_array($inner) ? $this->countIn($inner) : 0;
        }

        return $count;
    }

    /**
     * @param array<array<string, mixed>> $blocks
     * @param array<array<string, mixed>> $body
     * @return list<array<string, mixed>>
     */
    private function replace(array $blocks, array $body): array
    {
        $result = [];
        foreach ($blocks as $block) {
            if (($block['blockName'] ?? null) === self::BLOCK) {
                array_push($result, ...$body);
                continue;
            }
            $inner = is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : [];
            if ($inner !== []) {
                $block = $this->replaceInner($block, $inner, $body);
            }
            $result[] = $block;
        }

        return $result;
    }

    /**
     * A block whose inner slot is replaced: each inner block has a `null` place in `innerContent`, so the
     * slot's one place becomes as many places as the body has blocks.
     *
     * @param array<string, mixed> $block
     * @param array<array<string, mixed>> $inner
     * @param array<array<string, mixed>> $body
     * @return array<string, mixed>
     */
    private function replaceInner(array $block, array $inner, array $body): array
    {
        $content = is_array($block['innerContent'] ?? null) ? $block['innerContent'] : [];
        $newInner = [];
        $newContent = [];
        $index = 0;
        foreach ($content as $piece) {
            if ($piece !== null) {
                $newContent[] = $piece;
                continue;
            }
            $child = $inner[$index++] ?? null;
            if (!is_array($child)) {
                continue;
            }
            if (($child['blockName'] ?? null) === self::BLOCK) {
                foreach ($body as $bodyBlock) {
                    $newInner[] = $bodyBlock;
                    $newContent[] = null;
                }
                continue;
            }
            $grandchildren = is_array($child['innerBlocks'] ?? null) ? $child['innerBlocks'] : [];
            $newInner[] = $grandchildren === [] ? $child : $this->replaceInner($child, $grandchildren, $body);
            $newContent[] = null;
        }
        $block['innerBlocks'] = $newInner;
        $block['innerContent'] = $newContent;

        return $block;
    }
}
