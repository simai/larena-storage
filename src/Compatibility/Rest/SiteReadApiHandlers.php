<?php

declare(strict_types=1);

namespace Larena\Storage\Compatibility\Rest;

use Closure;
use Larena\Core\Contracts\OperationContext;
use Larena\Core\Contracts\OperationDescriptor;
use Larena\Rest\Contracts\OperationHandlerRegistry;
use Larena\Rest\Exceptions\ApiOperationException;
use InvalidArgumentException;
use Larena\Storage\Contracts\PublishedListQuery;
use Larena\Storage\Contracts\PublishedListReads;
use Larena\Storage\Contracts\PublishedReadVisibility;
use Larena\Storage\Contracts\ReadContracts;
use Larena\Storage\Exceptions\ReadContractRejected;

/**
 * Connects Storage's public read contracts to the REST public site boundary.
 *
 * Storage declares the operations in its api.yaml; an application that composes
 * Storage with REST registers these handlers (as Compatibility\Audit does for
 * Audit), so Storage never requires REST.
 *
 * Every miss is the same 404: a record that is unpublished, archived, ambiguous,
 * hidden from the caller or absent. The caller's filter comes from Storage's
 * PublishedReadVisibility, the same one the HTML route asks, so a REST caller gets
 * no more than a visitor of the page.
 */
final class SiteReadApiHandlers
{
    public const RESOLVE = 'storage.read.resolve_key';

    public const PROJECTION = 'storage.read.published_projection';

    public const LIST = 'storage.read.published_list';

    /**
     * @param Closure(): ReadContracts $read resolved lazily so registering at boot
     *                                      touches no database
     * @param Closure(): PublishedReadVisibility $visibility resolved lazily as well
     * @param (Closure(): PublishedListReads)|null $lists the filtered public lists, when Storage offers them
     */
    public static function register(OperationHandlerRegistry $handlers, Closure $read, Closure $visibility, ?Closure $lists = null): void
    {
        if ($lists !== null) {
            $handlers->register(
                self::LIST,
                static fn (array $input, OperationDescriptor $descriptor, OperationContext $context): array => self::list($lists(), $visibility(), $context->actorId, $input),
            );
        }
        $handlers->register(
            self::RESOLVE,
            static fn (array $input, OperationDescriptor $descriptor, OperationContext $context): array => self::resolve($read(), $visibility(), $context->actorId, $input),
        );
        $handlers->register(
            self::PROJECTION,
            static fn (array $input, OperationDescriptor $descriptor, OperationContext $context): array => self::projection($read(), $visibility(), $context->actorId, $input),
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private static function resolve(ReadContracts $read, PublishedReadVisibility $visibility, string $actor, array $input): array
    {
        $query = self::query($input);

        try {
            $resolved = $read->resolveKey(
                $query['target'],
                $query['scope_ref'],
                $query['locale'],
                (string) ($query['key_field'] ?? ''),
                (string) ($query['key_value'] ?? ''),
                $visibility->filterFor($actor, $query['target'], $query['scope_ref']),
            );
        } catch (ReadContractRejected) {
            // An ambiguous key is a data defect, and naming it would say a record
            // exists. It is the same miss as any other.
            $resolved = null;
        }

        if ($resolved === null) {
            throw new ApiOperationException('page_not_found', 404, 'Not found.');
        }

        return ['record' => [
            'record_id' => $resolved->recordId,
            'revision' => $resolved->revision,
            'fields' => self::fields($resolved->values),
        ]];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private static function projection(ReadContracts $read, PublishedReadVisibility $visibility, string $actor, array $input): array
    {
        $query = self::query($input);

        try {
            $page = $read->publishedProjection(
                $query['target'],
                $query['scope_ref'],
                $query['locale'],
                visibilityFilter: $visibility->filterFor($actor, $query['target'], $query['scope_ref']),
            );
        } catch (ReadContractRejected) {
            throw new ApiOperationException('page_not_found', 404, 'Not found.');
        }

        $records = [];
        foreach ($page->records as $record) {
            $records[] = [
                'record_id' => (string) ($record['record_id'] ?? ''),
                'revision' => (int) ($record['revision'] ?? 0),
                'fields' => self::fields(is_array($record['values'] ?? null) ? $record['values'] : []),
            ];
        }

        return ['records' => $records, 'returned' => count($records), 'truncated' => $page->truncated];
    }

    /**
     * One page of a filtered, searched and sorted public list. An invalid query (or one on a field that is not
     * public) is 422; a storage too large to list before its site витрина is ready is 503; any other refusal is
     * the same 404 as a missing page.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private static function list(PublishedListReads $lists, PublishedReadVisibility $visibility, string $actor, array $input): array
    {
        try {
            $query = PublishedListQuery::fromInput(is_array($input['query'] ?? null) ? $input['query'] : []);
            $page = $lists->publishedList($query, $visibility->filterFor($actor, $query->target, $query->scopeRef));
        } catch (InvalidArgumentException) {
            throw new ApiOperationException('invalid_query', 422, 'The list query is invalid.');
        } catch (ReadContractRejected $rejected) {
            throw match ($rejected->reasonCode) {
                'invalid_query' => new ApiOperationException('invalid_query', 422, 'The list query is invalid.'),
                'site_view_not_ready' => new ApiOperationException('site_view_not_ready', 503, 'The list is being prepared. Try again later.'),
                default => new ApiOperationException('page_not_found', 404, 'Not found.'),
            };
        }

        $records = [];
        foreach ($page->records as $record) {
            $records[] = [
                'record_id' => (string) ($record['record_id'] ?? ''),
                'revision' => (int) ($record['revision'] ?? 0),
                'fields' => self::fields(is_array($record['values'] ?? null) ? $record['values'] : []),
            ];
        }

        return ['records' => $records, 'returned' => count($records), 'continuation' => $page->continuation,
            'matched_count' => $page->matchedCount, 'count_exact' => $page->countExact, 'filtered_count' => $page->filteredCount];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{target: string, scope_ref: string, locale: string, key_field?: string, key_value?: string}
     */
    private static function query(array $input): array
    {
        $query = is_array($input['query'] ?? null) ? $input['query'] : [];
        foreach (['target', 'scope_ref', 'locale'] as $key) {
            if (!is_string($query[$key] ?? null) || $query[$key] === '') {
                throw new ApiOperationException('request_invalid', 422, 'The request is invalid.');
            }
        }

        /** @var array{target: string, scope_ref: string, locale: string, key_field?: string, key_value?: string} $query */
        return $query;
    }

    /**
     * Record values as key and value pairs: the REST schema is closed at every level,
     * and a record's fields depend on its structure. A structured value travels as
     * its JSON text.
     *
     * @param array<string, mixed> $values
     * @return list<array{key: string, value: string|int|float|bool|null}>
     */
    private static function fields(array $values): array
    {
        ksort($values);
        $fields = [];
        foreach ($values as $key => $value) {
            if (!is_scalar($value) && $value !== null) {
                $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            }
            $fields[] = ['key' => (string) $key, 'value' => $value];
        }

        return $fields;
    }
}
