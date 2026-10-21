<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

use JsonException;
use Larena\Storage\Enums\StorageWorkbenchCountMode;
use Larena\Storage\Exceptions\ReadContractRejected;
use Larena\Storage\View\StorageRecordQueryNormalizer;

/**
 * One public list query (PublishedListReads).
 *
 * - `target`: a structure role reference (`role@vN`) or a storage schema id.
 * - `filters`: field key => `{operator, value}` or `{operator, values}`, the shape of
 *   the workbench record query (StorageRecordQueryNormalizer), public fields only.
 * - `search`: words matched at the start of a word of the public searchable fields.
 * - `sort`: up to three `{field, direction}` over public fields, `_published_at` (when
 *   the record was published in the scope and locale) or `_record_id`; ties, and an
 *   empty sort, follow the record id.
 * - `limit`: 1..MAX_LIMIT.
 * - `continuation`: the cursor a previous page returned.
 * - `countMode`: Skip (default: no total), Auto (exact under a threshold, an estimate
 *   above it) or Exact.
 */
final readonly class PublishedListQuery
{
    public const MAX_LIMIT = 100;
    public const DEFAULT_LIMIT = 20;
    /** The longest filters JSON a request may carry. */
    public const MAX_FILTERS_JSON_BYTES = 8192;
    /** The longest continuation a request may carry. */
    public const MAX_CONTINUATION_BYTES = 4096;
    public const SORT_PUBLISHED_AT = '_published_at';
    public const SORT_RECORD_ID = '_record_id';
    public const SYSTEM_SORTS = [self::SORT_PUBLISHED_AT, self::SORT_RECORD_ID];

    /**
     * @param array<array-key, mixed> $filters
     * @param array<array-key, mixed> $sort
     */
    public function __construct(
        public string $target,
        public string $scopeRef,
        public string $locale,
        public array $filters = [],
        public ?string $search = null,
        public array $sort = [],
        public int $limit = self::DEFAULT_LIMIT,
        public ?string $continuation = null,
        public StorageWorkbenchCountMode $countMode = StorageWorkbenchCountMode::Skip,
    ) {
    }

    /**
     * The query of an operation's input or a REST query string: `filters` as an object
     * or a JSON string, `sort` as a list of `{field, direction}` or as
     * `field[:asc|:desc],...`, `limit` as an integer or its digits, `cursor` and
     * `count` (`auto`, `exact` or `skip`) as strings. Anything else is `invalid_query`.
     *
     * @param array<array-key, mixed> $input
     */
    public static function fromInput(array $input): self
    {
        $string = static function (string $key) use ($input): string {
            $value = $input[$key] ?? null;
            if (!is_string($value) || trim($value) === '') {
                throw self::invalid();
            }

            return $value;
        };
        $optional = static function (string $key) use ($input): ?string {
            $value = $input[$key] ?? null;
            if ($value === null || $value === '') {
                return null;
            }
            if (!is_string($value)) {
                throw self::invalid();
            }

            return $value;
        };

        $count = $optional('count');
        $mode = $count === null ? StorageWorkbenchCountMode::Skip : StorageWorkbenchCountMode::tryFrom($count);
        if ($mode === null) {
            throw self::invalid();
        }
        $cursor = $optional('cursor');
        if ($cursor !== null && strlen($cursor) > self::MAX_CONTINUATION_BYTES) {
            throw self::invalid();
        }

        return new self(
            $string('target'),
            $string('scope_ref'),
            $string('locale'),
            self::filtersOf($input['filters'] ?? null),
            $optional('search'),
            self::sortOf($input['sort'] ?? null),
            self::limitOf($input['limit'] ?? null),
            $cursor,
            $mode,
        );
    }

    /** @return array<array-key, mixed> */
    private static function filtersOf(mixed $filters): array
    {
        if ($filters === null || $filters === '' || $filters === []) {
            return [];
        }
        if (is_string($filters)) {
            if (strlen($filters) > self::MAX_FILTERS_JSON_BYTES) {
                throw self::invalid();
            }
            try {
                $filters = json_decode($filters, true, 8, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw self::invalid();
            }
        }
        if (!is_array($filters) || ($filters !== [] && array_is_list($filters))) {
            throw self::invalid();
        }

        return $filters;
    }

    /** @return array<array-key, mixed> */
    private static function sortOf(mixed $sort): array
    {
        if ($sort === null || $sort === '' || $sort === []) {
            return [];
        }
        if (is_array($sort)) {
            return $sort;
        }
        if (!is_string($sort) || strlen($sort) > 256) {
            throw self::invalid();
        }
        $parts = explode(',', $sort);
        if (count($parts) > StorageRecordQueryNormalizer::MAX_SORTS) {
            throw self::invalid();
        }
        $items = [];
        foreach ($parts as $part) {
            if (preg_match('/\A(_?[a-z][a-z0-9_]{0,63})(?::(asc|desc))?\z/D', trim($part), $matches) !== 1) {
                throw self::invalid();
            }
            $items[] = ['field' => $matches[1], 'direction' => $matches[2] ?? 'asc'];
        }

        return $items;
    }

    private static function limitOf(mixed $limit): int
    {
        if ($limit === null || $limit === '') {
            return self::DEFAULT_LIMIT;
        }
        if (is_string($limit) && preg_match('/\A[1-9][0-9]{0,2}\z/D', $limit) === 1) {
            $limit = (int) $limit;
        }
        if (!is_int($limit) || $limit < 1 || $limit > self::MAX_LIMIT) {
            throw self::invalid();
        }

        return $limit;
    }

    private static function invalid(): ReadContractRejected
    {
        return new ReadContractRejected('invalid_query', 'The list query is invalid.');
    }
}
