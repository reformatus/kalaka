<?php

declare(strict_types=1);

namespace Kalaka\Foundation;

/**
 * The lock guard's view of a row key: the same row read as `'5'` and given as `5` is one key, and
 * keys sort by table name, then column by column (foundation.md, "Lock order").
 *
 * @internal
 */
final class LockKey
{
    /**
     * An int, or a string of digits without a leading zero, becomes an int; any other value stays a string.
     *
     * @param list<int|string> $key
     * @return list<int|string>
     */
    public static function normalise(array $key): array
    {
        $normalised = [];
        foreach ($key as $value) {
            // The round trip keeps a string too long for an int a string.
            $digits = is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/', $value) === 1;
            if ($digits && (string) (int) $value === $value) {
                $value = (int) $value;
            }
            $normalised[] = $value;
        }

        return $normalised;
    }

    /** @param list<int|string> $key a normalised key */
    public static function identity(string $table, array $key): string
    {
        return serialize([$table, $key]);
    }

    /**
     * Table names byte-wise, then the key columns in key order: ints as numbers and before strings,
     * strings byte-wise. Both keys are normalised.
     *
     * @param list<int|string> $a
     * @param list<int|string> $b
     * @return int<-1, 1>
     */
    public static function compare(string $tableA, array $a, string $tableB, array $b): int
    {
        $order = strcmp($tableA, $tableB);
        foreach ($a as $i => $left) {
            if ($order !== 0) {
                break;
            }
            $right = $b[$i] ?? '';
            if (is_int($left) && is_int($right)) {
                $order = $left <=> $right;
            } elseif (is_int($left) || is_int($right)) {
                $order = is_int($left) ? -1 : 1;
            } else {
                $order = strcmp($left, $right);
            }
        }

        return $order <=> 0;
    }
}
