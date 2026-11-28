<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Registrations;

use Kalaka\Forms\Api\AnswerSet;
use Kalaka\Forms\Api\DraftClaimChoice;
use Kalaka\Registrations\Draft\Draft;
use Kalaka\Registrations\Draft\DraftChoice;
use PHPUnit\Framework\TestCase;

/** What a choice does to the draft that stays (S74, N1106): shared by a person's and a guest's. */
final class DraftChoiceTest extends TestCase
{
    private const HASH = 'ab12';

    /** @param array<string, string> $answers */
    private function draft(array $answers, int $version = 3): Draft
    {
        $at = new \DateTimeImmutable('2026-11-20 10:00:00', new \DateTimeZone('UTC'));

        return new Draft(1, 7, $version, null, 'hash', new AnswerSet($answers), 0, $at, $at, claims: 2);
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-27 10:00:00', new \DateTimeZone('UTC'));
    }

    public function testMergeWritesTheIncomingAnswersOverTheKeptAndKeepsTheRest(): void
    {
        $kept = $this->draft(['name' => 'a', 'note' => 'régi']);

        $incoming = $this->draft(['note' => 'új', 'email' => 'e'], 5);
        DraftChoice::apply($kept, $incoming, DraftClaimChoice::Merge, self::HASH, $this->now());

        self::assertSame(['name' => 'a', 'note' => 'új', 'email' => 'e'], $kept->answers->answers);
        self::assertSame(5, $kept->formVersion);
        self::assertSame(3, $kept->claims);
        self::assertSame(self::HASH, $kept->resolvedClaim);
        self::assertSame('merge', $kept->resolvedChoice);
        self::assertEquals($this->now(), $kept->updatedAt);
    }

    public function testKeepLinkReplacesTheAnswersWithTheIncomingAndRaisesTheCount(): void
    {
        $kept = $this->draft(['name' => 'a']);

        $incoming = $this->draft(['note' => 'új'], 1);
        DraftChoice::apply($kept, $incoming, DraftClaimChoice::KeepLink, self::HASH, $this->now());

        self::assertSame(['note' => 'új'], $kept->answers->answers);
        self::assertSame(3, $kept->formVersion);
        self::assertSame(3, $kept->claims);
        self::assertSame('keep_link', $kept->resolvedChoice);
    }

    public function testKeepDraftChangesNothingButRecordsTheClaim(): void
    {
        $kept = $this->draft(['name' => 'a']);
        $updated = $kept->updatedAt;

        $incoming = $this->draft(['note' => 'új']);
        DraftChoice::apply($kept, $incoming, DraftClaimChoice::KeepDraft, self::HASH, $this->now());

        self::assertSame(['name' => 'a'], $kept->answers->answers);
        self::assertSame(2, $kept->claims);
        self::assertSame($updated, $kept->updatedAt);
        self::assertSame(self::HASH, $kept->resolvedClaim);
        self::assertSame('keep_draft', $kept->resolvedChoice);
    }
}
