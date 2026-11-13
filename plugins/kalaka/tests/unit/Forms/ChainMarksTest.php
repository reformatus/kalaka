<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Forms;

use Kalaka\Forms\Condition\ChainMarks;
use PHPUnit\Framework\TestCase;

/** S76 follow-up (N7): one numbering of the chains, on the blocks the page renders. */
final class ChainMarksTest extends TestCase
{
    /**
     * @param list<array<string, mixed>> $inner
     * @return array<string, mixed>
     */
    private static function block(string $name, array $inner = []): array
    {
        return [
            'blockName' => $name,
            'attrs' => [],
            'innerBlocks' => $inner,
            'innerHTML' => '',
            'innerContent' => array_fill(0, count($inner), null),
        ];
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @return list<array{string, int, int}> every marked branch in document order: name, chain, branch
     */
    private static function marks(array $blocks): array
    {
        $found = [];
        foreach ($blocks as $block) {
            $marks = ChainMarks::of($block);
            if ($marks !== null) {
                $found[] = [(string) $block['blockName'], $marks[0], $marks[1]];
            }
            /** @var list<array<string, mixed>> $inner */
            $inner = $block['innerBlocks'];
            array_push($found, ...self::marks($inner));
        }

        return $found;
    }

    public function testChainsAreNumberedDepthFirstAChainBeforeTheChainsInsideIt(): void
    {
        $blocks = [
            self::block('kalaka/text-field'),
            self::block('kalaka/if', [self::block('kalaka/if'), self::block('kalaka/else')]),
            self::block('kalaka/else-if'),
            self::block('kalaka/else', [self::block('core/group', [self::block('kalaka/if')])]),
            ['blockName' => null, 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => "\n", 'innerContent' => ["\n"]],
            self::block('kalaka/if'),
        ];

        self::assertSame(
            [
                ['kalaka/if', 0, 0],
                ['kalaka/if', 1, 0],
                ['kalaka/else', 1, 1],
                ['kalaka/else-if', 0, 1],
                ['kalaka/else', 0, 2],
                ['kalaka/if', 2, 0],
                ['kalaka/if', 3, 0],
            ],
            self::marks(ChainMarks::mark($blocks))
        );
    }

    public function testTopLevelPagesAreNumberedInDocumentOrderAndChainsKeepCountingAcrossThem(): void
    {
        $blocks = [
            self::block('kalaka/form-page', [self::block('kalaka/if')]),
            ['blockName' => null, 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => "\n", 'innerContent' => ["\n"]],
            self::block('kalaka/form-page', [self::block('kalaka/if'), self::block('kalaka/form-page')]),
        ];

        $marked = ChainMarks::mark($blocks);

        self::assertSame(0, ChainMarks::pageOf($marked[0]));
        self::assertNull(ChainMarks::pageOf($marked[1]));
        self::assertSame(1, ChainMarks::pageOf($marked[2]));
        // A page inside a page is not numbered: the definition refuses it.
        self::assertNull(ChainMarks::pageOf($marked[2]['innerBlocks'][1]));
        self::assertSame([['kalaka/if', 0, 0], ['kalaka/if', 1, 0]], self::marks($marked));
    }

    public function testAMarkInTheStoredMarkupIsOverwrittenAndOtherBlocksCarryNone(): void
    {
        $forged = self::block('kalaka/if');
        $forged['attrs'] = [ChainMarks::CHAIN_ATTRIBUTE => 9, ChainMarks::BRANCH_ATTRIBUTE => 9];
        $field = self::block('kalaka/text-field');

        $marked = ChainMarks::mark([$field, $forged]);

        self::assertNull(ChainMarks::of($marked[0]));
        self::assertSame([0, 0], ChainMarks::of($marked[1]));
    }
}
