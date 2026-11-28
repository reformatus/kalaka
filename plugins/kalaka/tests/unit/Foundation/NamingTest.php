<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Foundation;

use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Naming\DeclaredName;
use Kalaka\Foundation\Api\Naming\NameStore;
use Kalaka\Foundation\Api\Naming\PluginNames;
use PHPUnit\Framework\TestCase;

final class NamingTest extends TestCase
{
    public function testTheDeclaredDefaultShowsWhenNothingIsStored(): void
    {
        $this->assertSame('Alap', $this->names(null)->get('kind'));
    }

    public function testAStoredNameOverridesTheDefaultOfItsOwnKeyOnly(): void
    {
        $names = $this->names(['kind' => 'Egyház']);

        $this->assertSame('Egyház', $names->get('kind'));
        $this->assertSame('Sor', $names->get('line'));
    }

    public function testAnEmptyOrWhitespaceOnlyValueFallsBackToTheDefault(): void
    {
        $names = $this->names(['kind' => " \t\u{00A0} ", 'line' => '']);

        $this->assertSame('Alap', $names->get('kind'));
        $this->assertSame('Sor', $names->get('line'));
    }

    public function testStoredValuesOfTheWrongShapeOrTooLongFallBackWithoutThrowing(): void
    {
        $tooLong = str_repeat('a', PluginNames::MAX_LENGTH + 1);
        $values = [[], 'text', 7, ['kind' => 5], ['kind' => ['x']], ['kind' => $tooLong]];
        $values[] = ['kind' => "Alap\xFF"];
        foreach ($values as $stored) {
            $this->assertSame('Alap', $this->names($stored)->get('kind'));
        }
    }

    public function testASavedValueIsTrimmedAndAnEmptyOneIsDropped(): void
    {
        $kept = $this->names(null)->checked(['kind' => '  Egyház ', 'line' => '   ']);

        $this->assertSame(['kind' => 'Egyház'], $kept);
    }

    public function testTheLongestAllowedValueIsKeptAndOneLongerIsRefused(): void
    {
        $longest = str_repeat('é', PluginNames::MAX_LENGTH);
        $this->assertSame(['kind' => $longest], $this->names(null)->checked(['kind' => $longest]));

        $this->expectException(InvalidInput::class);
        $this->names(null)->checked(['kind' => $longest . 'é']);
    }

    public function testAnUndeclaredKeyOrANonTextValueIsRefused(): void
    {
        foreach ([['other' => 'x'], ['kind' => 5], ['kind' => ['x']], 'text'] as $input) {
            try {
                $this->names(null)->checked($input);
                $this->fail('Expected a refusal.');
            } catch (InvalidInput) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAnInvalidUtf8ValueIsRefusedRatherThanDropped(): void
    {
        $this->expectException(InvalidInput::class);

        $this->names(null)->checked(['kind' => "Alap\xFF"]);
    }

    public function testAnUndeclaredNameCannotBeRead(): void
    {
        $this->expectException(\LogicException::class);
        $this->names(null)->get('other');
    }

    public function testADeclarationNeedsAnIdentifierKeyAUsableDefaultAndOneDeclaration(): void
    {
        $builds = [
            static fn() => new PluginNames(self::store(null), [new DeclaredName('a', 'x'), new DeclaredName('a', 'y')]),
            static fn() => new DeclaredName('Bad key', 'x'),
            static fn() => new DeclaredName('a', '  '),
            static fn() => new DeclaredName('a', str_repeat('a', PluginNames::MAX_LENGTH + 1)),
        ];
        foreach ($builds as $build) {
            try {
                $build();
                $this->fail('Expected an invalid declaration.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function names(mixed $stored): PluginNames
    {
        $declared = [new DeclaredName('kind', 'Alap'), new DeclaredName('line', 'Sor')];

        return new PluginNames(self::store($stored), $declared);
    }

    private static function store(mixed $stored): NameStore
    {
        return new class ($stored) implements NameStore {
            public function __construct(private readonly mixed $stored)
            {
            }

            public function read(): mixed
            {
                return $this->stored;
            }
        };
    }
}
