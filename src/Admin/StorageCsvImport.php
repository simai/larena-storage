<?php

declare(strict_types=1);

namespace Larena\Storage\Admin;

use Illuminate\Database\ConnectionInterface;
use Larena\Storage\Admin\Contracts\StorageSectionScope;
use Larena\Admin\Runtime\RecordFilterFields;
use Larena\Storage\Contracts\StorageWorkbench;
use Larena\Storage\Contracts\StorageWorkbenchRecord;
use Larena\Storage\Contracts\StorageWorkbenchRecordQuery;
use Larena\Storage\Enums\StorageWorkbenchCountMode;
use Larena\Storage\Exceptions\StorageConflict;
use Larena\Storage\Exceptions\StorageRejected;
use InvalidArgumentException;
use Throwable;

/**
 * Records of a storage from a CSV file (owner decision 2026-10-19: add and update). A row with the ID of a record
 * updates the columns the file has and leaves the rest; a row without an ID adds a record. The file is first read
 * into a plan the person sees (what is added, what changes, what is refused and why); the plan is then written.
 *
 * A small file is written whole or not at all, each change a new version in the record's history. A large one is
 * written by the queue in chunks ({@see StorageImportJobHandler}): the plan is read again into a private file of
 * rows, and each chunk is written in one transaction together with the job's progress, so a retried or resumed
 * job continues after the last written chunk and never writes a row twice.
 *
 * The plan reads the file in windows of rows, and the records a window names are read together through Storage's
 * list rather than one by one. A file too large to plan in the request is planned by the queue
 * ({@see StorageImportPlanJobHandler}) window by window, resuming after the last saved window; its plan is the one
 * the request would show.
 *
 * Columns are matched to fields by their name or key, an «ID» column to records. Values are read as the export
 * writes them: an option by its label or value, «Да» / «Нет», numbers and dates as a Russian spreadsheet shows them.
 * Fields that point at a person, a file or another record are not imported yet; the plan says so.
 */
final readonly class StorageCsvImport
{
    public const MAX_BYTES = 52_428_800;
    public const MAX_ROWS = 500_000;
    /** The plan shows at most this many errors of a file read by the queue; the rest are counted. */
    public const MAX_KEPT_ERRORS = 200;
    /** Rows planned together: the records they name are read at once, and the queue saves its progress after each. */
    public const PLAN_WINDOW = 1000;
    /** A storage of at most this many records per wanted ID is read page by page rather than record by record. */
    private const SCAN_FACTOR = 20;
    private const SCAN_PAGE = 100;
    private const RECORD_ID = '/\Arecord-[a-f0-9]{32}\z/D';

    public function __construct(
        private StorageWorkbench $workbench,
        private StorageSectionScope $scopes,
        private ConnectionInterface $database,
    ) {
    }

    /**
     * What the file would do, with nothing written.
     *
     * @param array<string, bool> $permissions the section's rights of the actor (record.create, record.update)
     * @return array{columns: list<array{header: string, key: ?string, field: ?string, skipped: ?string}>,
     *     rows: list<array{line: int, action: string, record_id: ?string, revision: ?int, values: array<string, mixed>}>,
     *     errors: list<array{line: int, column: string, reason: string}>,
     *     counts: array{create: int, update: int, same: int}, digest: string}
     */
    public function plan(string $actor, string $structureId, string $csv, array $permissions): array
    {
        $rows = [];
        $scan = $this->scan($actor, $structureId, $csv, $permissions, static function (array $row) use (&$rows): void {
            $rows[] = $row;
        }, null);

        return ['columns' => $scan['columns'], 'rows' => $scan['columns'] === [] ? [] : $rows,
            'errors' => $scan['errors'], 'counts' => $scan['counts'], 'digest' => $scan['digest']];
    }

    /**
     * The plan of a stored file written to another private file, one JSON row per line (only the rows that add or
     * change a record), for the queue to write in chunks. The digest is the one {@see plan()} shows for the file.
     *
     * @param array<string, bool> $permissions
     * @return array{errors: list<array{line: int, column: string, reason: string}>, error_count: int,
     *     counts: array{create: int, update: int, same: int}, digest: string}
     */
    public function planToFile(string $actor, string $structureId, string $csvPath, array $permissions, string $planPath): array
    {
        $csv = is_file($csvPath) && filesize($csvPath) <= self::MAX_BYTES ? file_get_contents($csvPath) : false;
        $partial = $planPath.'.part';
        $out = fopen($partial, 'wb');
        if (!is_string($csv) || $out === false) {
            if ($out !== false) {
                fclose($out);
                @unlink($partial);
            }

            return ['errors' => [['line' => 0, 'column' => '', 'reason' => 'file_unreadable']], 'error_count' => 1,
                'counts' => ['create' => 0, 'update' => 0, 'same' => 0], 'digest' => ''];
        }
        try {
            $scan = $this->scan($actor, $structureId, $csv, $permissions, static function (array $row) use ($out): void {
                if ($row['action'] !== 'same') {
                    fwrite($out, json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
                }
            }, self::MAX_KEPT_ERRORS);
        } finally {
            fclose($out);
        }
        if ($scan['error_count'] > 0 || !rename($partial, $planPath)) {
            @unlink($partial);
        }

        return ['errors' => $scan['errors'], 'error_count' => $scan['error_count'], 'counts' => $scan['counts'], 'digest' => $scan['digest']];
    }

    /** How many rows with records the file has, without reading any record; null for a file that is not CSV. */
    public static function rowCount(string $csv): ?int
    {
        $stream = self::stream($csv);
        if ($stream === null) {
            return null;
        }
        $count = -1;
        try {
            foreach (self::cells($stream['stream'], $stream['delimiter']) as $cells) {
                if ($count === -1 || implode('', array_map('trim', $cells)) !== '') {
                    $count++;
                }
            }
        } finally {
            fclose($stream['stream']);
        }

        return max(0, $count);
    }

    /**
     * Writes the plan the person saw, whole or not at all: the file is read again and must give the same
     * plan (no record changed meanwhile), with no errors.
     *
     * @param array<string, bool> $permissions
     * @return array{status: string, counts?: array{create: int, update: int, same: int}, reason?: string}
     */
    public function apply(string $actor, string $structureId, string $csv, string $digest, array $permissions): array
    {
        $plan = $this->plan($actor, $structureId, $csv, $permissions);
        if ($plan['errors'] !== []) {
            return ['status' => 'rejected', 'reason' => 'plan_has_errors'];
        }
        if (!hash_equals($plan['digest'], $digest)) {
            return ['status' => 'rejected', 'reason' => 'changed_meanwhile'];
        }
        $scope = $this->scopes->scopeForActor($actor);
        try {
            $this->database->transaction(function () use ($plan, $scope, $structureId, $actor): void {
                foreach ($plan['rows'] as $row) {
                    if ($row['action'] === 'create') {
                        $this->workbench->createRecord($scope, $structureId, $row['values'], $actor);
                    } elseif ($row['action'] === 'update' && is_string($row['record_id']) && is_int($row['revision'])) {
                        $this->workbench->updateRecord($scope, $structureId, $row['record_id'], $row['revision'], $row['values'], $actor);
                    }
                }
            });
        } catch (Throwable) {
            return ['status' => 'rejected', 'reason' => 'write_refused'];
        }

        return ['status' => 'ok', 'counts' => $plan['counts']];
    }

    /**
     * One chunk of a planned import, inside the caller's transaction. Each row is written on its own savepoint:
     * a row Storage refuses is reported by its line and the others are kept. A row that changes a record which
     * already holds the planned values (the chunk was written before) counts as written, not as an error.
     * Anything else (the database itself failing) is thrown, so the caller's transaction writes nothing.
     *
     * @param list<array{line: int, action: string, record_id: ?string, revision: ?int, values: array<string, mixed>}> $rows
     * @return array{create: int, update: int, errors: list<array{line: int, column: string, reason: string}>}
     */
    public function applyRows(string $scope, string $structureId, array $rows, string $actor): array
    {
        $written = ['create' => 0, 'update' => 0, 'errors' => []];
        foreach ($rows as $row) {
            try {
                $this->database->transaction(function () use ($row, $scope, $structureId, $actor): void {
                    if ($row['action'] === 'create') {
                        $this->workbench->createRecord($scope, $structureId, $row['values'], $actor);
                    } elseif (is_string($row['record_id']) && is_int($row['revision'])) {
                        $this->workbench->updateRecord($scope, $structureId, $row['record_id'], $row['revision'], $row['values'], $actor);
                    }
                });
                $written[$row['action'] === 'create' ? 'create' : 'update']++;
            } catch (StorageConflict) {
                $current = null;
                try {
                    $current = is_string($row['record_id']) ? $this->workbench->readRecord($scope, $structureId, $row['record_id'], $actor) : null;
                } catch (StorageRejected) {
                }
                if ($current !== null && self::same($current, $row['values'])) {
                    $written['update']++;
                } else {
                    $written['errors'][] = ['line' => $row['line'], 'column' => 'ID', 'reason' => 'changed_meanwhile'];
                }
            } catch (StorageRejected|InvalidArgumentException) {
                $written['errors'][] = ['line' => $row['line'], 'column' => '', 'reason' => 'write_refused'];
            }
        }

        return $written;
    }

    /**
     * The plan of a stored file read by the queue window by window ({@see PLAN_WINDOW} rows): after each window
     * $checkpoint gets the state to continue from, and a run given that state as $resume continues after the
     * window, so an interrupted job reads no record twice. The result is the one {@see plan()} shows for the file.
     *
     * @param array<string, bool> $permissions
     * @param array<string, mixed>|null $resume
     * @param callable(array{line: int, rows: int, structure: string, counts: array{create: int, update: int, same: int},
     *     errors: list<array{line: int, column: string, reason: string}>, error_count: int, digest: string}): void $checkpoint
     * @return array{columns: list<array{header: string, key: ?string, field: ?string, skipped: ?string}>,
     *     errors: list<array{line: int, column: string, reason: string}>, error_count: int,
     *     counts: array{create: int, update: int, same: int}, digest: string, rows: int}
     */
    public function planStored(string $actor, string $structureId, string $csvPath, array $permissions, ?array $resume, callable $checkpoint): array
    {
        $csv = is_file($csvPath) && filesize($csvPath) <= self::MAX_BYTES ? file_get_contents($csvPath) : false;
        if (!is_string($csv)) {
            return self::refused('file_unreadable') + ['rows' => 0];
        }

        return $this->scan($actor, $structureId, $csv, $permissions, static function (): void {
        }, self::MAX_KEPT_ERRORS, $resume, $checkpoint);
    }

    /**
     * Reads the file into what each row does, handing every row that passes to $row. The rows are read in windows
     * of {@see PLAN_WINDOW}: the records a window names are read together ({@see records()}), then its rows are
     * planned in order. The digest covers the storage and every passing row, so the queue can tell whether the
     * file still plans as the person saw it.
     *
     * @param array<string, bool> $permissions
     * @param callable(array{line: int, action: string, record_id: ?string, revision: ?int, values: array<string, mixed>}): void $onRow
     * @param array<string, mixed>|null $resume
     * @param (callable(array{line: int, rows: int, structure: string, counts: array{create: int, update: int, same: int},
     *     errors: list<array{line: int, column: string, reason: string}>, error_count: int, digest: string}): void)|null $checkpoint
     * @return array{columns: list<array{header: string, key: ?string, field: ?string, skipped: ?string}>,
     *     errors: list<array{line: int, column: string, reason: string}>, error_count: int,
     *     counts: array{create: int, update: int, same: int}, digest: string, rows: int}
     */
    private function scan(string $actor, string $structureId, string $csv, array $permissions, callable $onRow, ?int $keepErrors,
        ?array $resume = null, ?callable $checkpoint = null): array
    {
        $scope = $this->scopes->scopeForActor($actor);
        $structure = $this->workbench->readStructure($scope, $structureId, $actor);
        $stream = self::stream($csv);
        if ($stream === null) {
            return self::refused('file_unreadable') + ['rows' => 0];
        }
        $fingerprint = $structure->version.':'.$structure->descriptorHash;
        $state = self::resumed($resume, $structureId, $fingerprint);
        $errors = $state['errors'];
        $errorCount = $state['error_count'];
        $error = static function (array $found) use (&$errors, &$errorCount, $keepErrors): void {
            $errorCount++;
            if ($keepErrors === null || count($errors) < $keepErrors) {
                $errors[] = $found;
            }
        };
        $fields = [];
        foreach ($structure->fields as $field) {
            $fields[(string) $field['key']] = $field;
        }
        $columns = [];
        $idColumn = null;
        $seen = [];
        $counts = $state['counts'];
        $digest = $state['digest'];
        $rows = 0;
        $size = null;
        $line = 0;
        $header = null;
        $window = [];
        $snapshot = static function () use (&$line, &$rows, &$counts, &$errors, &$errorCount, $digest, $fingerprint): array {
            return ['line' => $line, 'rows' => $rows, 'structure' => $fingerprint, 'counts' => $counts, 'errors' => $errors,
                'error_count' => $errorCount, 'digest' => base64_encode(serialize(hash_copy($digest)))];
        };
        try {
            foreach (self::cells($stream['stream'], $stream['delimiter']) as $cells) {
                $line++;
                if ($header === null) {
                    $header = $cells;
                    [$columns, $idColumn] = self::columns($cells, $fields);
                    continue;
                }
                if ($line - 1 > self::MAX_ROWS) {
                    return self::refused('too_many_rows') + ['rows' => $rows];
                }
                if (implode('', array_map('trim', $cells)) === '') {
                    continue;
                }
                $rows++;
                if ($line <= $state['line']) {
                    // Planned by an earlier run: only the IDs it saw are remembered, for «id_repeated».
                    $id = $idColumn !== null ? trim($cells[$idColumn] ?? '') : '';
                    if ($id !== '' && preg_match(self::RECORD_ID, $id) === 1) {
                        $seen[$id] = true;
                    }
                    continue;
                }
                $window[] = [$line, $cells];
                if (count($window) >= self::PLAN_WINDOW) {
                    $this->window($scope, $structureId, $actor, $window, $columns, $idColumn, $fields, $permissions, $seen, $counts, $size, $digest, $error, $onRow);
                    $window = [];
                    if ($checkpoint !== null) {
                        $checkpoint($snapshot());
                    }
                }
            }
            if ($window !== []) {
                $this->window($scope, $structureId, $actor, $window, $columns, $idColumn, $fields, $permissions, $seen, $counts, $size, $digest, $error, $onRow);
            }
        } finally {
            fclose($stream['stream']);
        }
        if ($header === null || $line < 2) {
            return ($header === null ? self::refused('file_unreadable') : self::refused('file_empty')) + ['rows' => $rows];
        }

        return ['columns' => array_values($columns), 'errors' => $errors, 'error_count' => $errorCount,
            'counts' => $counts, 'digest' => hash_final($digest), 'rows' => $rows];
    }

    /**
     * Plans one window of rows in their order, after reading the records they name together.
     *
     * @param list<array{0: int, 1: list<string>}> $window line and cells of each row
     * @param array<int, array{header: string, key: ?string, field: ?string, skipped: ?string}> $columns
     * @param array<string, array<string, mixed>> $fields
     * @param array<string, bool> $permissions
     * @param array<string, bool> $seen
     * @param array{create: int, update: int, same: int} $counts
     * @param callable(array{line: int, column: string, reason: string}): void $error
     * @param callable(array{line: int, action: string, record_id: ?string, revision: ?int, values: array<string, mixed>}): void $onRow
     */
    private function window(string $scope, string $structureId, string $actor, array $window, array $columns, ?int $idColumn, array $fields,
        array $permissions, array &$seen, array &$counts, ?int &$size, \HashContext $digest, callable $error, callable $onRow): void
    {
        $ids = [];
        foreach ($window as [, $cells]) {
            $id = $idColumn !== null ? trim($cells[$idColumn] ?? '') : '';
            if ($id !== '' && preg_match(self::RECORD_ID, $id) === 1 && !isset($seen[$id])) {
                $ids[$id] = true;
            }
        }
        $records = $this->records($scope, $structureId, $actor, array_keys($ids), $size);
        foreach ($window as [$line, $cells]) {
            $row = $this->row($line, $cells, $columns, $idColumn, $fields, $permissions, $seen, $records, $error);
            if ($row === null) {
                continue;
            }
            $action = $row['action'];
            if ($action === 'create' || $action === 'update' || $action === 'same') {
                $counts[$action]++;
            }
            hash_update($digest, json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n");
            $onRow($row);
        }
    }

    /**
     * Where a run continues: the state an earlier run of the same file left, or the start. A state of another
     * structure version, or one that does not read back, starts over.
     *
     * @param array<string, mixed>|null $resume
     * @return array{line: int, counts: array{create: int, update: int, same: int},
     *     errors: list<array{line: int, column: string, reason: string}>, error_count: int, digest: \HashContext}
     */
    private static function resumed(?array $resume, string $structureId, string $fingerprint): array
    {
        $start = hash_init('sha256');
        hash_update($start, $structureId."\n");
        $fresh = ['line' => 0, 'counts' => ['create' => 0, 'update' => 0, 'same' => 0], 'errors' => [], 'error_count' => 0, 'digest' => $start];
        if ($resume === null || ($resume['structure'] ?? null) !== $fingerprint || !is_int($resume['line'] ?? null) || $resume['line'] < 1
            || !is_int($resume['error_count'] ?? null) || !is_array($resume['counts'] ?? null) || !is_array($resume['errors'] ?? null)
            || !is_string($resume['digest'] ?? null)) {
            return $fresh;
        }
        $raw = base64_decode($resume['digest'], true);
        $digest = is_string($raw) ? @unserialize($raw, ['allowed_classes' => [\HashContext::class]]) : false;
        $counts = [];
        foreach (['create', 'update', 'same'] as $action) {
            $counts[$action] = is_int($resume['counts'][$action] ?? null) ? $resume['counts'][$action] : -1;
        }
        $errors = [];
        foreach ($resume['errors'] as $found) {
            if (is_array($found) && is_int($found['line'] ?? null) && is_string($found['column'] ?? null) && is_string($found['reason'] ?? null)) {
                $errors[] = ['line' => $found['line'], 'column' => $found['column'], 'reason' => $found['reason']];
            }
        }
        if (!$digest instanceof \HashContext || min($counts) < 0 || count($errors) !== count($resume['errors'])) {
            return $fresh;
        }

        return ['line' => $resume['line'], 'counts' => $counts, 'errors' => $errors, 'error_count' => $resume['error_count'], 'digest' => $digest];
    }

    /**
     * The records of a window's IDs, read together where Storage's list allows it. A storage of at most
     * {@see SCAN_FACTOR} records per wanted ID is read in pages of {@see SCAN_PAGE} in ID order, stopping once every
     * wanted record is found: a few queries for the window instead of one read per row. A larger storage, or a list
     * Storage refuses, reads each record by its ID. A record not found is left out (the row says «record_unknown»).
     *
     * @param list<string> $ids
     * @param int|null $size the storage's record count, counted once per plan (-1 when the list cannot be read)
     * @return array<string, StorageWorkbenchRecord>
     */
    private function records(string $scope, string $structureId, string $actor, array $ids, ?int &$size): array
    {
        if ($ids === []) {
            return [];
        }
        if ($size === null) {
            try {
                $size = $this->workbench->listRecords(new StorageWorkbenchRecordQuery($scope, $structureId, limit: 1, includeArchived: true), $actor)->matchedCount;
            } catch (Throwable) {
                $size = -1;
            }
        }
        $found = [];
        if ($size >= 0 && $size <= count($ids) * self::SCAN_FACTOR) {
            $wanted = array_fill_keys($ids, true);
            try {
                $continuation = null;
                do {
                    $page = $this->workbench->listRecords(new StorageWorkbenchRecordQuery($scope, $structureId,
                        sort: [['field' => '_record_id', 'direction' => 'asc']], limit: self::SCAN_PAGE, continuation: $continuation,
                        includeArchived: true, countMode: StorageWorkbenchCountMode::Skip), $actor);
                    foreach ($page->items as $record) {
                        if (isset($wanted[$record->recordId])) {
                            $found[$record->recordId] = $record;
                        }
                    }
                    $continuation = $page->continuation;
                } while ($continuation !== null && count($found) < count($wanted));

                return $found;
            } catch (Throwable) {
                $found = [];
            }
        }
        foreach ($ids as $id) {
            try {
                $found[$id] = $this->workbench->readRecord($scope, $structureId, $id, $actor);
            } catch (Throwable) {
            }
        }

        return $found;
    }

    /**
     * The header: «ID» names records; any other column is a field by its name or its key.
     *
     * @param list<string> $header
     * @param array<string, array<string, mixed>> $fields
     * @return array{0: array<int, array{header: string, key: ?string, field: ?string, skipped: ?string}>, 1: ?int}
     */
    private static function columns(array $header, array $fields): array
    {
        $columns = [];
        $idColumn = null;
        $taken = [];
        foreach ($header as $index => $text) {
            $text = trim($text);
            $name = mb_strtolower($text);
            if (in_array($name, ['id', 'record_id'], true)) {
                $idColumn = $index;
                $columns[$index] = ['header' => $text, 'key' => null, 'field' => 'ID', 'skipped' => null];
                continue;
            }
            $key = null;
            foreach ($fields as $fieldKey => $field) {
                if ($name !== '' && ($name === mb_strtolower((string) ($field['label'] ?? '')) || $name === mb_strtolower($fieldKey))) {
                    $key = $fieldKey;
                    break;
                }
            }
            $skipped = match (true) {
                $key === null => 'column_unknown',
                // A reference (Property: reference) names something the file cannot.
                RecordFilterFields::referenceType($fields[$key]['type'] ?? null) => 'column_reference',
                // An encrypted field is not imported: the import cannot compare what it would overwrite, and its
                // values never appear in a plan. The record keeps the value it has.
                StorageEncryptedFields::isEncrypted($fields[$key]) => 'column_encrypted',
                isset($taken[$key]) => 'column_repeated',
                default => null,
            };
            // A column is taken only when it names a field ($skipped is null only then).
            if ($skipped === null) {
                $taken[$key] = true;
            }
            $columns[$index] = ['header' => $text, 'key' => $skipped === null ? $key : null,
                'field' => $skipped === null ? (string) ($fields[$key]['label'] ?? $key) : null, 'skipped' => $skipped];
        }

        return [$columns, $idColumn];
    }

    /**
     * What one row does, or null when it is refused (its reasons go to $error).
     *
     * @param list<string> $cells
     * @param array<int, array{header: string, key: ?string, field: ?string, skipped: ?string}> $columns
     * @param array<string, array<string, mixed>> $fields
     * @param array<string, bool> $permissions
     * @param array<string, bool> $seen
     * @param array<string, StorageWorkbenchRecord> $records the window's records by their IDs
     * @param callable(array{line: int, column: string, reason: string}): void $error
     * @return array{line: int, action: string, record_id: ?string, revision: ?int, values: array<string, mixed>}|null
     */
    private function row(int $line, array $cells, array $columns, ?int $idColumn, array $fields, array $permissions, array &$seen,
        array $records, callable $error): ?array
    {
        $id = $idColumn !== null ? trim($cells[$idColumn] ?? '') : '';
        $current = null;
        if ($id !== '') {
            if (preg_match(self::RECORD_ID, $id) !== 1 || isset($seen[$id])) {
                $error(['line' => $line, 'column' => 'ID', 'reason' => isset($seen[$id]) ? 'id_repeated' : 'record_unknown']);

                return null;
            }
            $seen[$id] = true;
            $current = $records[$id] ?? null;
            if ($current === null) {
                $error(['line' => $line, 'column' => 'ID', 'reason' => 'record_unknown']);

                return null;
            }
            if ($current->state !== 'active') {
                $error(['line' => $line, 'column' => 'ID', 'reason' => 'record_archived']);

                return null;
            }
        }
        $rowErrors = [];
        $values = $current === null ? [] : $current->values;
        foreach ($columns as $index => $column) {
            if ($column['key'] === null) {
                continue;
            }
            [$value, $reason] = self::value($fields[$column['key']], self::unescape(trim($cells[$index] ?? '')));
            if ($reason !== null) {
                $rowErrors[] = ['line' => $line, 'column' => $column['header'], 'reason' => $reason];
            } elseif ($value === null) {
                unset($values[$column['key']]);
            } else {
                $values[$column['key']] = $value;
            }
        }
        foreach ($fields as $key => $field) {
            if (($field['required'] ?? false) === true && !array_key_exists($key, $values)) {
                $rowErrors[] = ['line' => $line, 'column' => (string) ($field['label'] ?? $key), 'reason' => 'value_required'];
            }
        }
        $action = $current === null ? 'create' : (self::same($current, $values) ? 'same' : 'update');
        if ($action !== 'same' && ($permissions['record.'.$action] ?? false) !== true) {
            $rowErrors[] = ['line' => $line, 'column' => $id !== '' ? 'ID' : '', 'reason' => $action.'_denied'];
        }
        if ($rowErrors !== []) {
            foreach ($rowErrors as $found) {
                $error($found);
            }

            return null;
        }
        ksort($values, SORT_STRING);

        return ['line' => $line, 'action' => $action, 'record_id' => $current?->recordId,
            'revision' => $current?->revision, 'values' => $values];
    }

    /** @return array{columns: list<never>, errors: list<array{line: int, column: string, reason: string}>, error_count: int, counts: array{create: int, update: int, same: int}, digest: string} */
    private static function refused(string $reason): array
    {
        return ['columns' => [], 'errors' => [['line' => 0, 'column' => '', 'reason' => $reason]], 'error_count' => 1,
            'counts' => ['create' => 0, 'update' => 0, 'same' => 0], 'digest' => ''];
    }

    /**
     * The file as a stream of UTF-8 text: UTF-8 or the Windows-1251 a Russian spreadsheet saves, with the
     * delimiter its header uses.
     *
     * @return array{stream: resource, delimiter: string}|null
     */
    private static function stream(string $csv): ?array
    {
        if ($csv === '' || strlen($csv) > self::MAX_BYTES) {
            return null;
        }
        if (str_starts_with($csv, "\u{FEFF}")) {
            $csv = substr($csv, 3);
        }
        if (!mb_check_encoding($csv, 'UTF-8')) {
            $converted = mb_convert_encoding($csv, 'UTF-8', 'Windows-1251');
            if (!mb_check_encoding($converted, 'UTF-8')) {
                return null;
            }
            $csv = $converted;
        }
        $first = strtok($csv, "\n");
        $first = is_string($first) ? $first : '';
        $counts = [';' => substr_count($first, ';'), ',' => substr_count($first, ','), "\t" => substr_count($first, "\t")];
        arsort($counts);
        $delimiter = (string) array_key_first($counts);
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            return null;
        }
        fwrite($stream, $csv);
        rewind($stream);

        return ['stream' => $stream, 'delimiter' => $delimiter];
    }

    /**
     * @param resource $stream
     * @return \Generator<int, list<string>>
     */
    private static function cells($stream, string $delimiter): \Generator
    {
        while (($cells = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            // A blank line comes back as one null cell.
            yield array_map(static fn (?string $cell): string => rtrim($cell ?? '', "\r"), $cells);
        }
    }

    /** The export writes a value a spreadsheet would run as a formula behind an apostrophe; it comes back without it. */
    private static function unescape(string $cell): string
    {
        return preg_match("/^'[=+\\-@\\t\\r]/", $cell) === 1 ? substr($cell, 1) : $cell;
    }

    /**
     * A cell as the field stores it: [value, null], [null, null] for an empty cell, or [null, reason].
     *
     * @param array<string, mixed> $field
     * @return array{0: mixed, 1: ?string}
     */
    private static function value(array $field, string $cell): array
    {
        if ($cell === '') {
            return [null, null];
        }
        $plain = (string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', $cell);
        $options = is_array($field['constraints']['options'] ?? null) ? $field['constraints']['options'] : [];
        $option = static function (string $text) use ($options): ?string {
            foreach ($options as $option) {
                if (is_array($option) && is_scalar($option['value'] ?? null)
                    && ((string) $option['value'] === $text || mb_strtolower((string) ($option['label'] ?? '')) === mb_strtolower($text))) {
                    return (string) $option['value'];
                }
            }

            return null;
        };

        return match ($field['type'] ?? 'string') {
            'integer' => preg_match('/^-?\d{1,18}$/', $plain) === 1 ? [(int) $plain, null] : [null, 'value_not_integer'],
            'number' => self::decimal(str_replace(',', '.', $plain)),
            'boolean' => match (mb_strtolower($cell)) {
                'да', 'д', 'yes', 'y', 'true', '1', 'истина' => [true, null],
                'нет', 'н', 'no', 'n', 'false', '0', 'ложь' => [false, null],
                default => [null, 'value_not_boolean'],
            },
            'date' => self::date($cell),
            'datetime' => self::dateTime($cell),
            'choice' => ($value = $option($cell)) !== null ? [$value, null] : [null, 'value_not_an_option'],
            'choices' => self::choices($cell, $option),
            default => [$cell, null],
        };
    }

    /**
     * A number as Property keeps it: a decimal string with no leading zeros and no trailing zeros after the point.
     * The section's record writes take a typed number the same way.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function decimal(string $text): array
    {
        if (preg_match('/^([+-]?)(\d+)(?:\.(\d+))?$/', $text, $m) !== 1) {
            return [null, 'value_not_number'];
        }
        $integer = ltrim($m[2], '0');
        $fraction = rtrim($m[3] ?? '', '0');
        $normalized = ($integer === '' ? '0' : $integer).($fraction === '' ? '' : '.'.$fraction);

        return [$m[1] === '-' && $normalized !== '0' ? '-'.$normalized : $normalized, null];
    }

    /** @return array{0: ?string, 1: ?string} */
    private static function date(string $cell): array
    {
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $cell, $m) === 1) {
            [$day, $month, $year] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $cell, $m) === 1) {
            [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return [null, 'value_not_date'];
        }

        return checkdate($month, $day, $year) ? [sprintf('%04d-%02d-%02d', $year, $month, $day), null] : [null, 'value_not_date'];
    }

    /** @return array{0: ?string, 1: ?string} */
    private static function dateTime(string $cell): array
    {
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})[ T](\d{2}):(\d{2})(?::(\d{2}))?$/', $cell, $m) === 1) {
            [$day, $month, $year] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?$/', $cell, $m) === 1) {
            [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return [null, 'value_not_datetime'];
        }
        [$hour, $minute, $second] = [(int) $m[4], (int) $m[5], (int) ($m[6] ?? 0)];
        if (!checkdate($month, $day, $year) || $hour > 23 || $minute > 59 || $second > 59) {
            return [null, 'value_not_datetime'];
        }

        return [sprintf('%04d-%02d-%02dT%02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second), null];
    }

    /**
     * @param callable(string): ?string $option
     * @return array{0: ?list<string>, 1: ?string}
     */
    private static function choices(string $cell, callable $option): array
    {
        $values = [];
        foreach (array_filter(array_map('trim', explode(',', $cell)), static fn (string $part): bool => $part !== '') as $part) {
            $value = $option($part);
            if ($value === null) {
                return [null, 'value_not_an_option'];
            }
            $values[] = $value;
        }

        return [array_values(array_unique($values)), null];
    }

    /** @param array<string, mixed> $values */
    private static function same(StorageWorkbenchRecord $current, array $values): bool
    {
        $before = $current->values;
        ksort($before, SORT_STRING);
        ksort($values, SORT_STRING);

        return json_encode($before) === json_encode($values);
    }
}
