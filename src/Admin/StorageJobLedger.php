<?php

declare(strict_types=1);

namespace Larena\Storage\Admin;

use InvalidArgumentException;
use Larena\Setting\Enums\SettingScopeType;
use Larena\Setting\Exceptions\SettingRevisionConflict;
use Larena\Setting\Persistence\DatabaseSettingValueRepository;

/**
 * A person's Storage import and export jobs: which jobs they started (the newest {@see LIMIT}) and each job's
 * progress, kept as the person's own Setting values. Only the person who started a job finds it here, so only
 * they see, retry, cancel or download it. The progress is written on the database connection the import
 * writes records on, so a chunk and the progress after it are committed together.
 */
final readonly class StorageJobLedger
{
    public const NAMESPACE = 'admin.storage-jobs';
    public const LIMIT = 20;
    private const LEDGER = 'ledger';
    private const KINDS = ['import', 'export'];

    public function __construct(private DatabaseSettingValueRepository $settings)
    {
    }

    /**
     * The person's jobs, newest first.
     *
     * @return list<array{ref: string, kind: string, structure_id: string, job_id: ?string, created_at: string}>
     */
    public function entries(string $actor): array
    {
        $stored = $this->settings->revisionedValue(self::NAMESPACE, self::LEDGER, SettingScopeType::User->value, self::subject($actor));

        return self::clean($stored->value);
    }

    /** @return array{ref: string, kind: string, structure_id: string, job_id: ?string, created_at: string}|null */
    public function entry(string $actor, string $ref): ?array
    {
        foreach ($this->entries($actor) as $entry) {
            if (hash_equals($entry['ref'], $ref)) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Adds a job at the top; the oldest finished jobs beyond the limit drop out.
     *
     * @param array{ref: string, kind: string, structure_id: string, job_id: ?string, created_at: string} $entry
     * @param callable(string): bool $finished whether the job of a reference has ended
     * @return list<string> the references that dropped out
     */
    public function add(string $actor, array $entry, callable $finished): array
    {
        $dropped = [];
        $this->change($actor, static function (array $entries) use ($entry, $finished, &$dropped): array {
            $entries = [$entry, ...array_values(array_filter($entries, static fn (array $old): bool => $old['ref'] !== $entry['ref']))];
            $dropped = [];
            while (count($entries) > self::LIMIT) {
                $index = null;
                foreach (array_reverse(array_keys($entries)) as $candidate) {
                    if ($candidate > 0 && $finished($entries[$candidate]['ref'])) {
                        $index = $candidate;
                        break;
                    }
                }
                if ($index === null) {
                    break;
                }
                $dropped[] = $entries[$index]['ref'];
                array_splice($entries, $index, 1);
            }

            return $entries;
        });
        foreach ($dropped as $ref) {
            $this->forgetProgress($actor, $ref);
        }

        return $dropped;
    }

    public function setJobId(string $actor, string $ref, string $jobId): void
    {
        $this->change($actor, static fn (array $entries): array => array_map(
            static fn (array $entry): array => $entry['ref'] === $ref ? ['job_id' => $jobId] + $entry : $entry, $entries));
    }

    public function remove(string $actor, string $ref): void
    {
        $this->change($actor, static fn (array $entries): array => array_values(array_filter(
            $entries, static fn (array $entry): bool => $entry['ref'] !== $ref)));
        $this->forgetProgress($actor, $ref);
    }

    /** @return array<string, mixed> */
    public function progress(string $actor, string $ref): array
    {
        $stored = $this->settings->revisionedValue(self::NAMESPACE, self::progressKey($ref), SettingScopeType::User->value, self::subject($actor));

        return is_array($stored->value) && !array_is_list($stored->value) ? $stored->value : [];
    }

    /** @param array<string, mixed> $progress */
    public function saveProgress(string $actor, string $ref, array $progress): void
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $stored = $this->settings->revisionedValue(self::NAMESPACE, self::progressKey($ref), SettingScopeType::User->value, self::subject($actor));
            try {
                $this->settings->compareAndSet(self::NAMESPACE, self::progressKey($ref), SettingScopeType::User->value, self::subject($actor), $progress, $stored->revision);

                return;
            } catch (SettingRevisionConflict) {
                continue;
            }
        }
        throw new InvalidArgumentException('storage_job_progress_conflict');
    }

    private function forgetProgress(string $actor, string $ref): void
    {
        $stored = $this->settings->revisionedValue(self::NAMESPACE, self::progressKey($ref), SettingScopeType::User->value, self::subject($actor));
        if ($stored->revision > 0) {
            try {
                $this->settings->compareAndSet(self::NAMESPACE, self::progressKey($ref), SettingScopeType::User->value, self::subject($actor), [], $stored->revision);
            } catch (SettingRevisionConflict) {
            }
        }
    }

    /** @param callable(list<array{ref: string, kind: string, structure_id: string, job_id: ?string, created_at: string}>): list<array{ref: string, kind: string, structure_id: string, job_id: ?string, created_at: string}> $change */
    private function change(string $actor, callable $change): void
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $stored = $this->settings->revisionedValue(self::NAMESPACE, self::LEDGER, SettingScopeType::User->value, self::subject($actor));
            try {
                $this->settings->compareAndSet(self::NAMESPACE, self::LEDGER, SettingScopeType::User->value, self::subject($actor),
                    $change(self::clean($stored->value)), $stored->revision);

                return;
            } catch (SettingRevisionConflict) {
                continue;
            }
        }
        throw new InvalidArgumentException('storage_job_ledger_conflict');
    }

    private static function progressKey(string $ref): string
    {
        if (!StorageJobFiles::isRef($ref)) {
            throw new InvalidArgumentException('storage_job_ref_invalid');
        }

        return 'job.'.$ref;
    }

    /** The person the values belong to; a long reference is kept by its hash. */
    private static function subject(string $actor): string
    {
        $actor = trim($actor);
        if ($actor === '' || strlen($actor) > 191) {
            throw new InvalidArgumentException('storage_job_actor_invalid');
        }

        return strlen($actor) <= 120 ? $actor : 'sha256:'.hash('sha256', $actor);
    }

    /** @return list<array{ref: string, kind: string, structure_id: string, job_id: ?string, created_at: string}> */
    private static function clean(mixed $value): array
    {
        $entries = [];
        foreach (is_array($value) ? $value : [] as $entry) {
            if (is_array($entry) && StorageJobFiles::isRef($entry['ref'] ?? null) && in_array($entry['kind'] ?? null, self::KINDS, true)
                && is_string($entry['structure_id'] ?? null) && is_string($entry['created_at'] ?? null)
                && (($entry['job_id'] ?? null) === null || is_string($entry['job_id']))) {
                $entries[] = ['ref' => (string) $entry['ref'], 'kind' => (string) $entry['kind'], 'structure_id' => $entry['structure_id'],
                    'job_id' => $entry['job_id'] ?? null, 'created_at' => $entry['created_at']];
            }
        }

        return array_slice($entries, 0, self::LIMIT * 2);
    }
}
