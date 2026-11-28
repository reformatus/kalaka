<?php

declare(strict_types=1);

namespace Kalaka\Mail\Body;

use Kalaka\Portal\Api\MailMoney;

/**
 * The two blocks an admin places in a mail body for money (owner topic 15, G2): the money table
 * `kalaka/mail-money` and the transfer details `kalaka/mail-transfer`. Each renders, at send time, the
 * registration whose mail `MailRenderer` is rendering, through the same read as the status page, in mail markup.
 * Outside a mail's render (a page, the editor's preview) they render nothing.
 *
 * The same two things typed by hand (owner 2026-11-26, N960 option (a)): a paragraph or an HTML block that holds only
 * `{{money_table}}` or `{{transfer_details}}` is the matching block (`expand()`), so the typed key and the block
 * render through one path. The two keys are not values of the mail's context: placed anywhere else (inline, in an
 * attribute, in a binding or a condition) they are unknown placeholders, refused at save and a failed mail at send.
 */
final class MailBlocks
{
    public const MONEY_BLOCK = 'kalaka/mail-money';

    public const TRANSFER_BLOCK = 'kalaka/mail-transfer';

    /** The typed placeholder of the money table, and of the transfer details. */
    public const MONEY_KEY = 'money_table';

    public const TRANSFER_KEY = 'transfer_details';

    /** Where the typed key stands alone: its paragraph (`core/paragraph`) or its HTML block (`core/html`). */
    private const ALONE = [
        'core/paragraph' => '~\A\s*<p(?:\s[^>]*)?>\s*\{\{\s*(money_table|transfer_details)\s*\}\}\s*</p>\s*\z~',
        'core/html' => '~\A\s*\{\{\s*(money_table|transfer_details)\s*\}\}\s*\z~',
    ];

    /**
     * The body with each paragraph or HTML block that holds only `{{money_table}}` or `{{transfer_details}}` (at any
     * depth, a condition's branch included) replaced by the block that renders it. The stored body stays as typed:
     * the save check and the send both read it through here. A body with no such block is returned untouched.
     */
    public static function expand(string $content): string
    {
        if (!str_contains($content, '{{')) {
            return $content;
        }
        $changed = false;
        $blocks = self::expanded(array_values(parse_blocks($content)), $changed);

        /** @var array<array{blockName: string|null, attrs: array<string, mixed>, innerBlocks: array<array<string, mixed>>, innerHTML: string, innerContent: array<string|null>}> $blocks */
        return $changed ? serialize_blocks($blocks) : $content;
    }

    /**
     * @param list<array<string, mixed>> $blocks parsed blocks
     * @return list<array<string, mixed>>
     */
    private static function expanded(array $blocks, bool &$changed): array
    {
        foreach ($blocks as $index => $block) {
            $name = self::blockOf($block);
            if ($name !== null) {
                $blocks[$index] = [
                    'blockName' => $name,
                    'attrs' => [],
                    'innerBlocks' => [],
                    'innerHTML' => '',
                    'innerContent' => [],
                ];
                $changed = true;
            } elseif (is_array($block['innerBlocks'] ?? null) && $block['innerBlocks'] !== []) {
                $blocks[$index]['innerBlocks'] = self::expanded(array_values($block['innerBlocks']), $changed);
            }
        }

        return $blocks;
    }

    /** @param array<string, mixed> $block the block a typed key stands alone in: the block that renders it, or null */
    private static function blockOf(array $block): ?string
    {
        $name = $block['blockName'] ?? null;
        $html = $block['innerHTML'] ?? null;
        if (!is_string($name) || !is_string($html) || !isset(self::ALONE[$name])) {
            return null;
        }
        if (preg_match(self::ALONE[$name], $html, $found) !== 1) {
            return null;
        }

        return $found[1] === self::MONEY_KEY ? self::MONEY_BLOCK : self::TRANSFER_BLOCK;
    }

    public static function money(): string
    {
        $registration = MailInProgress::registration();

        return $registration === null ? '' : (new MailMoney())->table($registration);
    }

    public static function transfer(): string
    {
        $registration = MailInProgress::registration();

        return $registration === null ? '' : (new MailMoney())->transfer($registration);
    }

    /** @internal the `init` callback: the two blocks, from `build/` */
    public static function register(): void
    {
        $built = dirname(KALAKA_FILE) . '/build/Mail/Blocks/';
        $renders = [
            'money' => [self::MONEY_BLOCK, [self::class, 'money']],
            'transfer' => [self::TRANSFER_BLOCK, [self::class, 'transfer']],
        ];
        foreach ($renders as $folder => [$name, $render]) {
            if (is_file($built . $folder . '/block.json')) {
                register_block_type($built . $folder);
            } else {
                // Without `build/` a mail that places the block still renders it, from its server render only.
                register_block_type($name, ['render_callback' => static fn (): string => $render()]);
            }
        }
    }
}
