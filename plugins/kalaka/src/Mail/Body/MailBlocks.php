<?php

declare(strict_types=1);

namespace Kalaka\Mail\Body;

use Kalaka\Portal\Api\MailMoney;

/**
 * The two blocks an admin places in a mail body for money (owner topic 15, G2): the money table
 * `kalaka/mail-money` and the transfer details `kalaka/mail-transfer`. Each renders, at send time, the
 * registration whose mail `MailRenderer` is rendering, through the same read as the status page, in mail markup.
 * Outside a mail's render (a page, the editor's preview) they render nothing.
 */
final class MailBlocks
{
    public const MONEY_BLOCK = 'kalaka/mail-money';

    public const TRANSFER_BLOCK = 'kalaka/mail-transfer';

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
