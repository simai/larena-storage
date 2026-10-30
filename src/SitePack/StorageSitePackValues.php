<?php

declare(strict_types=1);

namespace Larena\Storage\SitePack;

use Closure;
use Larena\Storage\Encryption\StorageFieldEnvelope;

/**
 * Rewrites one set of a pack's values (a version's shared values, or one locale of its translations) for the target
 * site, the same way for the plan's dry run and for the import:
 *
 * - record@1: a record of the pack itself (the field targets the pack's structure) through the run's id map; a record
 *   of another storage through the resolved references. Unresolved: an optional field is cleared
 *   (`reference_cleared`), a required one is an error (`reference_required_unresolved`).
 * - user@1: a user this installation has stays. A missing one: an optional field is cleared (`user_missing_cleared`),
 *   a required one is set to the person importing (`user_missing_set_to_importer`), or is an error when that person
 *   is not a user (`user_required_missing`).
 * - file@1: through the files the plan decided (reuse, create, remap). A file the pack does not carry: an optional
 *   field is cleared (`file_missing_cleared`), a required one is an error (`file_required_missing`).
 *
 * - content@1: the `target` of each `larena.list` block (a structure role ref, or a storage schema id). A role ref is
 *   kept; a schema id is mapped through $listTargets (the pack's own structure → the storage it becomes, another
 *   storage → the one an earlier import of this scope made of it, or the same schema here). Unresolved: kept as it
 *   is (`list_target_unresolved`; the site shows the list as unavailable until it names a storage of the site).
 * - an encrypted field («Шифровать»): a pack carries no encrypted value, unless it was exported with them in
 *   plaintext (`contains_decrypted_values`: the write seals them with this site's key); an envelope found anyway (it
 *   would not open on this site) is left out (`encrypted_values_omitted`).
 *
 * Every other value passes as it is; the write normalizes it as any write does.
 */
final readonly class StorageSitePackValues
{
    /** A warning: encrypted values are not in the pack, and a required encrypted field is imported as optional. */
    public const string ENCRYPTED_OMITTED = 'encrypted_values_omitted';

    /** A warning: the pack carries encrypted values in plaintext; the import seals them with this site's data key. */
    public const string ENCRYPTED_DECRYPTED = 'encrypted_values_decrypted';

    public const string USER_PATTERN = '/\Auser:[a-z][a-z0-9_]{0,63}:[1-9][0-9]{0,19}\z/D';

    /** A warning: a list block names a storage this site does not have; it is kept as it is. */
    public const string LIST_TARGET_UNRESOLVED = 'list_target_unresolved';

    /** The block type whose `target` names a storage (a role ref or a schema id). */
    public const string LIST_BLOCK = 'larena.list';

    private const string ROLE_REF_PATTERN = '/\A[a-z][a-z0-9_]{0,63}@v[1-9][0-9]{0,5}\z/D';

    /** @var array<string, array<string, mixed>> */
    private array $fields;

    /**
     * @param list<array<string, mixed>> $sourceFields the pack's fields (their record@1 targets as the pack names them)
     * @param (Closure(string): ?string)|null $listTargets the storage a list block's schema id names here, null when
     *        none; without it list targets pass as they are
     */
    public function __construct(array $sourceFields, private string $sourceStructureId, private ?Closure $listTargets = null)
    {
        $fields = [];
        foreach ($sourceFields as $field) {
            if (is_string($field['key'] ?? null)) {
                $fields[$field['key']] = $field;
            }
        }
        $this->fields = $fields;
    }

    /**
     * The refs one set of values holds, by kind.
     *
     * @param array<string, mixed> $values
     * @return array{internal: list<string>, external: list<string>, users: list<string>, files: list<string>}
     */
    public function refs(array $values): array
    {
        $refs = ['internal' => [], 'external' => [], 'users' => [], 'files' => []];
        foreach ($values as $key => $value) {
            $field = $this->fields[(string) $key] ?? null;
            if ($field === null || !is_string($value) || $value === '') {
                continue;
            }
            match ($field['type'] ?? null) {
                'record' => $refs[$this->internal($field) ? 'internal' : 'external'][] = strtolower($value),
                'user' => $refs['users'][] = $value,
                'file' => $refs['files'][] = $value,
                default => null,
            };
        }

        return $refs;
    }

    /**
     * @param array<string, mixed> $values
     * @param Closure(string, bool): ?string $record the target of a record (internal or not), null when unresolved
     * @param Closure(string): bool $user whether a user exists here
     * @param Closure(string): ?string $file the target logical ref of a file, null when the pack does not carry it
     * @return array{values: array<string, mixed>, issues: list<string>}
     */
    public function rewrite(array $values, Closure $record, Closure $user, Closure $file, ?string $importer): array
    {
        $issues = [];
        foreach ($values as $key => $value) {
            $field = $this->fields[(string) $key] ?? null;
            if ($field !== null && StorageFieldEnvelope::isEnvelope($value)) {
                unset($values[$key]);
                $issues[] = self::ENCRYPTED_OMITTED;
                continue;
            }
            if ($field !== null && ($field['type'] ?? null) === 'content' && is_array($value)) {
                $values[$key] = $this->listTargets($value, $issues);
                continue;
            }
            if ($field === null || !is_string($value) || $value === '') {
                continue;
            }
            $required = ($field['required'] ?? false) === true;
            switch ($field['type'] ?? null) {
                case 'record':
                    $target = $record(strtolower($value), $this->internal($field));
                    if ($target !== null) {
                        $values[$key] = $target;
                    } elseif ($required) {
                        $issues[] = 'reference_required_unresolved';
                    } else {
                        unset($values[$key]);
                        $issues[] = 'reference_cleared';
                    }
                    break;
                case 'user':
                    if ($user($value)) {
                        break;
                    }
                    if (!$required) {
                        unset($values[$key]);
                        $issues[] = 'user_missing_cleared';
                    } elseif ($importer !== null && preg_match(self::USER_PATTERN, $importer) === 1) {
                        $values[$key] = $importer;
                        $issues[] = 'user_missing_set_to_importer';
                    } else {
                        $issues[] = 'user_required_missing';
                    }
                    break;
                case 'file':
                    $target = $file($value);
                    if ($target !== null) {
                        $values[$key] = $target;
                    } elseif ($required) {
                        $issues[] = 'file_required_missing';
                    } else {
                        unset($values[$key]);
                        $issues[] = 'file_missing_cleared';
                    }
                    break;
            }
        }

        return ['values' => $values, 'issues' => $issues];
    }

    /**
     * A content@1 value with each list block's schema id target mapped.
     *
     * @param array<array-key, mixed> $blocks
     * @param list<string> $issues
     * @return array<array-key, mixed>
     */
    private function listTargets(array $blocks, array &$issues): array
    {
        if ($this->listTargets === null) {
            return $blocks;
        }
        foreach ($blocks as $position => $block) {
            $target = is_array($block) && ($block['type'] ?? null) === self::LIST_BLOCK && is_array($block['params'] ?? null)
                ? ($block['params']['target'] ?? null) : null;
            if (!is_string($target) || $target === '' || preg_match(self::ROLE_REF_PATTERN, $target) === 1) {
                continue;
            }
            $mapped = ($this->listTargets)($target);
            if ($mapped === null) {
                $issues[] = self::LIST_TARGET_UNRESOLVED;
            } elseif ($mapped !== $target) {
                $blocks[$position]['params']['target'] = $mapped;
            }
        }

        return $blocks;
    }

    /** Issue codes that refuse the import (the others are warnings). */
    public static function isError(string $issue): bool
    {
        return in_array($issue, ['reference_required_unresolved', 'user_required_missing', 'file_required_missing'], true);
    }

    /** @param array<string, mixed> $field */
    private function internal(array $field): bool
    {
        $constraints = is_array($field['constraints'] ?? null) ? $field['constraints'] : [];

        return ($constraints['target_structure_id'] ?? null) === $this->sourceStructureId;
    }
}
