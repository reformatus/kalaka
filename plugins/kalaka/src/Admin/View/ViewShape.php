<?php

declare(strict_types=1);

namespace Kalaka\Admin\View;

use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Registrations\Api\RegistrationStatus;

/**
 * A validated filter and column list: foundation.md, "Views and grants". The filter is a list of terms
 * that must all hold; a term is a column with the strings it may hold, or the one parameter term
 * (`in` null) that is bound when access is checked. The stored JSON is always the canonical form.
 */
final readonly class ViewShape
{
    private const FIELD_KEY = '/^[a-z][a-z0-9_]{0,63}$/D';

    private const DECISION_KIND = '/^[a-z][a-z0-9_]*$/D';

    private const KIND_LENGTH = 50;

    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /**
     * @param list<array{column: string, in: ?list<string>}> $filter
     * @param list<array{column: string, write: bool}> $columns
     */
    private function __construct(public array $filter, public array $columns)
    {
    }

    /**
     * @throws InvalidInput
     */
    public static function of(mixed $filter, mixed $columns): self
    {
        return new self(self::terms($filter), self::columnList($columns));
    }

    /**
     * The shape of a stored view.
     *
     * @throws SystemFailure when the stored JSON is not a valid shape
     */
    public static function fromStored(string $filter, string $columns): self
    {
        try {
            return self::of(json_decode($filter, true), json_decode($columns, true));
        } catch (InvalidInput $e) {
            $error = new SystemFailure(null, new \UnexpectedValueException('A stored view is malformed.', 0, $e));
            throw $error;
        }
    }

    /**
     * The master view: no filter, `code`, `status` and `serial`, every given field key as a read-write
     * column, then every given decision kind.
     *
     * @param list<string> $fieldKeys
     * @param list<string> $decisionKinds
     * @throws InvalidInput
     */
    public static function master(array $fieldKeys, array $decisionKinds): self
    {
        $columns = [
            ['column' => 'code', 'write' => false],
            ['column' => 'status', 'write' => false],
            ['column' => 'serial', 'write' => false],
        ];
        foreach ($fieldKeys as $key) {
            $columns[] = ['column' => 'field:' . $key, 'write' => true];
        }
        foreach ($decisionKinds as $kind) {
            $columns[] = ['column' => 'decision:' . $kind, 'write' => false];
        }

        return self::of([], $columns);
    }

    public function filterJson(): string
    {
        $terms = [];
        foreach ($this->filter as $term) {
            $terms[] = $term['in'] === null
                ? ['column' => $term['column'], 'parameter' => true]
                : ['column' => $term['column'], 'in' => $term['in']];
        }

        return self::encode($terms);
    }

    public function columnsJson(): string
    {
        return self::encode($this->columns);
    }

    public function hasParameter(): bool
    {
        return $this->parameterColumn() !== null;
    }

    /** The column of the parameter term, bound per grant; null when the filter has none. */
    public function parameterColumn(): ?string
    {
        foreach ($this->filter as $term) {
            if ($term['in'] === null) {
                return $term['column'];
            }
        }

        return null;
    }

    /** Whether the column is listed and read-write. */
    public function writes(string $column): bool
    {
        foreach ($this->columns as $listed) {
            if ($listed['column'] === $column) {
                return $listed['write'];
            }
        }

        return false;
    }

    public function lists(string $column): bool
    {
        foreach ($this->columns as $listed) {
            if ($listed['column'] === $column) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> the field keys of the field columns, in column order */
    public function fieldKeys(): array
    {
        return $this->suffixes('field:');
    }

    /** @return list<string> the kinds of the decision columns, in column order */
    public function decisionKinds(): array
    {
        return $this->suffixes('decision:');
    }

    /** @return list<string> */
    private function suffixes(string $prefix): array
    {
        $suffixes = [];
        foreach ($this->columns as $listed) {
            if (str_starts_with($listed['column'], $prefix)) {
                $suffixes[] = substr($listed['column'], strlen($prefix));
            }
        }

        return $suffixes;
    }

    /**
     * @return list<array{column: string, in: ?list<string>}>
     * @throws InvalidInput
     */
    private static function terms(mixed $filter): array
    {
        if (!is_array($filter) || !array_is_list($filter)) {
            throw new InvalidInput();
        }

        $terms = [];
        $parameters = 0;
        foreach ($filter as $term) {
            if (!is_array($term) || !is_string($term['column'] ?? null) || !self::validColumn($term['column'])) {
                throw new InvalidInput();
            }
            $column = $term['column'];
            if ($column === 'code' || $column === 'serial') {
                throw new InvalidInput();
            }

            if (self::hasExactly($term, 'column', 'parameter') && $term['parameter'] === true) {
                $parameters++;
                $terms[] = ['column' => $column, 'in' => null];
                continue;
            }
            if (!self::hasExactly($term, 'column', 'in')) {
                throw new InvalidInput();
            }
            $terms[] = ['column' => $column, 'in' => self::values($column, $term['in'])];
        }
        if ($parameters > 1) {
            throw new InvalidInput();
        }

        return $terms;
    }

    /**
     * @return list<string>
     * @throws InvalidInput
     */
    private static function values(string $column, mixed $values): array
    {
        if (!is_array($values) || !array_is_list($values) || $values === []) {
            throw new InvalidInput();
        }
        foreach ($values as $value) {
            if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
                throw new InvalidInput();
            }
            if ($column === 'status' && RegistrationStatus::tryFrom($value) === null) {
                throw new InvalidInput();
            }
        }

        return $values;
    }

    /**
     * @return list<array{column: string, write: bool}>
     * @throws InvalidInput
     */
    private static function columnList(mixed $columns): array
    {
        if (!is_array($columns) || !array_is_list($columns) || $columns === []) {
            throw new InvalidInput();
        }

        $list = [];
        $seen = [];
        foreach ($columns as $item) {
            if (
                !is_array($item)
                || !self::hasExactly($item, 'column', 'write')
                || !is_string($item['column'])
                || !is_bool($item['write'])
                || !self::validColumn($item['column'])
                || isset($seen[$item['column']])
                || ($item['write'] && !str_starts_with($item['column'], 'field:'))
            ) {
                throw new InvalidInput();
            }
            $seen[$item['column']] = true;
            $list[] = ['column' => $item['column'], 'write' => $item['write']];
        }

        return $list;
    }

    /** @param array<mixed> $item */
    private static function hasExactly(array $item, string ...$keys): bool
    {
        return count($item) === count($keys) && array_diff_key(array_flip($keys), $item) === [];
    }

    private static function validColumn(string $column): bool
    {
        if (in_array($column, ['code', 'status', 'serial'], true)) {
            return true;
        }
        if (str_starts_with($column, 'field:')) {
            return preg_match(self::FIELD_KEY, substr($column, 6)) === 1;
        }
        if (str_starts_with($column, 'decision:')) {
            $kind = substr($column, 9);

            return strlen($kind) <= self::KIND_LENGTH && preg_match(self::DECISION_KIND, $kind) === 1;
        }

        return false;
    }

    /** @param list<array<string, mixed>> $list */
    private static function encode(array $list): string
    {
        $json = wp_json_encode($list, self::JSON_FLAGS);
        if ($json === false) {
            $error = new SystemFailure(null, new \LogicException('A view shape is not JSON-encodable.'));
            throw $error;
        }

        return $json;
    }
}
