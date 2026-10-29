<?php

declare(strict_types=1);

namespace Larena\Storage\Admin;

use Closure;
use Throwable;

/**
 * The state of a storage's encryption key for «Шифрование» on its settings page (larena-specs
 * docs/architecture/2026-10-22-storage-field-encryption.md, «Keys»), from what Storage tells without any key
 * material: StorageDataKeys::health() for the installation (sodium, the site's key-encryption key) and
 * StorageEncryptionExplain::explain() for the storage's own data keys (whether this installation can unwrap each and
 * whether the current key-encryption key wraps it).
 *
 * - `ok`: the storage's keys open here and are wrapped by the current site key;
 * - `none`: the storage has no data key yet (it gets one with its first encrypted field);
 * - `warning`: a key is still wrapped by a previous site key (run `larena:storage:encryption:rewrap`);
 * - `key_missing`: a key cannot be unwrapped here;
 * - `unavailable`: no sodium or no site key while keys exist.
 *
 * Both are given as closures by the service provider (null while Storage offers none); a refusal of the explain
 * (its right, a storage it cannot read) falls back to the installation's health.
 */
final readonly class StorageEncryptionKeyStatus
{
    public const STATES = ['ok', 'none', 'warning', 'key_missing', 'unavailable'];

    /**
     * @param (Closure(): array<string, mixed>)|null $health StorageDataKeys::health()
     * @param (Closure(string, string, string): array<string, mixed>)|null $explain StorageEncryptionExplain::explain(scope, structure, actor)
     */
    public function __construct(private ?Closure $health = null, private ?Closure $explain = null)
    {
    }

    /** Whether Storage tells anything about keys here. */
    public function available(): bool
    {
        return $this->health !== null || $this->explain !== null;
    }

    /**
     * The storage's key state and whether its key can be changed (a data key exists and opens here).
     *
     * @return array{state: 'ok'|'none'|'warning'|'key_missing'|'unavailable', rotatable: bool}
     */
    public function status(string $actor, string $scopeRef, string $structureId): array
    {
        $health = $this->health();
        $keys = $this->keys($actor, $scopeRef, $structureId);
        if ($keys === null) {
            $state = match (true) {
                ($health['state'] ?? null) === 'unavailable' => 'unavailable',
                in_array('storage_field_encryption_key_unavailable', $health['reasons'], true) => 'key_missing',
                in_array('storage_data_keys_previous_kek', $health['reasons'], true) => 'warning',
                $health['keys'] === 0 => 'none',
                default => 'ok',
            };

            return ['state' => $state, 'rotatable' => $state === 'ok' || $state === 'warning'];
        }
        if ($keys === []) {
            return ['state' => 'none', 'rotatable' => false];
        }
        $state = 'ok';
        foreach ($keys as $key) {
            if (!is_array($key)) {
                continue;
            }
            if (($key['unwrappable'] ?? false) !== true) {
                $state = 'key_missing';
                break;
            }
            if (($key['current_kek'] ?? true) !== true) {
                $state = 'warning';
            }
        }
        if (($health['state'] ?? null) === 'unavailable') {
            $state = 'unavailable';
        }

        return ['state' => $state, 'rotatable' => $state === 'ok' || $state === 'warning'];
    }

    /** @return array{state: ?string, reasons: list<string>, keys: int} */
    private function health(): array
    {
        if ($this->health === null) {
            return ['state' => null, 'reasons' => [], 'keys' => 0];
        }
        try {
            $health = ($this->health)();
        } catch (Throwable) {
            return ['state' => 'unavailable', 'reasons' => [], 'keys' => 0];
        }

        return ['state' => is_string($health['state'] ?? null) ? $health['state'] : null,
            'reasons' => array_values(array_filter(is_array($health['reasons'] ?? null) ? $health['reasons'] : [], 'is_string')),
            'keys' => is_int($health['keys'] ?? null) ? $health['keys'] : 0];
    }

    /** The storage's data keys as Storage explains them; null when it does not. @return list<mixed>|null */
    private function keys(string $actor, string $scopeRef, string $structureId): ?array
    {
        if ($this->explain === null) {
            return null;
        }
        try {
            $explained = ($this->explain)($scopeRef, $structureId, $actor);
        } catch (Throwable) {
            return null;
        }

        return is_array($explained['keys'] ?? null) ? array_values($explained['keys']) : null;
    }
}
