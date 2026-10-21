<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

use Larena\Storage\Exceptions\StorageRejected;

/**
 * What an import of a SitePack will do, decided by its plan job before anything is written outside the run tables
 * (larena-specs docs/architecture/2026-10-22-storage-sitepack.md). apply() is bound to it by its digest.
 *
 * - `structure`: {source_id, target_id, kept (the pack's id is kept), label, history_keep, fields}.
 * - `records`: {total, keep (ids kept), new (ids remapped), archived, versions}.
 * - `files`: {total, reuse (the site already holds it), create (under the pack's ids), remap (under new ids), missing
 *   (referenced, not in the pack), bytes (content to store: create + remap)}.
 * - `locales`: the locales the pack's translations and publication states use, in order.
 * - `references`: records of other storages the pack points at: {total, resolved, unresolved}.
 * - `roles`: [{role_ref, action: bind|skip}].
 * - `publication`: {mode: draft|keep, published, scheduled}: the publication states the pack carries.
 * - `users`: {total, missing}: user@1 values and how many name a user this installation does not have.
 * - `replay`: null, or {run_ref, structure_id, imported_at} when this site already imported this package.
 * - `warnings` / `errors`: [{code, count, subject}]; any error refuses apply(). Codes: see StorageSitePack.
 * - `options`: the StorageSitePackImportOptions it was planned with; `id_map`: {rows, sha256} of the run's id map.
 */
final readonly class StorageSitePackPlan
{
    /**
     * @param array<string, mixed> $structure
     * @param array<string, int> $records
     * @param array<string, int> $files
     * @param list<string> $locales
     * @param array<string, int> $references
     * @param list<array{role_ref: string, action: string}> $roles
     * @param array<string, int|string> $publication
     * @param array<string, int> $users
     * @param array<string, string>|null $replay
     * @param list<array{code: string, count: int, subject: ?string}> $warnings
     * @param list<array{code: string, count: int, subject: ?string}> $errors
     * @param array<string, mixed> $options
     * @param array{rows: int, sha256: string} $idMap
     */
    public function __construct(
        public array $structure,
        public array $records,
        public array $files,
        public array $locales,
        public array $references,
        public array $roles,
        public array $publication,
        public array $users,
        public ?array $replay,
        public array $warnings,
        public array $errors,
        public array $options,
        public array $idMap,
    ) {
    }

    public function ok(): bool
    {
        return $this->errors === [];
    }

    /** @return list<string> */
    public function errorCodes(): array
    {
        return array_values(array_unique(array_map(static fn (array $error): string => $error['code'], $this->errors)));
    }

    /** @return list<string> */
    public function warningCodes(): array
    {
        return array_values(array_unique(array_map(static fn (array $warning): string => $warning['code'], $this->warnings)));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'structure' => $this->structure,
            'records' => $this->records,
            'files' => $this->files,
            'locales' => $this->locales,
            'references' => $this->references,
            'roles' => $this->roles,
            'publication' => $this->publication,
            'users' => $this->users,
            'replay' => $this->replay,
            'warnings' => $this->warnings,
            'errors' => $this->errors,
            'options' => $this->options,
            'id_map' => $this->idMap,
        ];
    }

    /** @param array<array-key, mixed> $plan */
    public static function fromArray(array $plan): self
    {
        foreach (['structure', 'records', 'files', 'locales', 'references', 'roles', 'publication', 'users', 'warnings', 'errors', 'options', 'id_map'] as $key) {
            if (!is_array($plan[$key] ?? null)) {
                throw new StorageRejected('storage_sitepack_plan_corrupt');
            }
        }
        if (($plan['replay'] ?? null) !== null && !is_array($plan['replay'])) {
            throw new StorageRejected('storage_sitepack_plan_corrupt');
        }
        $issues = static function (array $list): array {
            $out = [];
            foreach ($list as $issue) {
                if (!is_array($issue) || !is_string($issue['code'] ?? null)) {
                    throw new StorageRejected('storage_sitepack_plan_corrupt');
                }
                $out[] = [
                    'code' => $issue['code'],
                    'count' => (int) ($issue['count'] ?? 1),
                    'subject' => is_string($issue['subject'] ?? null) ? $issue['subject'] : null,
                ];
            }

            return $out;
        };
        $ints = static function (array $map): array {
            $out = [];
            foreach ($map as $key => $value) {
                $out[(string) $key] = (int) $value;
            }

            return $out;
        };
        $roles = [];
        foreach ($plan['roles'] as $role) {
            if (!is_array($role) || !is_string($role['role_ref'] ?? null) || !is_string($role['action'] ?? null)) {
                throw new StorageRejected('storage_sitepack_plan_corrupt');
            }
            $roles[] = ['role_ref' => $role['role_ref'], 'action' => $role['action']];
        }
        $publication = [];
        foreach ($plan['publication'] as $key => $value) {
            $publication[(string) $key] = is_int($value) ? $value : (string) $value;
        }
        $replay = null;
        if (is_array($plan['replay'] ?? null)) {
            $replay = [];
            foreach ($plan['replay'] as $key => $value) {
                $replay[(string) $key] = (string) $value;
            }
        }

        /** @var array<string, mixed> $structure */
        $structure = $plan['structure'];
        /** @var array<string, mixed> $options */
        $options = $plan['options'];

        return new self(
            $structure,
            $ints($plan['records']),
            $ints($plan['files']),
            array_values(array_map('strval', $plan['locales'])),
            $ints($plan['references']),
            $roles,
            $publication,
            $ints($plan['users']),
            $replay,
            $issues($plan['warnings']),
            $issues($plan['errors']),
            $options,
            ['rows' => (int) ($plan['id_map']['rows'] ?? 0), 'sha256' => (string) ($plan['id_map']['sha256'] ?? '')],
        );
    }
}
