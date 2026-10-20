<?php

declare(strict_types=1);

namespace Larena\Storage\View;

use Closure;
use Larena\Storage\Exceptions\StorageRejected;
use Throwable;

/**
 * The continuation of a list read from the витрина: the sort keys and the
 * record id of the last row of the page, bound to the query and the access
 * decision it was issued for and signed with the cursor key. The payload says
 * `version: 2`; the earlier offset continuation (`version: 1`) is still read
 * by the caller for one release.
 */
final readonly class StorageViewKeyset
{
    public const VERSION = 2;
    private const MAX_BYTES = 2048;
    private const MAX_KEY_BYTES = 256;
    public const MAX_OFFSET = 1_000_000_000;

    /** @param Closure(mixed): string $canonicalJson */
    public function __construct(private string $cursorKey, private Closure $canonicalJson)
    {
    }

    /** The payload version of a continuation, or null when it is not one. */
    public static function version(string $continuation): ?int
    {
        $payload = self::payload($continuation);

        return is_int($payload['version'] ?? null) ? $payload['version'] : null;
    }

    /**
     * Null when a key is too long to carry (a sort on long text); the caller then
     * continues by offset.
     *
     * @param list<int|string|null> $values
     * @param int $offset How many rows the list has passed once this page is read.
     */
    public function encode(string $queryHash, string $scopeHash, array $values, string $recordId, int $offset): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && strlen($value) > self::MAX_KEY_BYTES) {
                return null;
            }
        }
        $payload = ['version' => self::VERSION, 'query' => $queryHash, 'scope' => $scopeHash, 'after' => $values, 'record_id' => $recordId, 'offset' => $offset];
        $payload['signature'] = hash_hmac('sha256', ($this->canonicalJson)($payload), $this->cursorKey);
        $token = rtrim(strtr(base64_encode(($this->canonicalJson)($payload)), '+/', '-_'), '=');

        return strlen($token) <= self::MAX_BYTES ? $token : null;
    }

    /** @return array{values: list<int|string|null>, record_id: string, offset: int} */
    public function decode(string $continuation, string $queryHash, string $scopeHash, int $keys): array
    {
        $payload = self::payload($continuation);
        $names = is_array($payload) ? array_keys($payload) : [];
        sort($names, SORT_STRING);
        $after = $payload['after'] ?? null;
        if ($names !== ['after', 'offset', 'query', 'record_id', 'scope', 'signature', 'version']
            || !is_int($payload['offset'] ?? null) || $payload['offset'] < 0 || $payload['offset'] > self::MAX_OFFSET
            || ($payload['version'] ?? null) !== self::VERSION
            || !is_string($payload['query'] ?? null) || !is_string($payload['scope'] ?? null)
            || !is_string($payload['signature'] ?? null)
            || !is_string($payload['record_id'] ?? null)
            || preg_match('/\Arecord-[a-f0-9]{32}\z/D', $payload['record_id']) !== 1
            || !is_array($after) || !array_is_list($after) || count($after) !== $keys) {
            throw new StorageRejected('storage_workbench_record_continuation_invalid');
        }
        foreach ($after as $value) {
            if (!($value === null || is_int($value) || is_string($value))) {
                throw new StorageRejected('storage_workbench_record_continuation_invalid');
            }
        }
        $signed = ['version' => self::VERSION, 'query' => $payload['query'], 'scope' => $payload['scope'], 'after' => $after,
            'record_id' => $payload['record_id'], 'offset' => $payload['offset']];
        $expected = hash_hmac('sha256', ($this->canonicalJson)($signed), $this->cursorKey);
        if (!hash_equals($expected, $payload['signature'])
            || !hash_equals($queryHash, $payload['query'])
            || !hash_equals($scopeHash, $payload['scope'])) {
            throw new StorageRejected('storage_workbench_record_continuation_invalid');
        }

        /** @var list<int|string|null> $after */
        return ['values' => $after, 'record_id' => $payload['record_id'], 'offset' => $payload['offset']];
    }

    /** @return array<array-key, mixed>|null */
    private static function payload(string $continuation): ?array
    {
        if ($continuation === '' || strlen($continuation) > self::MAX_BYTES) {
            throw new StorageRejected('storage_workbench_record_continuation_invalid');
        }
        $encoded = strtr($continuation, '-_', '+/');
        $padding = strlen($encoded) % 4;
        if ($padding !== 0) {
            $encoded .= str_repeat('=', 4 - $padding);
        }
        $json = base64_decode($encoded, true);
        try {
            $payload = is_string($json) ? json_decode($json, true, 32, JSON_THROW_ON_ERROR) : null;
        } catch (Throwable) {
            $payload = null;
        }

        return is_array($payload) && !array_is_list($payload) ? $payload : null;
    }
}
