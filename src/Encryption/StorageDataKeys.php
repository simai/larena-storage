<?php

declare(strict_types=1);

namespace Larena\Storage\Encryption;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Larena\Secret\Contracts\DataKeyWrapper;
use Larena\Secret\ValueObjects\WrappedDataKey;
use Larena\Storage\Exceptions\StorageRejected;
use LogicException;
use stdClass;
use Throwable;

/**
 * Each storage's data keys (DEKs), in `larena_storage_data_keys`: a random 32-byte key a storage, stored only wrapped
 * by the key-encryption key of larena/secret (DataKeyWrapper; by default derived from APP_KEY). The wrap binds the
 * storage and the key id (associated data `["larena/storage/data-key", schema id, key id]`), so a wrapped key copied
 * to another storage or row does not unwrap.
 *
 * - One key a storage is `active` (`active_slot` = 1, unique with the schema id); new values use it. A rotation
 *   (rotate()) makes it `retiring`: it still opens the values it sealed until the rotation run sealed them again,
 *   and is deleted then (dropRetiring()). rewrap() moves every key to the current KEK after an APP_KEY change;
 *   health() says whether one is still wrapped by a previous KEK.
 * - The wrapper is made at the first use, never at boot: an installation without APP_KEY or sodium boots, and every
 *   encrypted write or read is refused (`storage_field_encryption_unavailable`) before anything changes.
 * - A key that does not unwrap (another APP_KEY, a tampered row, a key of another storage) is
 *   `storage_field_encryption_key_unavailable`. Nothing here ever carries key material in a message.
 * - Unwrapped keys are kept for the life of this object (a request, a job) and zeroed when it ends. A key created in
 *   a transaction that rolls back is never served from memory: the active key is always read from the table.
 */
final class StorageDataKeys
{
    public const TABLE = 'larena_storage_data_keys';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_RETIRING = 'retiring';
    public const STATUS_RETIRED = 'retired';
    public const AAD_LABEL = 'larena/storage/data-key';
    public const REASON_UNAVAILABLE = 'storage_field_encryption_unavailable';
    public const REASON_KEY_UNAVAILABLE = 'storage_field_encryption_key_unavailable';

    private ?DataKeyWrapper $resolved = null;

    /** @var array<string, string> schema id and key id => data key */
    private array $unwrapped = [];

    /**
     * @param Closure(): DataKeyWrapper $wrapper makes the wrapper at the first use
     */
    public function __construct(
        private readonly ConnectionInterface $database,
        private readonly Closure $wrapper,
    ) {
    }

    public function __destruct()
    {
        $this->forget();
    }

    /** Zeroes the unwrapped keys this object kept. */
    public function forget(): void
    {
        foreach (array_keys($this->unwrapped) as $memo) {
            sodium_memzero($this->unwrapped[$memo]);
        }
        $this->unwrapped = [];
    }

    /**
     * Whether values can be encrypted here: sodium is loaded and the key-encryption key can be made.
     *
     * @throws StorageRejected storage_field_encryption_unavailable
     */
    public function assertAvailable(): void
    {
        $this->wrapper();
    }

    /** @return array{available: bool, reason_code: ?string} */
    public function availability(): array
    {
        try {
            $this->assertAvailable();

            return ['available' => true, 'reason_code' => null];
        } catch (StorageRejected $exception) {
            return ['available' => false, 'reason_code' => $exception->reasonCode];
        }
    }

    /**
     * The storage's active key: its id and the data key. Creates it when the storage has none. Runs in the caller's
     * transaction (a write's), so a key whose write rolls back is gone with it.
     *
     * @return array{0: string, 1: string} key id, data key
     *
     * @throws StorageRejected
     */
    public function active(string $schemaId): array
    {
        $keyId = $this->ensureActive($schemaId);

        return [$keyId, $this->key($schemaId, $keyId)];
    }

    /**
     * The id of the storage's active key, created when it has none.
     *
     * @throws StorageRejected
     */
    public function ensureActive(string $schemaId): string
    {
        $wrapper = $this->wrapper();
        $keyId = $this->activeId($schemaId, false);
        if ($keyId !== null) {
            return $keyId;
        }
        $keyId = 'dk-' . bin2hex(random_bytes(12));
        $dataKey = random_bytes(StorageFieldCipher::KEY_BYTES);
        try {
            $wrapped = $wrapper->wrap($dataKey, self::aad($schemaId, $keyId));
        } catch (Throwable) {
            throw new StorageRejected(self::REASON_UNAVAILABLE);
        } finally {
            sodium_memzero($dataKey);
        }
        try {
            $this->database->table(self::TABLE)->insert([
                'key_id' => $keyId,
                'schema_id' => $schemaId,
                'status' => self::STATUS_ACTIVE,
                'wrapped_key' => json_encode($wrapped->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'kek_id' => $wrapped->kekId,
                'algorithm' => StorageFieldEnvelope::ALGORITHM,
                'created_at' => gmdate('Y-m-d H:i:s'),
                'retired_at' => null,
                'active_slot' => 1,
            ]);
        } catch (Throwable) {
            // Another writer created the storage's key meanwhile (the active slot is unique): that one is used.
            $keyId = $this->activeId($schemaId, true);
            if ($keyId === null) {
                throw new StorageRejected(self::REASON_KEY_UNAVAILABLE);
            }
        }

        return $keyId;
    }

    /**
     * One key of the storage, unwrapped.
     *
     * @throws StorageRejected storage_field_encryption_key_unavailable
     */
    public function key(string $schemaId, string $keyId): string
    {
        $memo = $schemaId . "\0" . $keyId;
        if (isset($this->unwrapped[$memo])) {
            return $this->unwrapped[$memo];
        }
        $wrapper = $this->wrapper();
        try {
            $row = $this->database->table(self::TABLE)->where('key_id', $keyId)->first();
        } catch (Throwable) {
            throw new StorageRejected(self::REASON_KEY_UNAVAILABLE);
        }
        if (!$row instanceof stdClass || (string) $row->schema_id !== $schemaId) {
            throw new StorageRejected(self::REASON_KEY_UNAVAILABLE);
        }
        try {
            $data = json_decode((string) $row->wrapped_key, true, 8, JSON_THROW_ON_ERROR);
            $wrapped = WrappedDataKey::fromArray(is_array($data) ? $data : []);
            if ($wrapped->kekId !== (string) $row->kek_id) {
                throw new StorageRejected(self::REASON_KEY_UNAVAILABLE);
            }
            $dataKey = $wrapper->unwrap($wrapped, self::aad($schemaId, $keyId));
        } catch (Throwable) {
            throw new StorageRejected(self::REASON_KEY_UNAVAILABLE);
        }
        if (strlen($dataKey) !== StorageFieldCipher::KEY_BYTES) {
            sodium_memzero($dataKey);

            throw new StorageRejected(self::REASON_KEY_UNAVAILABLE);
        }

        return $this->unwrapped[$memo] = $dataKey;
    }

    /**
     * The storage's keys, without any key material: id, status, the KEK that wraps it, the algorithm, when it was
     * made and retired, and whether this installation can unwrap it (its KEK is current or previous).
     *
     * @return list<array{key_id: string, status: string, kek_id: string, algorithm: string, created_at: string, retired_at: ?string, unwrappable: bool, current_kek: bool}>
     */
    public function describe(string $schemaId): array
    {
        try {
            $rows = $this->database->table(self::TABLE)->where('schema_id', $schemaId)->orderBy('created_at')->orderBy('key_id')->get()->all();
        } catch (Throwable) {
            return [];
        }
        $wrapper = null;
        $current = null;
        try {
            $wrapper = $this->wrapper();
            $current = $wrapper->currentKekId();
        } catch (Throwable) {
        }
        $keys = [];
        foreach ($rows as $row) {
            $unwrappable = false;
            if ($wrapper !== null) {
                try {
                    $data = json_decode((string) $row->wrapped_key, true, 8, JSON_THROW_ON_ERROR);
                    $unwrappable = $wrapper->canUnwrap(WrappedDataKey::fromArray(is_array($data) ? $data : []));
                } catch (Throwable) {
                    $unwrappable = false;
                }
            }
            $keys[] = [
                'key_id' => (string) $row->key_id,
                'status' => (string) $row->status,
                'kek_id' => (string) $row->kek_id,
                'algorithm' => (string) $row->algorithm,
                'created_at' => (string) $row->created_at,
                'retired_at' => $row->retired_at === null ? null : (string) $row->retired_at,
                'unwrappable' => $unwrappable,
                // False while the key is still wrapped by a previous KEK: `larena:storage:encryption:rewrap` moves it.
                'current_kek' => $current !== null && (string) $row->kek_id === $current,
            ];
        }

        return $keys;
    }

    /**
     * The id of the storage's active key, or null when it has none (nothing is created).
     *
     * @throws StorageRejected storage_field_encryption_key_unavailable
     */
    public function activeKeyId(string $schemaId): ?string
    {
        return $this->activeId($schemaId, false);
    }

    /**
     * DEK rotation, in the caller's transaction (it also queues the run that seals the values again): the active key
     * becomes `retiring` (it still opens what it sealed) and a new key becomes active. Returns the retiring key's id
     * (null when the storage had no key) and the new one's.
     *
     * @return array{0: ?string, 1: string}
     * @throws StorageRejected
     */
    public function rotate(string $schemaId): array
    {
        $this->wrapper();
        $retiring = $this->activeId($schemaId, true);
        if ($retiring !== null) {
            $this->database->table(self::TABLE)
                ->where('key_id', $retiring)
                ->where('schema_id', $schemaId)
                ->update(['status' => self::STATUS_RETIRING, 'active_slot' => null]);
        }

        return [$retiring, $this->ensureActive($schemaId)];
    }

    /**
     * The end of a rotation, in the caller's transaction: the retiring key, which no value uses any more, is deleted.
     * Only a retiring key of the storage is.
     */
    public function dropRetiring(string $schemaId, string $keyId): bool
    {
        $memo = $schemaId . "\0" . $keyId;
        if (isset($this->unwrapped[$memo])) {
            sodium_memzero($this->unwrapped[$memo]);
            unset($this->unwrapped[$memo]);
        }

        return $this->database->table(self::TABLE)
            ->where('key_id', $keyId)
            ->where('schema_id', $schemaId)
            ->where('status', self::STATUS_RETIRING)
            ->delete() === 1;
    }

    /**
     * KEK rotation (APP_KEY changed, the old one kept in APP_PREVIOUS_KEYS): every data key wrapped by another KEK
     * than the current one is unwrapped and wrapped again by the current one, all in one transaction, so either every
     * key moves or none does. The values are not touched. A key that does not unwrap refuses the whole rewrap
     * (`storage_field_encryption_key_unavailable`) before anything changes.
     *
     * @return array{rewrapped: int, kek_id: string}
     * @throws StorageRejected
     */
    public function rewrap(): array
    {
        $wrapper = $this->wrapper();
        try {
            $current = $wrapper->currentKekId();
        } catch (Throwable) {
            throw new StorageRejected(self::REASON_UNAVAILABLE);
        }
        $count = $this->database->transaction(function () use ($wrapper, $current): int {
            $rows = $this->database->table(self::TABLE)
                ->where('kek_id', '<>', $current)
                ->orderBy('key_id')
                ->lockForUpdate()
                ->get()
                ->all();
            $count = 0;
            foreach ($rows as $row) {
                $schemaId = (string) $row->schema_id;
                $keyId = (string) $row->key_id;
                $dataKey = null;
                try {
                    $data = json_decode((string) $row->wrapped_key, true, 8, JSON_THROW_ON_ERROR);
                    $wrapped = WrappedDataKey::fromArray(is_array($data) ? $data : []);
                    if ($wrapped->kekId !== (string) $row->kek_id) {
                        throw new StorageRejected(self::REASON_KEY_UNAVAILABLE);
                    }
                    $dataKey = $wrapper->unwrap($wrapped, self::aad($schemaId, $keyId));
                    $rewrapped = $wrapper->wrap($dataKey, self::aad($schemaId, $keyId));
                } catch (Throwable) {
                    throw new StorageRejected(self::REASON_KEY_UNAVAILABLE);
                } finally {
                    if (is_string($dataKey)) {
                        sodium_memzero($dataKey);
                    }
                }
                $this->database->table(self::TABLE)->where('key_id', $keyId)->update([
                    'wrapped_key' => json_encode($rewrapped->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    'kek_id' => $rewrapped->kekId,
                ]);
                $count++;
            }

            return $count;
        });

        return ['rewrapped' => (int) $count, 'kek_id' => $current];
    }

    /**
     * What monitoring and the admin can read without any key material: `warning` while a data key is still wrapped
     * by a previous KEK (run `larena:storage:encryption:rewrap`) or cannot be unwrapped here at all, `unavailable`
     * without sodium or a KEK while data keys exist, `ok` otherwise.
     *
     * @return array{state: string, reasons: list<string>, keys: int, previous_kek_keys: int, unwrappable_missing: int}
     */
    public function health(): array
    {
        try {
            $rows = $this->database->table(self::TABLE)->get(['wrapped_key', 'kek_id'])->all();
        } catch (Throwable) {
            return ['state' => 'ok', 'reasons' => [], 'keys' => 0, 'previous_kek_keys' => 0, 'unwrappable_missing' => 0];
        }
        $report = ['state' => 'ok', 'reasons' => [], 'keys' => count($rows), 'previous_kek_keys' => 0, 'unwrappable_missing' => 0];
        if ($rows === []) {
            return $report;
        }
        try {
            $wrapper = $this->wrapper();
            $current = $wrapper->currentKekId();
        } catch (Throwable) {
            $report['state'] = 'unavailable';
            $report['reasons'][] = self::REASON_UNAVAILABLE;

            return $report;
        }
        foreach ($rows as $row) {
            $unwrappable = false;
            try {
                $data = json_decode((string) $row->wrapped_key, true, 8, JSON_THROW_ON_ERROR);
                $unwrappable = $wrapper->canUnwrap(WrappedDataKey::fromArray(is_array($data) ? $data : []));
            } catch (Throwable) {
            }
            if (!$unwrappable) {
                $report['unwrappable_missing']++;
            } elseif ((string) $row->kek_id !== $current) {
                $report['previous_kek_keys']++;
            }
        }
        if ($report['previous_kek_keys'] > 0) {
            $report['state'] = 'warning';
            $report['reasons'][] = 'storage_data_keys_previous_kek';
        }
        if ($report['unwrappable_missing'] > 0) {
            $report['state'] = 'warning';
            $report['reasons'][] = self::REASON_KEY_UNAVAILABLE;
        }

        return $report;
    }

    /**
     * Removes every key of a storage, in the caller's transaction (a structure purge): its values can never be read
     * again.
     */
    public static function purge(ConnectionInterface $database, string $schemaId): int
    {
        return $database->table(self::TABLE)->where('schema_id', $schemaId)->delete();
    }

    /** The associated data of a storage's wrapped key. */
    public static function aad(string $schemaId, string $keyId): string
    {
        return StorageFieldCipher::canonicalJson([self::AAD_LABEL, $schemaId, $keyId]);
    }

    /** @return array{resolved: bool, unwrapped_keys: list<string>} */
    public function __debugInfo(): array
    {
        return ['resolved' => $this->resolved !== null, 'unwrapped_keys' => array_map(static fn (string $memo): string => substr($memo, (int) strpos($memo, "\0") + 1), array_map('strval', array_keys($this->unwrapped)))];
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new LogicException('storage_data_keys_not_serializable');
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new LogicException('storage_data_keys_not_serializable');
    }

    private function activeId(string $schemaId, bool $lock): ?string
    {
        try {
            $query = $this->database->table(self::TABLE)
                ->where('schema_id', $schemaId)
                ->where('status', self::STATUS_ACTIVE)
                ->where('active_slot', 1);
            if ($lock) {
                // A locking read sees the row another transaction committed meanwhile.
                $query->sharedLock();
            }
            $keyId = $query->value('key_id');
        } catch (Throwable) {
            throw new StorageRejected(self::REASON_KEY_UNAVAILABLE);
        }

        return is_string($keyId) && preg_match(StorageFieldEnvelope::KEY_ID_PATTERN, $keyId) === 1 ? $keyId : null;
    }

    /** @throws StorageRejected storage_field_encryption_unavailable */
    private function wrapper(): DataKeyWrapper
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }
        if (!StorageFieldCipher::available()) {
            throw new StorageRejected(self::REASON_UNAVAILABLE);
        }
        try {
            $wrapper = ($this->wrapper)();
        } catch (Throwable) {
            // DataKeyUnavailable (no or an invalid APP_KEY) or a binding that failed: never chained.
            throw new StorageRejected(self::REASON_UNAVAILABLE);
        }

        return $this->resolved = $wrapper;
    }
}
