<?php

declare(strict_types=1);

namespace Kalaka\Foundation;

final class ApiVersionRange
{
    /**
     * @param list<array{string, string}> $constraints Pairs of operator and version.
     */
    private function __construct(private readonly array $constraints)
    {
    }

    public static function parse(string $range): self
    {
        $parts = preg_split('/ /', $range);
        if ($parts === false || count($parts) < 1 || count($parts) > 2) {
            throw new \InvalidArgumentException('Invalid API range.');
        }

        $constraints = [];
        foreach ($parts as $part) {
            if (preg_match('/^(>=|<)(\d+\.\d+(?:\.\d+)?)$/', $part, $m) !== 1) {
                throw new \InvalidArgumentException('Invalid API range.');
            }
            $constraints[] = [$m[1], self::normalize($m[2])];
        }

        return new self($constraints);
    }

    public function allows(string $version): bool
    {
        foreach ($this->constraints as [$operator, $bound]) {
            if (!version_compare($version, $bound, $operator)) {
                return false;
            }
        }

        return true;
    }

    private static function normalize(string $version): string
    {
        return substr_count($version, '.') === 1 ? $version . '.0' : $version;
    }
}
