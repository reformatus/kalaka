<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Forms;

use Kalaka\Forms\Condition\Chain;
use Kalaka\Forms\Condition\Chains;
use Kalaka\Foundation\Api\Error\SystemFailure;
use PHPUnit\Framework\TestCase;

/** D7: where a chain begins and ends among sibling blocks. */
final class ChainsTest extends TestCase
{
    /** @return array<string, mixed> */
    private function block(string $name): array
    {
        return ['blockName' => $name, 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []];
    }

    /** @return array<string, mixed> */
    private function markup(string $html): array
    {
        return ['blockName' => null, 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => $html, 'innerContent' => [$html]];
    }

    /** @param list<array<string, mixed>|string> $sequence */
    private function names(array $sequence): string
    {
        $names = [];
        foreach (Chains::split($sequence) as $item) {
            if ($item instanceof Chain) {
                $names[] = '[' . implode(' ', array_map(static fn (array $b): string => (string) $b['blockName'], $item->branches)) . ']';
            } elseif (is_array($item)) {
                $names[] = (string) ($item['blockName'] ?? '~');
            } else {
                $names[] = '~';
            }
        }

        return implode(' ', $names);
    }

    public function testAnIfWithItsElseIfsAndAnElseIsOneChainWhateverWhitespaceIsBetween(): void
    {
        $sequence = [
            $this->block('kalaka/if'), $this->markup("\n\n"),
            $this->block('kalaka/else-if'), "\n",
            $this->block('kalaka/else-if'), ' ',
            $this->block('kalaka/else'),
            $this->block('core/paragraph'),
        ];

        self::assertSame('[kalaka/if kalaka/else-if kalaka/else-if kalaka/else] core/paragraph', $this->names($sequence));
    }

    public function testAnIfAloneIsAChainAndTheWhitespaceAfterItStays(): void
    {
        self::assertSame('[kalaka/if] ~ core/paragraph', $this->names([
            $this->block('kalaka/if'), $this->markup("\n"), $this->block('core/paragraph'),
        ]));
    }

    public function testAnotherIfStartsAnotherChain(): void
    {
        self::assertSame('[kalaka/if kalaka/else] [kalaka/if]', $this->names([
            $this->block('kalaka/if'), $this->block('kalaka/else'), $this->block('kalaka/if'),
        ]));
    }

    public function testNonWhitespaceMarkupOrABlockBetweenBreaksTheChain(): void
    {
        foreach ([$this->markup('<p>x</p>'), 'text', $this->block('core/paragraph')] as $between) {
            try {
                Chains::split([$this->block('kalaka/if'), $between, $this->block('kalaka/else')]);
                self::fail('The else was taken for a branch of the if.');
            } catch (SystemFailure $e) {
                self::assertStringContainsString('orphan_branch kalaka/else', $e->getMessage());
            }
        }
    }

    public function testAnElseIfOrElseWithoutAnIfBeforeItIsOrphaned(): void
    {
        foreach (['kalaka/else-if', 'kalaka/else'] as $name) {
            try {
                Chains::split([$this->block('core/paragraph'), $this->block($name)]);
                self::fail('The ' . $name . ' was accepted.');
            } catch (SystemFailure $e) {
                self::assertStringContainsString('orphan_branch ' . $name, $e->getMessage());
            }
        }
    }

    public function testABranchAfterAnElseIsOrphaned(): void
    {
        foreach (['kalaka/else-if', 'kalaka/else'] as $name) {
            try {
                Chains::split([$this->block('kalaka/if'), $this->block('kalaka/else'), $this->block($name)]);
                self::fail('The ' . $name . ' after an else was accepted.');
            } catch (SystemFailure $e) {
                self::assertStringContainsString('orphan_branch ' . $name, $e->getMessage());
            }
        }
    }

    public function testTheSiblingsOfAContainerAreItsBlocksAndTheMarkupBetweenThem(): void
    {
        $inner = [$this->block('kalaka/if'), $this->block('kalaka/else')];
        $container = [
            'blockName' => 'core/group',
            'attrs' => [],
            'innerBlocks' => $inner,
            'innerHTML' => '',
            'innerContent' => ['<div>', null, "\n", null, '</div>'],
        ];

        self::assertSame(['<div>', $inner[0], "\n", $inner[1], '</div>'], Chains::sequenceOf($container));
        self::assertSame('~ [kalaka/if kalaka/else] ~', $this->names(Chains::sequenceOf($container)));
    }
}
