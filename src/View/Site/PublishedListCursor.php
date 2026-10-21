<?php

declare(strict_types=1);

namespace Larena\Storage\View\Site;

use Larena\Storage\Exceptions\ReadContractRejected;
use Larena\Storage\Exceptions\StorageRejected;
use Larena\Storage\View\StorageViewKeyset;
use Throwable;

/**
 * The continuation of a public list (PublishedListReads).
 *
 * Inside it is a StorageViewKeyset continuation — the sort keys and record id of the
 * last row the page scanned, HMAC-signed with the cursor key and bound to the query
 * hash and the slot hash (the storage, scope and locale) — or, when a sort key is too
 * long to carry, the number of rows passed, signed and bound the same way. Outside it
 * is encrypted (an authenticated secret box under a key derived from the cursor key):
 * with a visibility filter the last row scanned may be one the caller may not read,
 * and its id and sort keys must not reach the caller.
 *
 * The slot hash names the slot by its storage, scope and locale rather than its row id,
 * so a cursor keeps working when the slot is silenced and the list is answered from the
 * published records instead, with the same order.
 */
final readonly class PublishedListCursor
{
    /** A record id a list cursor may name: printable, 1..191 bytes (signed and sealed besides). */
    public const RECORD_ID = '/\A[^\x00-\x1F\x7F]{1,191}\z/D';
    private const KEYSET = 'k';
    private const OFFSET = 'o';
    private const NONCE_BYTES = 24;
    private const GCM_IV_BYTES = 12;
    private const GCM_TAG_BYTES = 16;

    private StorageViewKeyset $keyset;
    private string $secret;

    public function __construct(private string $cursorKey)
    {
        // A published record's id is whatever its storage gave it (the workbench's `record-<hex>`,
        // a starter site's own ids): any printable id up to the publication column's length.
        $this->keyset = new StorageViewKeyset($cursorKey, self::canonicalJson(...), self::RECORD_ID);
        $this->secret = hash_hmac('sha256', 'larena/storage published list cursor', $cursorKey, true);
    }

    /**
     * The continuation after a row with these sort keys, `$passed` rows into the list.
     *
     * @param list<int|string|null> $keys
     */
    public function encode(string $queryHash, string $slotHash, array $keys, string $recordId, int $passed): string
    {
        $token = $this->keyset->encode($queryHash, $slotHash, $keys, $recordId, $passed);
        if ($token !== null) {
            return $this->seal(self::KEYSET . $token);
        }
        // A sort key too long to carry continues by position: slower, still complete.
        $payload = ['query' => $queryHash, 'slot' => $slotHash, 'offset' => $passed];
        $payload['signature'] = hash_hmac('sha256', self::canonicalJson($payload), $this->cursorKey);

        return $this->seal(self::OFFSET . self::canonicalJson($payload));
    }

    /**
     * Where a continuation resumes: after the keys and record id of a row, or after a
     * number of rows (`after` null). A continuation that was not issued for this query
     * and slot, or was altered, is `invalid_query`.
     *
     * @return array{after: array{values: list<int|string|null>, record_id: string}|null, offset: int}
     */
    public function decode(string $continuation, string $queryHash, string $slotHash, int $keys): array
    {
        $plain = $this->open($continuation);
        $kind = substr($plain, 0, 1);
        $body = substr($plain, 1);
        if ($kind === self::KEYSET) {
            try {
                $decoded = $this->keyset->decode($body, $queryHash, $slotHash, $keys);
            } catch (StorageRejected) {
                throw self::invalid();
            }

            return ['after' => ['values' => $decoded['values'], 'record_id' => $decoded['record_id']], 'offset' => $decoded['offset']];
        }
        if ($kind !== self::OFFSET) {
            throw self::invalid();
        }
        try {
            $payload = json_decode($body, true, 4, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw self::invalid();
        }
        if (!is_array($payload) || !is_string($payload['query'] ?? null) || !is_string($payload['slot'] ?? null)
            || !is_string($payload['signature'] ?? null) || !is_int($payload['offset'] ?? null)
            || $payload['offset'] < 0 || $payload['offset'] > StorageViewKeyset::MAX_OFFSET || count($payload) !== 4) {
            throw self::invalid();
        }
        $expected = hash_hmac('sha256', self::canonicalJson(['query' => $payload['query'], 'slot' => $payload['slot'], 'offset' => $payload['offset']]), $this->cursorKey);
        if (!hash_equals($expected, $payload['signature']) || !hash_equals($queryHash, $payload['query']) || !hash_equals($slotHash, $payload['slot'])) {
            throw self::invalid();
        }

        return ['after' => null, 'offset' => $payload['offset']];
    }

    /** JSON with object keys sorted at every level: the form a hash and a signature are taken of. */
    public static function canonicalJson(mixed $value): string
    {
        return json_encode(self::sorted($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    private static function sorted(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::sorted($item);
        }

        return $value;
    }

    private function seal(string $plain): string
    {
        if (function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(self::NONCE_BYTES);
            $sealed = 's' . $nonce . sodium_crypto_secretbox($plain, $nonce, $this->secret);
        } else {
            $iv = random_bytes(self::GCM_IV_BYTES);
            $tag = '';
            $cipher = openssl_encrypt($plain, 'aes-256-gcm', $this->secret, OPENSSL_RAW_DATA, $iv, $tag, '', self::GCM_TAG_BYTES);
            if (!is_string($cipher)) {
                throw new ReadContractRejected('published_list_unavailable', 'The list cursor cannot be sealed.');
            }
            $sealed = 'g' . $iv . $tag . $cipher;
        }

        return rtrim(strtr(base64_encode($sealed), '+/', '-_'), '=');
    }

    private function open(string $continuation): string
    {
        if ($continuation === '' || preg_match('/\A[A-Za-z0-9_-]+\z/D', $continuation) !== 1) {
            throw self::invalid();
        }
        $encoded = strtr($continuation, '-_', '+/');
        $padding = strlen($encoded) % 4;
        if ($padding !== 0) {
            $encoded .= str_repeat('=', 4 - $padding);
        }
        $sealed = base64_decode($encoded, true);
        if (!is_string($sealed) || $sealed === '') {
            throw self::invalid();
        }
        $plain = false;
        if ($sealed[0] === 's' && function_exists('sodium_crypto_secretbox_open') && strlen($sealed) > 1 + self::NONCE_BYTES) {
            try {
                $plain = sodium_crypto_secretbox_open(substr($sealed, 1 + self::NONCE_BYTES), substr($sealed, 1, self::NONCE_BYTES), $this->secret);
            } catch (Throwable) {
                $plain = false;
            }
        } elseif ($sealed[0] === 'g' && strlen($sealed) > 1 + self::GCM_IV_BYTES + self::GCM_TAG_BYTES) {
            $plain = openssl_decrypt(
                substr($sealed, 1 + self::GCM_IV_BYTES + self::GCM_TAG_BYTES),
                'aes-256-gcm',
                $this->secret,
                OPENSSL_RAW_DATA,
                substr($sealed, 1, self::GCM_IV_BYTES),
                substr($sealed, 1 + self::GCM_IV_BYTES, self::GCM_TAG_BYTES),
            );
        }
        if (!is_string($plain) || $plain === '') {
            throw self::invalid();
        }

        return $plain;
    }

    private static function invalid(): ReadContractRejected
    {
        return new ReadContractRejected('invalid_query', 'The list continuation is invalid.');
    }
}
