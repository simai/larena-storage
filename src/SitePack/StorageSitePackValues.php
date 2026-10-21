<?php

declare(strict_types=1);

namespace Larena\Storage\SitePack;

use Closure;

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
 * Every other value passes as it is; the write normalizes it as any write does.
 */
final readonly class StorageSitePackValues
{
    public const string USER_PATTERN = '/\Auser:[a-z][a-z0-9_]{0,63}:[1-9][0-9]{0,19}\z/D';

    /** @var array<string, array<string, mixed>> */
    private array $fields;

    /** @param list<array<string, mixed>> $sourceFields the pack's fields (their record@1 targets as the pack names them) */
    public function __construct(array $sourceFields, private string $sourceStructureId)
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
