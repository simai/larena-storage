<?php

declare(strict_types=1);

namespace Larena\Storage\View;

use Larena\Storage\Exceptions\StorageRejected;

/**
 * One field of a storage in its витрина: a typed column `cN`, or, for a
 * multi-value field, rows in the side table (no column).
 *
 * States: `adding` (reserved; the column may not exist yet and is not written),
 * `ready` (written on every write), `backfilling` (written, older rows not yet
 * filled), `dropping` (no longer written; dropped after commit).
 */
final readonly class StorageViewColumn
{
    public const SQL_BIGINT = 'bigint';
    public const SQL_DECIMAL = 'decimal';
    public const SQL_DATE = 'date';
    public const SQL_DATETIME = 'datetime';
    public const SQL_TINYINT = 'tinyint';
    public const SQL_REF = 'ref64';
    public const SQL_USER_REF = 'ref96';
    public const SQL_VARCHAR = 'varchar255';
    public const SQL_TEXT = 'text';
    public const SQL_SIDE = 'side';
    public const SQL_TYPES = [
        self::SQL_BIGINT, self::SQL_DECIMAL, self::SQL_DATE, self::SQL_DATETIME, self::SQL_TINYINT,
        self::SQL_REF, self::SQL_USER_REF, self::SQL_VARCHAR, self::SQL_TEXT, self::SQL_SIDE,
    ];

    public const STATE_ADDING = 'adding';
    public const STATE_READY = 'ready';
    public const STATE_BACKFILLING = 'backfilling';
    public const STATE_DROPPING = 'dropping';
    public const STATES = [self::STATE_ADDING, self::STATE_READY, self::STATE_BACKFILLING, self::STATE_DROPPING];

    public function __construct(
        public string $fieldKey,
        public ?string $column,
        public string $kind,
        public string $sqlType,
        public bool $indexed,
        public string $state,
    ) {
        if (preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $fieldKey) !== 1
            || preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $kind) !== 1
            || !in_array($sqlType, self::SQL_TYPES, true)
            || !in_array($state, self::STATES, true)
            || ($sqlType === self::SQL_SIDE) !== ($column === null)
            || ($column !== null && preg_match('/\Ac[1-9][0-9]{0,8}\z/D', $column) !== 1)
            || ($indexed && $column === null)) {
            throw new StorageRejected('storage_view_layout_invalid');
        }
    }

    public function multiValued(): bool
    {
        return $this->column === null;
    }

    public function ordinal(): int
    {
        return $this->column === null ? 0 : (int) substr($this->column, 1);
    }

    /** True when every write sets the column. */
    public function written(): bool
    {
        return $this->state === self::STATE_READY || $this->state === self::STATE_BACKFILLING;
    }

    public function withState(string $state): self
    {
        return new self($this->fieldKey, $this->column, $this->kind, $this->sqlType, $this->indexed, $state);
    }

    public function withType(string $sqlType, bool $indexed): self
    {
        return new self($this->fieldKey, $this->column, $this->kind, $sqlType, $indexed, $this->state);
    }

    /** The same physical column: same name, type and index. */
    public function samePhysical(self $other): bool
    {
        return $this->column === $other->column && $this->sqlType === $other->sqlType && $this->indexed === $other->indexed;
    }

    /** @return array{field_key: string, column: ?string, kind: string, sql_type: string, indexed: bool, state: string} */
    public function toArray(): array
    {
        return [
            'field_key' => $this->fieldKey,
            'column' => $this->column,
            'kind' => $this->kind,
            'sql_type' => $this->sqlType,
            'indexed' => $this->indexed,
            'state' => $this->state,
        ];
    }

    /** @param array<array-key, mixed> $data */
    public static function fromArray(array $data): self
    {
        $keys = array_keys($data);
        sort($keys, SORT_STRING);
        if ($keys !== ['column', 'field_key', 'indexed', 'kind', 'sql_type', 'state']
            || !is_string($data['field_key']) || !(is_string($data['column']) || $data['column'] === null)
            || !is_string($data['kind']) || !is_string($data['sql_type']) || !is_bool($data['indexed'])
            || !is_string($data['state'])) {
            throw new StorageRejected('storage_view_layout_invalid');
        }

        return new self($data['field_key'], $data['column'], $data['kind'], $data['sql_type'], $data['indexed'], $data['state']);
    }
}
