<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

/**
 * The person's choice when a link's or the quiz's answers meet their own draft (S74, owner ruling
 * 2026-11-14): merge (the link's answers written over the draft's, the rest kept), keep the draft and drop
 * the link, or keep the link and drop the draft.
 */
enum DraftClaimChoice: string
{
    case Merge = 'merge';
    case KeepDraft = 'keep_draft';
    case KeepLink = 'keep_link';
}
