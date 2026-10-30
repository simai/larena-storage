<?php

declare(strict_types=1);

namespace Larena\Storage\Admin;

use InvalidArgumentException;
use JsonException;
use Larena\Access\Contracts\ActorOperationAuthorizer;
use Larena\Access\Exceptions\AccessMutationRejected;
use Larena\Storage\Admin\Contracts\StorageSectionScope;
use Larena\Storage\Admin\Contracts\StorageRecordSearch;
use Larena\Storage\Admin\Contracts\StorageStructureSearch;
use Larena\Dataview\Enums\DataviewViewType;
use Larena\Dataview\Runtime\DataviewRoleBinder;
use Larena\Storage\Admin\Contracts\StorageWorkbenchGateway;
use Larena\Filesystem\Contracts\PersistentLogicalFileInspector;
use Larena\Property\Enums\PropertyValueKind;
use Larena\Property\Runtime\PropertyTypeSettingsCatalog;
use Larena\Storage\Contracts\StorageWorkbench;
use Larena\Storage\Contracts\StorageWorkbenchEncryption;
use Larena\Storage\Contracts\StorageWorkbenchFieldChangeCheck;
use Larena\Storage\Contracts\StorageWorkbenchRecord;
use Larena\Storage\Contracts\StorageWorkbenchRecordQuery;
use Larena\Storage\Enums\StorageWorkbenchCountMode;
use Larena\Storage\Contracts\StorageWorkbenchStructure;
use Larena\Storage\Encryption\StorageEncryptionPolicy;
use Larena\Storage\Exceptions\StorageConflict;
use Larena\Storage\Exceptions\StorageRejected;
use Larena\Storage\Presets\StoragePresets;
use RuntimeException;
use Throwable;

/**
 * The admin Storage section over Storage's workbench: storages (structures) and their
 * records, plus the admin search over storages. Every read and write goes through the
 * Access authorizer and Storage's own operations; the product composition only says which
 * scope an actor works in ({@see StorageSectionScope}).
 *
 * Files, block documents, page layouts and publications are not part of the section: a
 * snapshot for another surface is rejected and their actions are unknown here. A product
 * that offers them decorates this gateway and delegates the section paths to it.
 */
final readonly class StorageSectionGateway implements StorageWorkbenchGateway, StorageStructureSearch, StorageRecordSearch, \Larena\Admin\Contracts\StorageMenuStructures
{
    public const SURFACE = 'storage_section';

    /**
     * Owner input refusals raised through Laravel validation (Filesystem's inspector raises
     * them for a malformed reference) are rejected requests, like Storage's own refusals.
     * Named, not imported: Admin itself does not depend on illuminate/validation.
     */
    private const VALIDATION_EXCEPTION = 'Illuminate\\Validation\\ValidationException';
    private const MAX_JSON_BYTES = 1_048_576;
    private const MAX_STRUCTURES = 100;

    /** At most this many records are archived at once by an action on everything a filter finds. */
    private const MAX_BULK_MATCHING = 1000;
    /**
     * Storage's refusals of a field's search and index options (StorageViewFieldOptions): the
     * field editor explains each in its own words, so they pass through as they are.
     */
    public const FIELD_OPTION_REASONS = [
        'storage_field_option_invalid',
        'storage_field_searchable_type_unsupported',
        'storage_field_indexed_type_unsupported',
        'storage_field_indexed_max_length_invalid',
        'storage_field_index_limit_exceeded',
    ];
    /**
     * Storage's refusals about encrypted fields («Шифровать»: StorageEncryptionPolicy, the envelope, the reveal): the
     * field editor and the record panel explain each in their own words, so they pass through as they are.
     */
    public const ENCRYPTION_REASONS = [...StorageEncryptionPolicy::REASON_CODES, 'storage_field_reveal_invalid',
        // Why an encryption run stopped (StorageFieldEncryptionRunner), said again when a retry is refused for it.
        'storage_field_encryption_key_still_used', 'storage_field_encryption_record_corrupt', 'storage_field_encryption_interrupted',
        // The field editor's own: switching encryption on an existing field waits for «Подтверждаю включение шифрования».
        self::ENCRYPTION_CONFIRM_REQUIRED];
    /** The field editor asks to confirm switching encryption on an existing field before Storage is asked. */
    public const ENCRYPTION_CONFIRM_REQUIRED = 'storage_field_encryption_confirm_required';
    /** A saved change, a key change or a retry whose encryption run goes on in the background. */
    public const ENCRYPTION_STARTED = 'storage_field_encryption_started';
    /** The structure-descriptor flag Storage asks for to switch encryption off a field with values. */
    public const ENCRYPTION_DISABLE_CONFIRMED = 'encryption_disable_confirmed';
    /** Storage refuses a structure change or archiving while a field change runs. */
    public const FIELD_CHANGE_RUNNING = 'storage_workbench_field_change_running';
    /** A saved structure change that goes on in the background (a large storage's field change). */
    public const FIELD_CHANGE_STARTED = 'storage_workbench_field_change_started';
    /** A field change running in the background was stopped: the structure keeps its fields. */
    public const FIELD_CHANGE_CANCELLED = 'storage_workbench_field_change_cancelled';
    /** Storage refuses a cancel once the field change has ended (switched, failed or cancelled). */
    public const FIELD_CHANGE_NOT_RUNNING = 'storage_workbench_field_change_not_running';
    /**
     * Storage lists records from the storage's list table (витрина) only. A large storage whose list is still being
     * built in the background refuses a list, and a permanent deletion that needs a referencing storage's list, with
     * this reason: the page asks to come back in a few minutes.
     */
    public const VIEW_NOT_READY = 'storage_view_not_ready';
    /** No list table can exist here (no sync, an unsupported database, the registry not migrated): an operator's matter. */
    public const VIEW_UNAVAILABLE = 'storage_view_unavailable';
    /**
     * A record's value one field cannot take: the receipt names the field (`field`) and why (`field_reason`, the
     * section's own reason or Property's), so the record panel says it under that field instead of a general refusal.
     */
    public const FIELD_VALUE_INVALID = 'storage_workbench_field_value_invalid';
    /**
     * The field editor's input mistakes the page explains in their own words: a key the section keeps for itself,
     * a «Storage record» field without an active storage of the scope to point at, a maximum length out of range,
     * a key another field already has, a label that is empty, too long or carries markup.
     */
    public const FIELD_INPUT_REASONS = [
        'storage_workbench_key_unsafe',
        'storage_workbench_key_taken',
        'storage_workbench_field_target_invalid',
        'storage_workbench_field_max_length_invalid',
        'storage_workbench_field_type_invalid',
        'storage_workbench_text_invalid',
        // A field's settings that Property refuses together (a minimum above the maximum) or a locked one changed.
        'storage_workbench_field_settings_invalid',
        'storage_field_setting_locked',
    ];
    /** A change of the fields that touches the stored records and was not confirmed (`confirm_migration`). */
    public const MIGRATION_UNCONFIRMED = 'storage_workbench_migration_unconfirmed';
    /** The longest string a field may be limited to (Storage keeps a value of at most 64 KiB). */
    public const MAX_STRING_LENGTH = 65_535;
    private const FORBIDDEN_KEYS = [
        'path', 'physical_path', 'disk', 'storage_key', 'secret', 'token', 'password',
        'private', 'private_metadata', 'previous', 'exception', 'trace', 'stack', 'html',
        'raw_html',
    ];
    private const PERMISSIONS = [
        'structure.create' => 'storage.workbench.structure.create',
        // «Заготовка» of a new storage: its fields and its role, with the same right as an empty one.
        'structure.create_preset' => 'storage.workbench.structure.create',
        // «Отвязать роль»: an ordinary storage again, with the right to change a storage.
        'structure.unbind_role' => 'storage.workbench.structure.update',
        'structure.update' => 'storage.workbench.structure.update',
        'record.create' => 'storage.workbench.record.create',
        'record.update' => 'storage.workbench.record.update',
        'record.revert' => 'storage.workbench.record.update',
        'record.archive' => 'storage.workbench.record.archive',
        'record.bulk_archive' => 'storage.workbench.record.bulk_archive',
        'record.restore' => 'storage.workbench.record.restore',
        'record.purge' => 'storage.workbench.record.purge',
        'structure.configure' => 'storage.workbench.structure.update',
        'structure.history_keep' => 'storage.workbench.structure.update',
        'structure.migrate' => 'storage.workbench.structure.migrate',
        'structure.archive' => 'storage.workbench.structure.archive',
        'structure.restore' => 'storage.workbench.structure.restore',
        'structure.purge' => 'storage.workbench.structure.purge',
        // «Перенос на другой сайт»: moving the storage as a SitePack (high risk, always audited by Storage).
        'sitepack.export' => 'storage.sitepack.export',
        // «Загрузить хранилище»: a storage from a SitePack archive (critical, always audited by Storage), and whether
        // its records may keep their publication.
        'sitepack.import' => 'storage.sitepack.import',
        'publication.publish' => 'storage.publication.publish',
        // «Шифрование»: switching encryption on and off filled fields, the key change and «Повторить» of a stopped
        // run (critical, Storage asks for it too), and reading encrypted values (a SitePack export in plaintext).
        'encryption.manage' => 'storage.encryption.manage',
        'record.decrypt' => 'storage.workbench.record.decrypt',
    ];

    public function __construct(
        private ActorOperationAuthorizer $authorizer,
        private StorageSectionScope $scopes,
        private StorageWorkbench $workbench,
        private PersistentLogicalFileInspector $fileInspector,
        // The encryption runs of the structures (key change, retry); the workbench itself when it offers them.
        private ?StorageWorkbenchEncryption $encryption = null,
    ) {
    }

    /**
     * The storages the Storage section lists for this actor, so a search result always opens
     * there; archived storages are left out.
     */
    public function searchStructures(string $actor, ?string $requestedScope, string $search, int $limit): array
    {
        $search = mb_strtolower(trim($search));
        $limit = max(1, min(50, $limit));
        if ($search === '' || mb_strlen($search) > 100 || preg_match('/[\x00-\x1F\x7F]/', $search) === 1) {
            return [];
        }
        $state = $this->snapshot($actor, $requestedScope, ['surface' => self::SURFACE]);
        if (($state['status'] ?? null) !== 'ok' || ! is_array($state['structures'] ?? null)) {
            return [];
        }
        $found = [];
        foreach ($state['structures'] as $structure) {
            if (count($found) >= $limit) {
                break;
            }
            if (($structure['archived'] ?? false) === true
                || ! str_contains(mb_strtolower($structure['label'].' '.$structure['structure_id']), $search)) {
                continue;
            }
            $found[] = ['structure_id' => $structure['structure_id'], 'label' => $structure['label'], 'field_count' => count($structure['fields'])];
        }

        return $found;
    }

    public function menuStructures(string $actor): array
    {
        try {
            $state = $this->snapshot($actor, null, ['surface' => self::SURFACE]);
        } catch (Throwable) {
            return [];
        }
        $listed = [];
        foreach (($state['status'] ?? null) === 'ok' && is_array($state['structures'] ?? null) ? $state['structures'] : [] as $structure) {
            if (($structure['archived'] ?? false) !== true && is_string($structure['structure_id'] ?? null)) {
                $listed[] = ['structure_id' => $structure['structure_id'], 'label' => (string) ($structure['label'] ?? $structure['structure_id'])];
            }
        }

        return $listed;
    }

    /**
     * Records whose text matches, in the active storages the actor may list, named by the field that
     * titles a record in the table; a record without a title is named by its id.
     */
    public function searchRecords(string $actor, ?string $requestedScope, string $search, int $limit): array
    {
        $search = trim($search);
        $limit = max(1, min(50, $limit));
        if ($search === '' || mb_strlen($search) > 100 || preg_match('/[\x00-\x1F\x7F]/', $search) === 1) {
            return [];
        }
        try {
            $scopeRef = $this->scope($actor, $requestedScope);
            $structures = $this->workbench->listStructures($scopeRef, $actor);
        } catch (Throwable) {
            return [];
        }
        $found = [];
        foreach (array_slice($structures, 0, 50) as $structure) {
            if (count($found) >= $limit) {
                break;
            }
            // The field that titles a record in the table, else the first text field.
            $title = (new DataviewRoleBinder)->bind(DataviewViewType::Table,
                array_map('strval', array_column($structure->fields, 'type', 'key')), ['title' => 'title'])['bindings']['title'] ?? null;
            foreach ($title === null ? $structure->fields : [] as $field) {
                if (in_array($field['type'] ?? null, ['string', 'text'], true) && ($field['hidden'] ?? false) !== true) {
                    $title = (string) $field['key'];
                    break;
                }
            }
            try {
                // Quick search shows the first matches only: no count.
                $page = $this->workbench->listRecords(new StorageWorkbenchRecordQuery($scopeRef, $structure->structureId,
                    search: $search, limit: $limit - count($found), countMode: StorageWorkbenchCountMode::Skip), $actor);
            } catch (AccessMutationRejected|StorageRejected) {
                continue;
            }
            foreach ($page->items as $record) {
                $value = is_string($title) ? ($record->values[$title] ?? null) : null;
                $found[] = [
                    'structure_id' => $structure->structureId,
                    'structure_label' => $structure->label,
                    'record_id' => $record->recordId,
                    'title' => is_string($value) && trim($value) !== '' ? trim($value) : $record->recordId,
                ];
            }
        }

        return array_slice($found, 0, $limit);
    }

    public function snapshot(string $actor, ?string $requestedScope, array $query = []): array
    {
        try {
            $this->authorizer->assertAllowed($actor, 'admin.frontend.read');
            $scopeRef = $this->scope($actor, $requestedScope);
            if (($query['surface'] ?? null) !== self::SURFACE) {
                throw new InvalidArgumentException('storage_workbench_surface_unsupported');
            }
            // The Storage section reads storages and records only: it neither needs
            // nor may require the file, content and page permissions of the workbench.
            $state = $this->structureState($actor, $scopeRef, $query, ($query['include_archived_structures'] ?? false) === true);

            return [
                'status' => 'ok',
                'reason_code' => 'storage_workbench_ready',
                'scope_ref' => $scopeRef,
                'structures' => $state['structures'],
                'selected_structure' => $state['selected_structure'],
                'records' => $state['records'],
                'files' => ['nodes' => []],
                'documents' => [],
                'pages' => [],
                'publication' => null,
                'permissions' => $this->permissions($actor),
                'limits' => [
                    'structures' => self::MAX_STRUCTURES,
                    'records_per_page' => 100,
                    'bulk' => 100,
                    'payload_bytes' => self::MAX_JSON_BYTES,
                    'tree_depth' => 32,
                ],
            ];
        } catch (AccessMutationRejected) {
            return $this->state('denied', 'storage_workbench_access_denied');
        } catch (StorageRejected $exception) {
            // A list not built yet, or one that cannot exist here, is unavailable, never rejected: a rejected list is
            // asked again without its filters, which cannot help.
            return match ($exception->reasonCode) {
                self::VIEW_NOT_READY, self::VIEW_UNAVAILABLE => $this->state('unavailable', $exception->reasonCode),
                default => $this->state('rejected', 'storage_workbench_request_rejected'),
            };
        } catch (InvalidArgumentException|JsonException) {
            return $this->state('rejected', 'storage_workbench_request_rejected');
        } catch (RuntimeException $exception) {
            if ($this->isScopeDenial($exception)) {
                return $this->state('denied', 'storage_workbench_scope_denied');
            }

            return $this->state('rejected', 'storage_workbench_request_rejected');
        } catch (Throwable $exception) {
            return is_a($exception, self::VALIDATION_EXCEPTION)
                ? $this->state('rejected', 'storage_workbench_request_rejected')
                : $this->state('unavailable', 'storage_workbench_runtime_unavailable');
        }
    }

    public function dispatch(string $actor, ?string $requestedScope, string $action, array $payload): array
    {
        try {
            $this->authorizer->assertAllowed($actor, 'admin.frontend.read');
            $scopeRef = $this->scope($actor, $requestedScope);
            $redirect = match ($action) {
                'structure.create' => $this->createStructure($actor, $scopeRef, $payload),
                'structure.create_preset' => $this->createPreset($actor, $scopeRef, $payload),
                'structure.unbind_role' => $this->unbindRole($actor, $scopeRef, $payload),
                'structure.update' => $this->updateStructure($actor, $scopeRef, $payload),
                'record.create' => $this->createRecord($actor, $scopeRef, $payload),
                'record.update' => $this->updateRecord($actor, $scopeRef, $payload),
                'record.revert' => $this->revertRecord($actor, $scopeRef, $payload),
                'record.archive' => $this->archiveRecord($actor, $scopeRef, $payload),
                'record.bulk_archive' => $this->bulkArchive($actor, $scopeRef, $payload),
                'record.bulk_archive_matching' => $this->bulkArchiveMatching($actor, $scopeRef, $payload),
                'record.restore' => $this->restoreRecord($actor, $scopeRef, $payload),
                'record.purge' => $this->purgeRecords($actor, $scopeRef, $payload),
                'structure.configure' => $this->configureStructure($actor, $scopeRef, $payload),
                'structure.configure_check' => $this->checkConfigure($actor, $scopeRef, $payload),
                'structure.history_keep' => $this->historyKeep($actor, $scopeRef, $payload),
                'structure.archive' => $this->changeStructureState($actor, $scopeRef, $payload, true),
                'structure.restore' => $this->changeStructureState($actor, $scopeRef, $payload, false),
                'structure.purge' => $this->purgeStructure($actor, $scopeRef, $payload),
                'structure.field_change_cancel' => $this->cancelFieldChange($actor, $scopeRef, $payload),
                'structure.encryption_rotate' => $this->rotateEncryptionKey($actor, $scopeRef, $payload),
                'structure.encryption_retry' => $this->retryEncryptionRun($actor, $scopeRef, $payload),
                default => throw new InvalidArgumentException('storage_workbench_action_unknown'),
            };

            // A field change that checks every record of a large storage goes on in the background, and so does an
            // encryption run that seals, opens or re-seals the stored values.
            return $this->receipt('ok', match (true) {
                ($redirect['encryption'] ?? null) === 'running' => self::ENCRYPTION_STARTED,
                ($redirect['field_change'] ?? null) === 'running' => self::FIELD_CHANGE_STARTED,
                ($redirect['field_change'] ?? null) === 'cancelled' => self::FIELD_CHANGE_CANCELLED,
                default => 'storage_workbench_action_applied',
            }, $scopeRef, array_diff_key($redirect, ['field_change' => true, 'encryption' => true]));
            // A check of a change («structure.configure_check») answers what it found beside the receipt (redirect.check).
        } catch (AccessMutationRejected) {
            return $this->receipt('denied', 'storage_workbench_access_denied', null);
        } catch (StorageConflict) {
            return $this->receipt('rejected', 'storage_workbench_revision_conflict', $requestedScope);
        } catch (StorageRejected $exception) {
            // A migration refused because some record cannot take the new field
            // settings is the one refusal the page explains in its own words.
            return $this->receipt('rejected', match (true) {
                $exception->reasonCode === 'storage_schema_migration_record_unconvertible' => 'storage_workbench_records_unconvertible',
                // A field change runs in the background: the page names the field and asks to wait.
                $exception->reasonCode === self::FIELD_CHANGE_RUNNING => self::FIELD_CHANGE_RUNNING,
                // A cancel that came after the change ended: the page says it is already over.
                $exception->reasonCode === self::FIELD_CHANGE_NOT_RUNNING => self::FIELD_CHANGE_NOT_RUNNING,
                // A list still being built (a permanent deletion checks the storages that point here): wait a few minutes.
                $exception->reasonCode === self::VIEW_NOT_READY => self::VIEW_NOT_READY,
                in_array($exception->reasonCode, self::FIELD_OPTION_REASONS, true) => $exception->reasonCode,
                in_array($exception->reasonCode, self::ENCRYPTION_REASONS, true) => $exception->reasonCode,
                in_array($exception->reasonCode, ['storage_role_binding_published', 'storage_binding_unknown'], true) => $exception->reasonCode,
                // A site page's or record's address refused: the panel says why in its own words.
                str_starts_with($exception->reasonCode, 'site_page_'), str_starts_with($exception->reasonCode, 'site_record_') => $exception->reasonCode,
                default => 'storage_workbench_action_rejected',
            }, $requestedScope);
        } catch (InvalidArgumentException|JsonException $exception) {
            $message = $exception->getMessage();
            // A value one field cannot take names that field and why, for the panel to say it under the field.
            if (str_starts_with($message, self::FIELD_VALUE_INVALID.'|')) {
                [, $field, $why] = array_pad(explode('|', $message, 3), 3, '');

                return $this->receipt('rejected', self::FIELD_VALUE_INVALID, $requestedScope, [], ['field' => $field, 'field_reason' => $why]);
            }

            // Options typed wrongly and the field editor's own input mistakes are explained in their own words.
            return $this->receipt('rejected', match (true) {
                str_starts_with($message, 'storage_workbench_field_options_') => 'storage_workbench_field_options_invalid',
                in_array($message, self::FIELD_INPUT_REASONS, true) => $message,
                // A change that touches the stored records and was not confirmed: the editor checks it and asks.
                $message === self::MIGRATION_UNCONFIRMED => $message,
                default => 'storage_workbench_action_rejected',
            }, $requestedScope);
        } catch (RuntimeException $exception) {
            if ($this->isScopeDenial($exception)) {
                return $this->receipt('denied', 'storage_workbench_scope_denied', null);
            }

            return $this->receipt('rejected', 'storage_workbench_action_rejected', $requestedScope);
        } catch (Throwable $exception) {
            return is_a($exception, self::VALIDATION_EXCEPTION)
                ? $this->receipt('rejected', 'storage_workbench_action_rejected', $requestedScope)
                : $this->receipt('unavailable', 'storage_workbench_runtime_unavailable', null);
        }
    }

    /**
     * The storages and records part of a snapshot, for an actor already authorized in
     * $scopeRef. A product that extends the snapshot with its own parts builds on this.
     * Throws Storage, scope and input exceptions for the caller to answer.
     *
     * @param array<string, mixed> $query
     * @return array{structures: list<array<string, mixed>>, selected_structure: array<string, mixed>|null, records: array<string, mixed>}
     */
    public function structureState(string $actor, string $scopeRef, array $query, bool $includeArchivedStructures): array
    {
        if (($query['input_invalid'] ?? false) === true || count($query) > 16) {
            throw new InvalidArgumentException('storage_workbench_query_invalid');
        }
        $structures = $this->workbench->listStructures($scopeRef, $actor, $includeArchivedStructures);
        if (count($structures) > self::MAX_STRUCTURES) {
            throw new RuntimeException('storage_workbench_structure_limit_exceeded');
        }
        // Other owners may store fields outside this admin surface's safe-key
        // policy. Keep those structures out of this workbench without making
        // every other structure in the actor's scope unavailable.
        $structures = array_values(array_filter($structures, $this->isProjectionSafeStructure(...)));
        $structureId = $this->nullableIdentity($query['structure_id'] ?? null, '/\Aworkbench\.[a-z][a-z0-9_.:-]{0,109}\z/D');
        $selected = null;
        $records = ['items' => [], 'continuation' => null, 'matched_count' => 0, 'matched_count_exact' => true,
            'sort_unindexed' => [], 'large' => false, 'sort_default' => false, 'history' => []];
        if ($structureId !== null) {
            $selected = $this->workbench->readStructure($scopeRef, $structureId, $actor);
        }
        // A page that needs the storage and not its list (settings, import, jobs, one record) asks with records = skip,
        // so it opens while a large storage's list is still being built.
        if ($structureId !== null && ($query['records'] ?? null) !== 'skip') {
            [$sort, $sortDefault] = $this->sort($query);
            $recordQuery = new StorageWorkbenchRecordQuery(
                scopeRef: $scopeRef,
                structureId: $structureId,
                filters: $this->filters($query),
                search: $this->nullableText($query['search'] ?? null, 100),
                sort: $sort,
                limit: $this->integer($query['per_page'] ?? $query['limit'] ?? 50, 1, 100),
                continuation: $this->nullableText($query['continuation'] ?? null, 2048),
                includeArchived: ($query['include_archived'] ?? false) === true,
                page: isset($query['page']) ? $this->integer($query['page'], 1, 10000) : null,
                // A large list is counted approximately; «count exactly» asks again with count = exact,
                // and a reader that only pages on (an export) asks with count = skip.
                countMode: match ($query['count'] ?? null) {
                    'exact' => StorageWorkbenchCountMode::Exact,
                    'skip' => StorageWorkbenchCountMode::Skip,
                    default => StorageWorkbenchCountMode::Auto,
                },
            );
            $page = $this->workbench->listRecords($recordQuery, $actor);
            $records = [
                'items' => array_map($this->recordProjection(...), $page->items),
                'continuation' => $page->continuation,
                'matched_count' => $page->matchedCount,
                'matched_count_exact' => $page->countExact,
                // The sorted fields the list has no index for, and whether the list is large: a slow sort
                // the page hints at (StorageSortHint).
                'sort_unindexed' => $page->sortUnindexed,
                'large' => $page->large,
                // No sort was asked for: the list is in its own order, newest first (StorageListOrder), and the page
                // says so; it is not the person's choice and nothing keeps it.
                'sort_default' => $sortDefault,
                'history' => [],
            ];
        }
        // The asked record's history is read by its ID, without the list.
        $recordId = $structureId === null ? null : $this->nullableRecordId($query['record_id'] ?? null);
        if ($structureId !== null && $recordId !== null) {
            $records['history'] = array_map(
                $this->recordProjection(...),
                $this->workbench->recordHistory($scopeRef, $structureId, $recordId, $actor),
            );
        }

        return [
            'structures' => array_map($this->structureProjection(...), $structures),
            'selected_structure' => $selected === null ? null : $this->structureProjection($selected),
            'records' => $records,
        ];
    }

    /**
     * Which storage and record actions the actor may take, by action name.
     *
     * @return array<string, bool>
     */
    public function permissions(string $actor): array
    {
        $result = [];
        foreach (self::PERMISSIONS as $key => $operation) {
            try {
                $this->authorizer->assertAllowed($actor, $operation);
                $result[$key] = true;
            } catch (Throwable) {
                $result[$key] = false;
            }
        }

        return $result;
    }

    /** @param array<string, mixed> $payload @return array<string, string> */
    private function createStructure(string $actor, string $scopeRef, array $payload): array
    {
        $structure = $this->workbench->createStructure($scopeRef, $this->structureDescriptor($payload, $scopeRef, $actor), $actor);

        return ['scope_ref' => $scopeRef, 'structure_id' => $structure->structureId];
    }

    /** @param array<string, mixed> $payload @return array<string, string> */
    private function createPreset(string $actor, string $scopeRef, array $payload): array
    {
        $preset = $payload['preset'] ?? null;
        if (!is_string($preset) || !in_array($preset, StoragePresets::KEYS, true) || $preset === StoragePresets::PLAIN) {
            throw new InvalidArgumentException('storage_preset_unknown');
        }
        $structure = \Illuminate\Container\Container::getInstance()->make(StoragePresets::class)
            ->create($scopeRef, $this->structureId($payload), $this->text($payload['label'] ?? null, 1, 120), $preset, $actor);

        return ['scope_ref' => $scopeRef, 'structure_id' => $structure->structureId];
    }

    /**
     * Ends the storage's binding to the role the payload names, in the scopes it is bound in. A storage with records still
     * out under the role is refused in words.
     *
     * @param array<string, mixed> $payload @return array<string, string>
     */
    private function unbindRole(string $actor, string $scopeRef, array $payload): array
    {
        $structureId = $this->structureId($payload);
        $schemaId = $this->workbench->readStructure($scopeRef, $structureId, $actor)->schema->schemaId;
        $roleRef = $payload['role_ref'] ?? null;
        $site = $payload['site_ref'] ?? null;
        if (!is_string($roleRef) || !is_string($site)) {
            throw new InvalidArgumentException('storage_role_unbind_invalid');
        }
        try {
            \Illuminate\Container\Container::getInstance()->make(\Larena\Storage\Contracts\StructureRoleRegistry::class)->unbindStructure($roleRef, $schemaId, $site, $actor);
        } catch (\Larena\Storage\Exceptions\StructureRoleRejected $refused) {
            throw new StorageRejected('storage_'.$refused->reasonCode);
        }

        return ['scope_ref' => $scopeRef, 'structure_id' => $structureId];
    }

    /**
     * The whole structure from its descriptor (the JSON path). The same rule as the field editor decides whether it
     * touches the stored records (touchesData()): such a change, or a removed field, needs `confirm_migration` and
     * runs as Storage's migration; any other is an update.
     *
     * @param array<string, mixed> $payload @return array<string, string>
     */
    private function updateStructure(string $actor, string $scopeRef, array $payload): array
    {
        $structureId = $this->identity($payload['structure_id'] ?? null, '/\Aworkbench\.[a-z][a-z0-9_.:-]{0,109}\z/D');
        $current = $this->workbench->readStructure($scopeRef, $structureId, $actor);
        $descriptor = $this->structureDescriptor($payload, $scopeRef, $actor, $current);
        $expected = $this->integer($payload['expected_version'] ?? null, 1, PHP_INT_MAX);
        $next = [];
        foreach ($descriptor['fields'] as $field) {
            $next[$field['key']] = $field;
        }
        $structural = false;
        foreach ($current->fields as $field) {
            $after = $next[(string) ($field['key'] ?? '')] ?? null;
            $structural = $structural || $after === null || self::touchesData($field, $after);
        }
        if ($structural) {
            if (!$this->boolean($payload['confirm_migration'] ?? false)) {
                throw new InvalidArgumentException(self::MIGRATION_UNCONFIRMED);
            }
            $structure = $this->workbench->migrateStructure($scopeRef, $structureId, $expected, $descriptor, $actor);
        } else {
            $structure = $this->workbench->updateStructure($scopeRef, $structureId, $expected, $descriptor, $actor);
        }

        return ['scope_ref' => $scopeRef, 'structure_id' => $structure->structureId] + $this->background($structure);
    }

    /** @param array<string, mixed> $payload @return array<string, string> */
    private function createRecord(string $actor, string $scopeRef, array $payload): array
    {
        $structureId = $this->structureId($payload);
        $structure = $this->workbench->readStructure($scopeRef, $structureId, $actor);
        $values = $this->recordValues($payload['values'] ?? null, $structure, $scopeRef);
        $record = $this->workbench->createRecord($scopeRef, $structureId, $values, $actor);

        return ['scope_ref' => $scopeRef, 'structure_id' => $structureId, 'record_id' => $record->recordId];
    }

    /** @param array<string, mixed> $payload @return array<string, string> */
    private function updateRecord(string $actor, string $scopeRef, array $payload): array
    {
        $structureId = $this->structureId($payload);
        $structure = $this->workbench->readStructure($scopeRef, $structureId, $actor);
        $recordId = $this->recordId($payload['record_id'] ?? null);
        $record = $this->workbench->updateRecord(
            $scopeRef,
            $structureId,
            $recordId,
            $this->integer($payload['expected_revision'] ?? null, 1, PHP_INT_MAX),
            $this->recordValues($this->keptEncrypted($payload['values'] ?? null, $structure, $scopeRef, $recordId, $actor), $structure, $scopeRef),
            $actor,
        );

        return ['scope_ref' => $scopeRef, 'structure_id' => $structureId, 'record_id' => $record->recordId];
    }

    /**
     * An encrypted field the write leaves out, or sends empty, keeps the value it has: the section never holds its
     * plaintext, so it sends the stored envelope back, which Storage takes as the unchanged value. An encrypted value
     * is replaced by typing a new one and is never cleared here, as a password is.
     */
    private function keptEncrypted(mixed $values, StorageWorkbenchStructure $structure, string $scopeRef, string $recordId, string $actor): mixed
    {
        $encrypted = StorageEncryptedFields::keys($structure->fields);
        if ($encrypted === [] || !is_array($values) || array_is_list($values)) {
            return $values;
        }
        $kept = array_filter($encrypted, static fn (string $key): bool => !array_key_exists($key, $values)
            || $values[$key] === null || $values[$key] === '' || StorageEncryptedFields::isMasked($values[$key]));
        if ($kept === []) {
            return $values;
        }
        $stored = $this->workbench->readRecord($scopeRef, $structure->structureId, $recordId, $actor)->values;
        foreach ($kept as $key) {
            unset($values[$key]);
            if (StorageEncryptedFields::isEnvelope($stored[$key] ?? null)) {
                $values[$key] = $stored[$key];
            }
        }

        return $values;
    }

    /**
     * An earlier version's values become the record's again, as a new revision over the current one:
     * nothing is lost, the version it replaced stays in the history.
     *
     * @param array<string, mixed> $payload @return array<string, string>
     */
    private function revertRecord(string $actor, string $scopeRef, array $payload): array
    {
        $structureId = $this->structureId($payload);
        $recordId = $this->recordId($payload['record_id'] ?? null);
        $from = $this->integer($payload['from_revision'] ?? null, 1, PHP_INT_MAX);
        $earlier = null;
        foreach ($this->workbench->recordHistory($scopeRef, $structureId, $recordId, $actor, 100) as $version) {
            if ($version->revision === $from) {
                $earlier = $version;
            }
        }
        if ($earlier === null) {
            throw new InvalidArgumentException('storage_workbench_revision_unknown');
        }
        $record = $this->workbench->updateRecord(
            $scopeRef,
            $structureId,
            $recordId,
            $this->integer($payload['expected_revision'] ?? null, 1, PHP_INT_MAX),
            $earlier->values,
            $actor,
        );

        return ['scope_ref' => $scopeRef, 'structure_id' => $structureId, 'record_id' => $record->recordId];
    }

    /** @param array<string, mixed> $payload @return array<string, string> */
    private function archiveRecord(string $actor, string $scopeRef, array $payload): array
    {
        $this->assertConfirmed($payload);
        $structureId = $this->structureId($payload);
        $record = $this->workbench->archiveRecord(
            $scopeRef,
            $structureId,
            $this->recordId($payload['record_id'] ?? null),
            $this->integer($payload['expected_revision'] ?? null, 1, PHP_INT_MAX),
            $actor,
        );

        return ['scope_ref' => $scopeRef, 'structure_id' => $structureId, 'record_id' => $record->recordId];
    }

    /** @param array<string, mixed> $payload @return array<string, string> */
    private function bulkArchive(string $actor, string $scopeRef, array $payload): array
    {
        $this->assertConfirmed($payload);
        $normalized = $this->expectedRevisions($payload);
        $structureId = $this->structureId($payload);
        $this->workbench->bulkArchive($scopeRef, $structureId, $normalized, $actor);

        return ['scope_ref' => $scopeRef, 'structure_id' => $structureId];
    }

    /**
     * Archive every active record the filters and the search find, when there are exactly as many as
     * the person confirmed: the set is read again here, and a count that changed meanwhile refuses
     * the action as a conflict instead of archiving what nobody saw.
     *
     * @param array<string, mixed> $payload @return array<string, string>
     */
    private function bulkArchiveMatching(string $actor, string $scopeRef, array $payload): array
    {
        $this->assertConfirmed($payload);
        $expected = $this->integer($payload['expected_count'] ?? null, 1, self::MAX_BULK_MATCHING);
        $structureId = $this->structureId($payload);
        $filters = $payload['filters'] ?? [];
        $search = $payload['search'] ?? null;
        if (!is_array($filters) || ($filters !== [] && array_is_list($filters))
            || ($search !== null && (!is_string($search) || mb_strlen($search) > 100))) {
            throw new InvalidArgumentException('storage_workbench_filter_invalid');
        }
        $search = is_string($search) && trim($search) !== '' ? trim($search) : null;
        $revisions = [];
        for ($page = 1; $page <= intdiv(self::MAX_BULK_MATCHING, 100); $page++) {
            $result = $this->workbench->listRecords(new StorageWorkbenchRecordQuery(
                $scopeRef, $structureId, filters: $filters, search: $search,
                sort: [['field' => '_record_id', 'direction' => 'asc']], limit: 100, page: $page,
                // The person confirmed a number: it is compared with an exact count.
                countMode: StorageWorkbenchCountMode::Exact,
            ), $actor);
            if ($result->matchedCount !== $expected) {
                throw new StorageConflict('storage_workbench_bulk_count_changed');
            }
            foreach ($result->items as $record) {
                $revisions[$record->recordId] = $record->revision;
            }
            if (count($revisions) >= $expected || $result->items === []) {
                break;
            }
        }
        if (count($revisions) !== $expected) {
            throw new StorageConflict('storage_workbench_bulk_count_changed');
        }
        ksort($revisions, SORT_STRING);
        $this->workbench->bulkArchive($scopeRef, $structureId, $revisions, $actor);

        return ['scope_ref' => $scopeRef, 'structure_id' => $structureId];
    }

    /** @param array<string, mixed> $payload @return array<string, string> */
    private function restoreRecord(string $actor, string $scopeRef, array $payload): array
    {
        $structureId = $this->structureId($payload);
        $record = $this->workbench->restoreRecord(
            $scopeRef,
            $structureId,
            $this->recordId($payload['record_id'] ?? null),
            $this->integer($payload['expected_revision'] ?? null, 1, PHP_INT_MAX),
            $actor,
        );

        return ['scope_ref' => $scopeRef, 'structure_id' => $structureId, 'record_id' => $record->recordId];
    }

    /** @param array<string, mixed> $payload @return array<string, string> */
    private function purgeRecords(string $actor, string $scopeRef, array $payload): array
    {
        $this->assertConfirmed($payload);
        $normalized = $this->expectedRevisions($payload);
        $structureId = $this->structureId($payload);
        $this->workbench->purgeRecords($scopeRef, $structureId, $normalized, $actor);

        return ['scope_ref' => $scopeRef, 'structure_id' => $structureId];
    }

    /** @param array<string, mixed> $payload @return array<string, int> */
    private function expectedRevisions(array $payload): array
    {
        $revisions = $payload['expected_revisions'] ?? null;
        if (!is_array($revisions) || array_is_list($revisions) || count($revisions) > 100) {
            throw new InvalidArgumentException('storage_workbench_bulk_invalid');
        }
        $normalized = [];
        foreach ($revisions as $recordId => $revision) {
            $normalized[$this->recordId($recordId)] = $this->integer($revision, 1, PHP_INT_MAX);
        }
        ksort($normalized, SORT_STRING);

        return $normalized;
    }

    /**
     * How many saved versions of each record the storage keeps: a number from 1 to 1000, or empty for all.
     *
     * @param array<string, mixed> $payload @return array<string, string>
     */
    private function historyKeep(string $actor, string $scopeRef, array $payload): array
    {
        $structureId = $this->structureId($payload);
        $keep = $payload['keep'] ?? null;
        $keep = $keep === null || $keep === '' ? null : $this->integer($keep, 1, \Larena\Storage\Contracts\VersionedStorage::HISTORY_KEEP_LIMIT);
        $this->workbench->setHistoryKeep($scopeRef, $structureId, $keep, $actor);

        return ['scope_ref' => $scopeRef, 'structure_id' => $structureId];
    }

    /**
     * A change of the storage's fields from the field editor and the settings list: each existing field's label,
     * order, hidden flag, search, index and translation options, its type, «required» and the settings of its type;
     * new fields are appended. Whether the change touches the stored records is one rule for every path
     * (touchesData(): a removal, a retype, «required» switched on or off, encryption switched, or a setting Property
     * says acts on data or is not free after creation, PropertyTypeSettings::dataChangingKeys()). Such a change needs
     * `confirm_migration` and runs as Storage's migration (in the background for a large storage); one that touches no
     * data is an update at once.
     *
     * @param array<string, mixed> $payload @return array<string, mixed>
     */
    private function configureStructure(string $actor, string $scopeRef, array $payload): array
    {
        [$structureId, $descriptor, $expectedVersion, $structural] = $this->plannedConfigure($actor, $scopeRef, $payload);
        if ($structural) {
            if (!$this->boolean($payload['confirm_migration'] ?? false)) {
                throw new InvalidArgumentException(self::MIGRATION_UNCONFIRMED);
            }
            $structure = $this->workbench->migrateStructure($scopeRef, $structureId, $expectedVersion, $descriptor, $actor);
        } else {
            $structure = $this->workbench->updateStructure($scopeRef, $structureId, $expectedVersion, $descriptor, $actor);
        }

        // A change above Storage's inline limit comes back unchanged with its running change attached; a switch of
        // encryption on a field with values comes back switched with its encryption run.
        return ['scope_ref' => $scopeRef, 'structure_id' => $structure->structureId] + $this->background($structure);
    }

    /**
     * What a change of the fields would do to the stored records, before it is confirmed: Storage's check of the
     * same descriptor (StorageWorkbenchFieldChangeCheck), nothing written. A change that touches no data answers so
     * without asking Storage.
     *
     * @param array<string, mixed> $payload @return array<string, mixed>
     */
    private function checkConfigure(string $actor, string $scopeRef, array $payload): array
    {
        [$structureId, $descriptor, , $structural] = $this->plannedConfigure($actor, $scopeRef, $payload);
        if (!$structural) {
            return ['scope_ref' => $scopeRef, 'structure_id' => $structureId,
                'check' => ['data_changing' => false, 'fields' => [], 'total' => 0, 'checked' => 0, 'invalid' => 0, 'complete' => true, 'samples' => []]];
        }
        if (!$this->workbench instanceof StorageWorkbenchFieldChangeCheck) {
            throw new StorageRejected('storage_workbench_field_change_check_unavailable');
        }
        unset($descriptor[self::ENCRYPTION_DISABLE_CONFIRMED]);

        return ['scope_ref' => $scopeRef, 'structure_id' => $structureId,
            'check' => $this->workbench->checkFieldChange($scopeRef, $structureId, $descriptor, $actor)];
    }

    /**
     * The descriptor a change of the fields gives, and whether it touches the stored records.
     *
     * @param array<string, mixed> $payload
     * @return array{0: string, 1: array<string, mixed>, 2: int, 3: bool}
     */
    private function plannedConfigure(string $actor, string $scopeRef, array $payload): array
    {
        $structureId = $this->structureId($payload);
        $current = $this->workbench->readStructure($scopeRef, $structureId, $actor);
        $input = $payload['fields'] ?? null;
        if (!is_array($input) || count($input) > 101) {
            throw new InvalidArgumentException('storage_workbench_fields_invalid');
        }
        $submitted = [];
        foreach ($input as $candidate) {
            if (!is_array($candidate) || array_is_list($candidate)) {
                throw new InvalidArgumentException('storage_workbench_field_invalid');
            }
            $key = is_string($candidate['key'] ?? null) ? trim($candidate['key']) : '';
            if ($key === '' && trim((string) ($candidate['label'] ?? '')) === '') {
                continue;
            }
            $this->assertSafeKey($key);
            $submitted[$key] = $candidate;
        }
        $fields = [];
        $structural = false;
        foreach ($current->fields as $field) {
            $key = (string) $field['key'];
            $candidate = $submitted[$key] ?? null;
            unset($submitted[$key]);
            if (!is_array($candidate)) {
                $fields[] = $field;
                continue;
            }
            // Removing a field takes its values from every record.
            if ($this->boolean($candidate['remove'] ?? false)) {
                $structural = true;
                continue;
            }
            $before = $field;
            $field['label'] = $this->text($candidate['label'] ?? null, 1, 120);
            $field['position'] = $this->integer($candidate['position'] ?? $field['position'], 0, 10_000);
            unset($field['hidden']);
            if ($this->boolean($candidate['hidden'] ?? false)) {
                $field['hidden'] = true;
            }
            $type = $candidate['type'] ?? $field['type'];
            if (!is_string($type) || !self::fieldType($type)) {
                throw new InvalidArgumentException('storage_workbench_field_type_invalid');
            }
            if ($type !== $field['type']) {
                // A retyped field starts without its rules, so without the options they allowed.
                unset($field['indexed']);
                if (!self::searchableType($type)) {
                    unset($field['searchable']);
                }
                $field['type'] = $type;
                // A retyped field is a new definition: it takes the type's current version.
                $field['type_version'] = self::currentVersion($type);
                $field['constraints'] = $this->fieldConstraints($scopeRef, $actor, $type, $candidate);
            } else {
                $field['constraints'] = $this->changedConstraints($field, $candidate);
            }
            $field = $this->viewOptions($field, $candidate);
            $field = $this->localized($field, $candidate);
            $field = $this->encryption($field, $candidate);
            if (array_key_exists('required', $candidate)) {
                $field['required'] = $this->boolean($candidate['required']);
            }
            $structural = $structural || self::touchesData($before, $field);
            $fields[] = $field;
        }
        foreach ($submitted as $key => $candidate) {
            if ($this->boolean($candidate['remove'] ?? false)) {
                continue;
            }
            $type = $candidate['type'] ?? null;
            if (!is_string($type) || !self::fieldType($type)) {
                throw new InvalidArgumentException('storage_workbench_field_type_invalid');
            }
            $fields[] = $this->encryption($this->localized($this->viewOptions([
                'key' => $key,
                'label' => $this->text($candidate['label'] ?? null, 1, 120),
                'position' => $this->integer($candidate['position'] ?? null, 0, 10_000),
                'type' => $type,
                // A new field takes the type's current version (string@2 counts characters, not bytes).
                'type_version' => self::currentVersion($type),
                'required' => false,
                'visibility' => 'public',
                'constraints' => $this->fieldConstraints($scopeRef, $actor, $type, $candidate),
            ], $candidate), $candidate), $candidate);
        }
        if ($fields === []) {
            throw new InvalidArgumentException('storage_workbench_fields_invalid');
        }
        $descriptor = [
            'structure_id' => $structureId,
            'label' => $this->text($payload['label'] ?? null, 1, 120),
            'fields' => $fields,
        ];
        // Switching encryption off a field with values is confirmed by the person; Storage takes the flag out.
        if ($this->boolean($payload[self::ENCRYPTION_DISABLE_CONFIRMED] ?? false)) {
            $descriptor[self::ENCRYPTION_DISABLE_CONFIRMED] = true;
        }

        return [$structureId, $descriptor, $this->integer($payload['expected_version'] ?? null, 1, PHP_INT_MAX), $structural];
    }

    /**
     * Whether a field's change touches the stored records: a retype, «required» switched, encryption switched, or a
     * setting of its type that acts on data or is not free after creation (Property: dataChangingKeys()). The one
     * rule the field editor, the settings list and the JSON path follow; Storage follows the same.
     *
     * @param array<array-key, mixed> $before @param array<array-key, mixed> $after
     */
    public static function touchesData(array $before, array $after): bool
    {
        $type = (string) ($before['type'] ?? '');
        if ($type !== ($after['type'] ?? null) || ($before['type_version'] ?? 1) !== ($after['type_version'] ?? 1)
            || (($before['required'] ?? false) === true) !== (($after['required'] ?? false) === true)
            || StorageEncryptedFields::isEncrypted($before) !== StorageEncryptedFields::isEncrypted($after)) {
            return true;
        }
        $declared = PropertyTypeSettingsCatalog::builtIns()->for($type, is_int($before['type_version'] ?? null) ? $before['type_version'] : 1);
        $from = is_array($before['constraints'] ?? null) ? $before['constraints'] : [];
        $to = is_array($after['constraints'] ?? null) ? $after['constraints'] : [];

        return $declared === null ? $from !== $to : $declared->dataChangingKeys($from, $to) !== [];
    }

    /**
     * An existing field's settings after a change that keeps its type: the stored ones, with each setting the change
     * names set (null takes it off) and the options as typed (an option keeps the icon it had when the line names
     * none). A setting locked after creation never changes; the result is checked by Property.
     *
     * @param array<string, mixed> $field @param array<mixed> $candidate
     * @return array<string, mixed>
     */
    private function changedConstraints(array $field, array $candidate): array
    {
        $type = (string) $field['type'];
        $declared = PropertyTypeSettingsCatalog::builtIns()->for($type, is_int($field['type_version'] ?? null) ? $field['type_version'] : 1)
            ?? PropertyTypeSettingsCatalog::builtIns()->latest($type);
        $stored = is_array($field['constraints'] ?? null) ? $field['constraints'] : [];
        $constraints = $stored;
        foreach ($this->candidateSettings($type, $candidate) as $key => $value) {
            $setting = $declared?->setting($key);
            if ($setting === null) {
                throw new InvalidArgumentException('storage_workbench_field_settings_invalid');
            }
            if ($setting->afterCreation === \Larena\Property\Contracts\PropertySettingDescriptor::AFTER_CREATION_LOCKED
                && ($stored[$key] ?? null) !== $value) {
                throw new InvalidArgumentException('storage_field_setting_locked');
            }
            if ($value === null) {
                unset($constraints[$key]);
            } else {
                $constraints[$key] = $value;
            }
        }
        if ($declared?->setting('options') !== null && is_string($candidate['options_text'] ?? null)) {
            $icons = [];
            foreach (is_array($stored['options'] ?? null) ? $stored['options'] : [] as $existing) {
                if (is_array($existing) && is_string($existing['value'] ?? null) && is_string($existing['icon'] ?? null)) {
                    $icons[$existing['value']] = $existing['icon'];
                }
            }
            $options = array_map(static fn (array $option): array => !isset($option['icon']) && isset($icons[$option['value']])
                ? $option + ['icon' => $icons[$option['value']]] : $option,
                $this->optionsFromText($candidate['options_text']));
            $normalize = static fn (array $list): array => array_map(static function (mixed $option): mixed {
                if (is_array($option)) {
                    ksort($option);
                }

                return $option;
            }, array_values($list));
            if ($normalize($options) !== $normalize(is_array($stored['options'] ?? null) ? $stored['options'] : [])) {
                $constraints['options'] = $options;
            }
        }
        $this->assertSettings($type, is_int($field['type_version'] ?? null) ? $field['type_version'] : 1, $constraints);

        return $constraints;
    }

    /**
     * The settings a change names: its `settings` (key => value, null to take one off), and the editor's older
     * spellings «max_length» and «target_structure_id» beside them.
     *
     * @param array<mixed> $candidate
     * @return array<string, mixed>
     */
    private function candidateSettings(string $type, array $candidate): array
    {
        $settings = [];
        foreach (is_array($candidate['settings'] ?? null) ? $candidate['settings'] : [] as $key => $value) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('storage_workbench_field_settings_invalid');
            }
            $settings[$key] = $value;
        }
        $declared = PropertyTypeSettingsCatalog::builtIns()->latest($type);
        if ($declared?->setting('max_length') !== null && array_key_exists('max_length', $candidate) && !array_key_exists('max_length', $settings)) {
            $settings['max_length'] = $candidate['max_length'];
        }
        if ($declared?->setting('target_structure_id') !== null && array_key_exists('target_structure_id', $candidate) && !array_key_exists('target_structure_id', $settings)) {
            $settings['target_structure_id'] = $candidate['target_structure_id'];
        }
        // «Максимальная длина» of a string or text: a whole number from 1 to 65535 (Storage keeps at most 64 KiB), or none.
        if (array_key_exists('max_length', $settings)) {
            $settings['max_length'] = $this->maxLength($settings['max_length']);
        }
        if (($settings['min_length'] ?? null) === '') {
            $settings['min_length'] = null;
        }

        return $settings;
    }

    /**
     * A field's settings checked by Property (its kinds, bounds and pairs, a lookup's source); a refusal of the options
     * keeps its own words.
     *
     * @param array<string, mixed> $constraints
     */
    private function assertSettings(string $type, int $version, array $constraints): void
    {
        $result = \Larena\Admin\Runtime\PropertyViewPort::types()->validateConstraints($type, $version, $constraints);
        if ($result->canBePersistedByOwner() || $result->blocked) {
            return;
        }
        throw new InvalidArgumentException(match ($result->errors[0] ?? '') {
            'property_choice_options_required' => 'storage_workbench_field_options_required',
            'property_choice_options_invalid' => 'storage_workbench_field_options_invalid',
            default => 'storage_workbench_field_settings_invalid',
        });
    }

    /**
     * «Переводится на другие языки»: a candidate that names it sets it, one that does not leaves it as stored. Storage
     * keeps it on the field; whether a field is localized changes no stored value.
     *
     * @param array<string, mixed> $field @param array<mixed> $candidate
     * @return array<string, mixed>
     */
    private function localized(array $field, array $candidate): array
    {
        if (array_key_exists('localized', $candidate)) {
            unset($field['localized']);
            if ($this->boolean($candidate['localized'])) {
                $field['localized'] = true;
            }
        }

        return $field;
    }

    /** @return array<string, string> what goes on in the background after a change: a field change or an encryption run */
    private function background(StorageWorkbenchStructure $structure): array
    {
        if ($structure->encryptionRun !== null && $structure->encryptionRun->running()) {
            return ['encryption' => 'running'];
        }

        return $structure->changing() ? ['field_change' => 'running'] : [];
    }

    /**
     * «Сменить ключ шифрования», once the person confirmed it: a new data key for the storage, and a run that seals
     * again what the old key sealed. Storage checks storage.encryption.manage and the structure's update right.
     *
     * @param array<string, mixed> $payload @return array<string, string>
     */
    private function rotateEncryptionKey(string $actor, string $scopeRef, array $payload): array
    {
        $this->assertConfirmed($payload);
        $structureId = $this->structureId($payload);
        $structure = $this->encryptionRuns()->rotateEncryptionKey($scopeRef, $structureId, $actor);

        return ['scope_ref' => $scopeRef, 'structure_id' => $structureId] + $this->background($structure);
    }

    /**
     * «Повторить» of a stopped encryption run: it resumes where it stopped. Storage refuses a run that has not failed.
     *
     * @param array<string, mixed> $payload @return array<string, string>
     */
    private function retryEncryptionRun(string $actor, string $scopeRef, array $payload): array
    {
        $structureId = $this->structureId($payload);
        $structure = $this->encryptionRuns()->retryEncryptionRun($scopeRef, $structureId, $actor);

        return ['scope_ref' => $scopeRef, 'structure_id' => $structureId] + $this->background($structure);
    }

    /** Storage's encryption runs; without them a key change or a retry is unavailable. */
    private function encryptionRuns(): StorageWorkbenchEncryption
    {
        $runs = $this->encryption ?? ($this->workbench instanceof StorageWorkbenchEncryption ? $this->workbench : null);
        if ($runs === null) {
            throw new StorageRejected(StorageEncryptionPolicy::UNAVAILABLE);
        }

        return $runs;
    }

    /** Whether a field of the section may have this type: every type Property declares. */
    private static function fieldType(string $type): bool
    {
        return PropertyTypeSettingsCatalog::builtIns()->latest($type) !== null;
    }

    /**
     * Whether the section checks a value of this type with Property before Storage does: every type but a reference
     * to something outside Storage (Property: reference). A Storage record reference is checked here, its id being
     * Property's syntax and the record Storage's own.
     */
    private static function prechecked(string $type): bool
    {
        $declared = PropertyTypeSettingsCatalog::builtIns()->latest($type);

        return $declared !== null && (!$declared->reference || $declared->valueKind === PropertyValueKind::RecordReference);
    }

    /** Whether a type is searched (Property: searchable). */
    private static function searchableType(mixed $type): bool
    {
        return is_string($type) && PropertyTypeSettingsCatalog::builtIns()->can($type, 'searchable');
    }

    /** The version a new field of a type takes: the latest Property declares. */
    private static function currentVersion(string $type): int
    {
        return PropertyTypeSettingsCatalog::builtIns()->latest($type)->version ?? 1;
    }

    /**
     * A field's search and index options, as hidden is kept: a candidate that names one sets it, one
     * that does not leaves it as stored. Only a departure from the default is written (a field is
     * searched and not indexed unless it says otherwise), so changing them never rewrites records;
     * Storage refuses an option the field's type or length cannot take.
     *
     * @param array<string, mixed> $field @param array<mixed> $candidate
     * @return array<string, mixed>
     */
    private function viewOptions(array $field, array $candidate): array
    {
        if (array_key_exists('searchable', $candidate)) {
            unset($field['searchable']);
            if (!$this->boolean($candidate['searchable'])) {
                $field['searchable'] = false;
            }
        }
        if (array_key_exists('indexed', $candidate)) {
            unset($field['indexed']);
            if ($this->boolean($candidate['indexed'])) {
                $field['indexed'] = true;
            }
        }

        return $field;
    }

    /**
     * «Шифровать»: a candidate that names it sets it, one that does not leaves it as stored. An encrypted field is
     * seen in the admin only (visibility admin), is never searched nor indexed, and its value is never a key;
     * switched off it is public again, as every field of the section is. Storage decides whether the field may
     * take it (its type, its values, the person's right to manage encryption).
     *
     * @param array<string, mixed> $field @param array<mixed> $candidate
     * @return array<string, mixed>
     */
    private function encryption(array $field, array $candidate): array
    {
        if (array_key_exists('encrypted', $candidate)) {
            $encrypt = $this->boolean($candidate['encrypted']);
            if ($encrypt !== StorageEncryptedFields::isEncrypted($field)) {
                unset($field['encrypted']);
                $field['visibility'] = $encrypt ? StorageEncryptionPolicy::VISIBILITY : 'public';
                if ($encrypt) {
                    $field['encrypted'] = true;
                }
            }
        }
        if (StorageEncryptedFields::isEncrypted($field)) {
            unset($field['indexed'], $field['searchable']);
            // A string or text is searched unless it says otherwise: an encrypted one says so.
            if (self::searchableType($field['type'] ?? null)) {
                $field['searchable'] = false;
            }
        }

        return $field;
    }

    /** @param array<string, mixed> $payload @return array<string, string> */
    private function changeStructureState(string $actor, string $scopeRef, array $payload, bool $archive): array
    {
        if ($archive) {
            $this->assertConfirmed($payload);
        }
        $structureId = $this->structureId($payload);
        $version = $this->integer($payload['expected_version'] ?? null, 1, PHP_INT_MAX);
        $archive
            ? $this->workbench->archiveStructure($scopeRef, $structureId, $version, $actor)
            : $this->workbench->restoreStructure($scopeRef, $structureId, $version, $actor);

        return ['scope_ref' => $scopeRef, 'structure_id' => $structureId];
    }

    /**
     * Stops the storage's field change that runs in the background, once the person confirmed it.
     * Storage checks the right to migrate the structure and refuses when the change has ended.
     *
     * @param array<string, mixed> $payload @return array<string, string>
     */
    private function cancelFieldChange(string $actor, string $scopeRef, array $payload): array
    {
        $this->assertConfirmed($payload);
        $structureId = $this->structureId($payload);
        $this->workbench->cancelFieldChange($scopeRef, $structureId, $actor);

        return ['scope_ref' => $scopeRef, 'structure_id' => $structureId, 'field_change' => 'cancelled'];
    }

    /** @param array<string, mixed> $payload @return array<string, string> */
    private function purgeStructure(string $actor, string $scopeRef, array $payload): array
    {
        $structureId = $this->structureId($payload);
        // Typing the id back is the confirmation: a stray click cannot delete.
        if (($payload['confirm_structure_id'] ?? null) !== $structureId) {
            throw new InvalidArgumentException('storage_workbench_confirmation_required');
        }
        $this->workbench->purgeStructure(
            $scopeRef,
            $structureId,
            $this->integer($payload['expected_version'] ?? null, 1, PHP_INT_MAX),
            $actor,
        );

        return ['scope_ref' => $scopeRef];
    }

    /** @param array<string, mixed> $payload */
    private function assertConfirmed(array $payload): void
    {
        if (($payload['confirmed'] ?? null) !== '1' && ($payload['confirmed'] ?? null) !== 1 && ($payload['confirmed'] ?? null) !== true) {
            throw new InvalidArgumentException('storage_workbench_confirmation_required');
        }
    }

    /** @param array<string, mixed> $payload @return array{structure_id: string, label: string, fields: list<array<string, mixed>>} */
    private function structureDescriptor(array $payload, string $scopeRef, string $actor, ?StorageWorkbenchStructure $current = null): array
    {
        $structureId = $this->structureId($payload);
        $existing = [];
        foreach ($current === null ? [] : $current->fields as $field) {
            if (is_string($field['key'] ?? null)) {
                $existing[$field['key']] = $field;
            }
        }
        $label = $this->text($payload['label'] ?? null, 1, 120);
        $input = $payload['fields'] ?? null;
        if (!is_array($input) || count($input) > 100) {
            throw new InvalidArgumentException('storage_workbench_fields_invalid');
        }
        $fields = [];
        foreach ($input as $candidate) {
            if (!is_array($candidate) || array_is_list($candidate)) {
                throw new InvalidArgumentException('storage_workbench_field_invalid');
            }
            $key = is_string($candidate['key'] ?? null) ? trim($candidate['key']) : '';
            $fieldLabel = is_string($candidate['label'] ?? null) ? trim($candidate['label']) : '';
            if ($key === '' && $fieldLabel === '') {
                continue;
            }
            $this->assertSafeKey($key);
            $type = $candidate['type'] ?? null;
            if (!is_string($type) || !self::fieldType($type)) {
                throw new InvalidArgumentException('storage_workbench_field_type_invalid');
            }
            $constraints = in_array($type, ['record', 'choice', 'choices'], true)
                ? $this->fieldConstraints($scopeRef, $actor, $type, $candidate) : ($candidate['constraints'] ?? []);
            if (!is_array($constraints) || ($constraints !== [] && array_is_list($constraints))) {
                throw new InvalidArgumentException('storage_workbench_field_constraints_invalid');
            }
            $fields[] = [
                'key' => $key,
                'label' => $this->text($fieldLabel, 1, 120),
                'position' => $this->integer($candidate['position'] ?? null, 0, 10_000),
                'type' => $type,
                // A field the structure has keeps its version unless one is sent; a new one takes the type's latest.
                'type_version' => $this->integer($candidate['type_version'] ?? (($existing[$key]['type'] ?? null) === $type
                    ? ($existing[$key]['type_version'] ?? self::currentVersion($type)) : self::currentVersion($type)), 1, 10),
                'required' => $this->boolean($candidate['required'] ?? false),
                'visibility' => 'public',
                'constraints' => $constraints,
            ];
        }
        if ($fields === []) {
            throw new InvalidArgumentException('storage_workbench_fields_invalid');
        }

        return ['structure_id' => $structureId, 'label' => $label, 'fields' => $fields];
    }

    /** @return array<string, mixed> */
    private function recordValues(mixed $values, StorageWorkbenchStructure $structure, string $scopeRef): array
    {
        if (!is_array($values) || array_is_list($values) || count($values) > 100) {
            throw new InvalidArgumentException('storage_workbench_values_invalid');
        }
        $fields = [];
        foreach ($structure->fields as $field) {
            $fields[(string) $field['key']] = $field;
        }
        foreach (array_keys($values) as $key) {
            if (!is_string($key) || !isset($fields[$key])) {
                throw new InvalidArgumentException('storage_workbench_unknown_field');
            }
            $this->assertSafeKey($key);
        }
        $normalized = [];
        foreach ($fields as $key => $field) {
            if (!array_key_exists($key, $values) || $values[$key] === '') {
                if (($field['required'] ?? false) === true) {
                    throw new InvalidArgumentException(self::FIELD_VALUE_INVALID.'|'.$key.'|storage_workbench_required_field_missing');
                }
                continue;
            }
            $value = $values[$key];
            if (StorageEncryptedFields::isEncrypted($field) && StorageEncryptedFields::isEnvelope($value)) {
                // The stored envelope, sent back unchanged: Storage checks that it belongs to this record and field.
                $normalized[$key] = $value;
                continue;
            }
            try {
                $normalized[$key] = $this->fieldValue($field, $value, $scopeRef);
            } catch (InvalidArgumentException $exception) {
                // The field and why travel with the refusal, so the panel says it under that field.
                throw new InvalidArgumentException(self::FIELD_VALUE_INVALID.'|'.$key.'|'.$exception->getMessage(), 0, $exception);
            }
        }
        $encoded = json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (strlen($encoded) > self::MAX_JSON_BYTES) {
            throw new InvalidArgumentException('storage_workbench_values_too_large');
        }
        ksort($normalized, SORT_STRING);

        return $normalized;
    }

    /**
     * One field's value as Storage takes it, checked by the field's Property type first so a value the type refuses
     * is named with its field and Property's reason. A number is typed with a comma or a point (Russian or English
     * habit) and kept as Property's canonical decimal.
     *
     * @param array<string, mixed> $field
     */
    private function fieldValue(array $field, mixed $value, string $scopeRef): mixed
    {
        $normalized = match ($field['type']) {
            'integer' => $this->integer($value, PHP_INT_MIN, PHP_INT_MAX),
            'number' => $this->decimal($value),
            'boolean' => $this->boolean($value),
            // Several choices come from the form as a list; Storage checks them against the options.
            'choices' => $this->choices($value),
            // The form's date-time control leaves out the seconds Storage keeps.
            'datetime' => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value) === 1
                ? $value.':00' : $this->safeScalar($value),
            // A link is {href, text?}; a bare address is its href. Property checks the rest.
            'link' => is_array($value) ? $this->linkValue($value) : $this->safeScalar($value),
            // Rich text carries markup by design: Property checks it, the site makes it safe when it shows it.
            'html' => is_string($value) ? $value : throw new InvalidArgumentException('storage_workbench_value_invalid'),
            default => $this->safeScalar($value),
        };
        if ($field['type'] === 'file') {
            $logicalRef = $this->uuid($normalized);
            if (!$this->scopes->fileBelongsToScope($logicalRef, $scopeRef)
                || !$this->fileInspector->inspect($logicalRef)->isContentAttachable()) {
                throw new InvalidArgumentException('storage_workbench_file_reference_ineligible');
            }

            return $logicalRef;
        }
        // Property's own check of a plain value, as Storage will make it: a refusal here names the field. A reference
        // to something outside Storage (a user, a content item) is left to Storage, which asks its owner.
        if (!self::prechecked((string) $field['type'])) {
            return $normalized;
        }
        $checked = \Larena\Admin\Runtime\PropertyViewPort::types()->normalizeAndValidate((string) $field['type'],
            is_int($field['type_version'] ?? null) ? $field['type_version'] : 1, $normalized,
            is_array($field['constraints'] ?? null) ? $field['constraints'] : []);
        if (!$checked->blocked && !$checked->valid && is_string($checked->errors[0] ?? null)) {
            throw new InvalidArgumentException($checked->errors[0]);
        }

        return $normalized;
    }

    /**
     * A decimal as Property keeps it: «100,5», «100.5», «1 000,50» and 100.5 all become «100.5».
     */
    private function decimal(mixed $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new InvalidArgumentException('storage_workbench_number_invalid');
            }
            $text = json_encode($value, JSON_THROW_ON_ERROR);
            // A very large or very small float is written out without its exponent.
            $value = stripos($text, 'e') === false ? $text : rtrim(rtrim(number_format($value, 20, '.', ''), '0'), '.');
        }
        if (!is_string($value) || strlen($value) > 1000) {
            throw new InvalidArgumentException('storage_workbench_number_invalid');
        }
        $text = (string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', $value);
        // One comma and no point is the decimal comma of the Russian habit.
        if (substr_count($text, ',') === 1 && !str_contains($text, '.')) {
            $text = str_replace(',', '.', $text);
        }
        [$decimal, $reason] = StorageCsvImport::decimal($text);
        if (!is_string($decimal) || $reason !== null) {
            throw new InvalidArgumentException('storage_workbench_number_invalid');
        }

        return $decimal;
    }

    /** @param array<string, mixed> $query @return array<array-key, mixed> */
    private function filters(array $query): array
    {
        if (array_key_exists('filters', $query)) {
            if (!is_array($query['filters']) || ($query['filters'] !== [] && array_is_list($query['filters']))) {
                throw new InvalidArgumentException('storage_workbench_filter_invalid');
            }
            return $query['filters'];
        }
        $field = $query['filter_field'] ?? null;
        $value = $query['filter_value'] ?? null;
        if ($field === null && $value === null) {
            return [];
        }
        if (!is_string($field) || $field === '' || !is_string($value)) {
            throw new InvalidArgumentException('storage_workbench_filter_invalid');
        }
        $this->assertSafeKey($field);

        return [$field => ['operator' => 'eq', 'value' => $value]];
    }

    /**
     * The list's sort as asked, or, when none is, its own order (newest first), with whether it is that default.
     *
     * @param array<string, mixed> $query
     * @return array{0: list<mixed>, 1: bool}
     */
    private function sort(array $query): array
    {
        if (array_key_exists('sort', $query)) {
            if (!is_array($query['sort']) || !array_is_list($query['sort'])) {
                throw new InvalidArgumentException('storage_workbench_sort_invalid');
            }
            return $query['sort'] === [] ? [StorageListOrder::NEWEST_FIRST, true] : [$query['sort'], false];
        }
        $field = $query['sort_field'] ?? null;
        $direction = $query['sort_direction'] ?? null;
        if ($field === null && $direction === null) {
            return [StorageListOrder::NEWEST_FIRST, true];
        }
        if (!is_string($field) || !is_string($direction) || !in_array($direction, ['asc', 'desc'], true)) {
            throw new InvalidArgumentException('storage_workbench_sort_invalid');
        }
        if (!StorageListOrder::isSystem($field)) {
            $this->assertSafeKey($field);
        }

        return [[['field' => $field, 'direction' => $direction]], false];
    }

    /** @return array<string, mixed> */
    private function structureProjection(StorageWorkbenchStructure $structure): array
    {
        foreach ($structure->fields as $field) {
            $this->assertSafeKey((string) ($field['key'] ?? ''));
        }

        return [
            'structure_id' => $structure->structureId,
            'label' => $structure->label,
            'version' => $structure->version,
            'schema_version' => $structure->schema->version,
            'descriptor_hash' => $structure->descriptorHash,
            'fields' => $structure->fields,
            'archived' => $structure->isArchived(),
            'archived_at' => $structure->archivedAt,
            'history_keep' => $structure->historyKeep,
            // The витрина and the field change running in the background (or the last one that failed or
            // was cancelled): the section shows them; while a change runs the structure takes no other change.
            'view_state' => $structure->viewState,
            'view_backfill_pending' => $structure->viewBackfillPending,
            'view_pending_fields' => array_values(array_filter($structure->viewPendingFields, 'is_string')),
            'field_change' => $structure->fieldChange === null ? null : [
                'change_ref' => $structure->fieldChange->changeRef,
                'state' => $structure->fieldChange->state,
                'checked' => max(0, $structure->fieldChange->checked),
                'total' => max(0, $structure->fieldChange->total),
                'fields' => array_values(array_filter($structure->fieldChange->fields, 'is_string')),
                'reason' => $structure->fieldChange->reason,
                'started_at' => $structure->fieldChange->startedAt,
                'finished_at' => $structure->fieldChange->finishedAt,
            ],
            // The encryption run that is queued, running or failed (a failed one waits for «Повторить»): the section
            // shows its progress or its reason; meanwhile the structure takes no other change.
            'encryption_run' => $structure->encryptionRun === null || !$structure->encryptionRun->open() ? null : [
                'run_ref' => $structure->encryptionRun->runRef,
                'mode' => $structure->encryptionRun->mode,
                'state' => $structure->encryptionRun->state,
                'fields' => array_values(array_filter($structure->encryptionRun->fields, 'is_string')),
                'rewritten' => max(0, $structure->encryptionRun->rewritten),
                'skipped' => max(0, $structure->encryptionRun->skipped),
                'total' => max(0, $structure->encryptionRun->total),
                'reason' => $structure->encryptionRun->reason,
                'started_at' => $structure->encryptionRun->startedAt,
                'finished_at' => $structure->encryptionRun->finishedAt,
            ],
            'changing' => $structure->changing(),
        ];
    }

    private function isProjectionSafeStructure(StorageWorkbenchStructure $structure): bool
    {
        try {
            foreach ($structure->fields as $field) {
                $this->assertSafeKey((string) ($field['key'] ?? ''));
            }

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    private function recordProjection(StorageWorkbenchRecord $record): array
    {
        foreach (array_keys($record->values) as $key) {
            $this->assertSafeKey((string) $key);
        }

        return [
            'structure_id' => $record->structureId,
            'record_id' => $record->recordId,
            'revision' => $record->revision,
            'schema_version' => $record->schemaVersion,
            'state' => $record->state,
            // An encrypted value leaves as its mask, never as the envelope (StorageEncryptedFields).
            'values' => StorageEncryptedFields::mask($record->values),
            'content_hash' => $record->contentHash,
            'operation' => $record->operation,
            'updated_at' => $record->updatedAt,
            'actor' => $record->actor,
        ];
    }

    private function scope(string $actor, ?string $requestedScope): string
    {
        $actual = $this->scopes->scopeForActor($actor);
        if ($requestedScope !== null && !hash_equals($actual, $requestedScope)) {
            throw new RuntimeException(StorageSectionScope::SCOPE_DENIED);
        }

        return $actual;
    }

    private function isScopeDenial(RuntimeException $exception): bool
    {
        return in_array($exception->getMessage(), [StorageSectionScope::SCOPE_DENIED, StorageSectionScope::SCOPE_UNSUPPORTED], true);
    }

    /** @return array<string, mixed> */
    private function state(string $status, string $reason): array
    {
        return [
            'status' => $status,
            'reason_code' => $reason,
            'scope_ref' => null,
            'structures' => [],
            'selected_structure' => null,
            'records' => ['items' => [], 'continuation' => null, 'matched_count' => 0, 'matched_count_exact' => true, 'history' => []],
            'files' => ['nodes' => []],
            'documents' => [],
            'pages' => [],
            'publication' => null,
            'permissions' => [],
            'limits' => [],
        ];
    }

    /**
     * @param array<string, string> $redirect
     * @param array{field?: string, field_reason?: string} $field the field a refused value belongs to, and why
     * @return array<string, mixed>
     */
    private function receipt(string $status, string $reason, ?string $scopeRef, array $redirect = [], array $field = []): array
    {
        return [
            'status' => $status,
            'reason_code' => $reason,
            'scope_ref' => $scopeRef,
            'notice' => $reason,
            'redirect' => $redirect,
        ] + $field;
    }

    /** @param array<string, mixed> $payload */
    private function structureId(array $payload): string
    {
        return $this->identity($payload['structure_id'] ?? null, '/\Aworkbench\.[a-z][a-z0-9_.:-]{0,109}\z/D');
    }

    private function identity(mixed $value, string $pattern): string
    {
        if (!is_string($value) || preg_match($pattern, $value) !== 1) {
            throw new InvalidArgumentException('storage_workbench_identity_invalid');
        }

        return $value;
    }

    private function nullableIdentity(mixed $value, string $pattern): ?string
    {
        return $value === null ? null : $this->identity($value, $pattern);
    }

    private function uuid(mixed $value): string
    {
        return $this->identity($value, '/\A[a-f0-9]{8}-[a-f0-9-]{27}\z/iD');
    }

    private function recordId(mixed $value): string
    {
        return $this->identity($value, '/\Arecord-[a-f0-9]{32}\z/D');
    }

    private function nullableRecordId(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : $this->recordId($value);
    }

    private function text(mixed $value, int $minimum, int $maximum): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('storage_workbench_text_invalid');
        }
        $value = trim($value);
        // Lengths are characters, as the forms say (a Cyrillic letter is two bytes).
        if (mb_strlen($value) < $minimum || mb_strlen($value) > $maximum
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]|<[^>]*>|<\?php|javascript\s*:/i', $value) === 1) {
            throw new InvalidArgumentException('storage_workbench_text_invalid');
        }

        return $value;
    }

    private function nullableText(mixed $value, int $maximum): ?string
    {
        return $value === null ? null : $this->text($value, 1, $maximum);
    }

    /** @return list<string> */
    private function choices(mixed $value): array
    {
        if (is_string($value)) {
            $value = [$value];
        }
        if (!is_array($value) || !array_is_list($value) || count($value) > 100) {
            throw new InvalidArgumentException('storage_workbench_value_invalid');
        }

        return array_values(array_unique(array_map(fn (mixed $item): string => is_string($item)
            ? $this->text($item, 1, 1000) : throw new InvalidArgumentException('storage_workbench_value_invalid'), $value)));
    }

    /**
     * @param array<array-key, mixed> $value
     * @return array<string, string>
     */
    private function linkValue(array $value): array
    {
        $link = [];
        foreach ($value as $key => $item) {
            if (!is_string($key) || !is_string($item) || strlen($item) > 4096) {
                throw new InvalidArgumentException('storage_workbench_value_invalid');
            }
            $link[$key] = $item;
        }

        return $link;
    }

    private function safeScalar(mixed $value): string|int|float|bool
    {
        if (!is_string($value) && !is_int($value) && !is_float($value) && !is_bool($value)) {
            throw new InvalidArgumentException('storage_workbench_value_invalid');
        }
        if (is_float($value) && !is_finite($value)) {
            throw new InvalidArgumentException('storage_workbench_value_invalid');
        }
        if (is_string($value)) {
            return $this->text($value, 1, 65_536);
        }

        return $value;
    }

    private function integer(mixed $value, int $minimum, int $maximum): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if (!is_int($integer) || $integer < $minimum || $integer > $maximum) {
            throw new InvalidArgumentException('storage_workbench_integer_invalid');
        }

        return $integer;
    }

    private function boolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (in_array($value, ['1', 1, 'true'], true)) {
            return true;
        }
        if (in_array($value, ['0', 0, 'false'], true)) {
            return false;
        }
        throw new InvalidArgumentException('storage_workbench_boolean_invalid');
    }

    /**
     * The settings of a new or retyped field: those the change names (`settings`, or a descriptor's own `constraints`
     * from JSON), the options as typed, a string's maximum length; a «Storage record» field names an active storage
     * of this scope as its target. Checked by Property against the type's declaration.
     *
     * @param array<mixed> $candidate
     * @return array<string, mixed>
     */
    private function fieldConstraints(string $scopeRef, string $actor, string $type, array $candidate): array
    {
        $declared = PropertyTypeSettingsCatalog::builtIns()->latest($type);
        $constraints = [];
        foreach (is_array($candidate['constraints'] ?? null) ? $candidate['constraints'] : [] as $key => $value) {
            if (is_string($key)) {
                $constraints[$key] = $value;
            }
        }
        foreach ($this->candidateSettings($type, $candidate) as $key => $value) {
            if ($value === null) {
                unset($constraints[$key]);
            } else {
                $constraints[$key] = $value;
            }
        }
        if ($declared?->setting('options') !== null) {
            $text = $candidate['options_text'] ?? null;
            if (is_string($text)) {
                $constraints['options'] = $this->optionsFromText($text);
            } elseif (!is_array($constraints['options'] ?? null) || $constraints['options'] === []) {
                // A descriptor that already names its options (a storage created from JSON) keeps them.
                throw new InvalidArgumentException('storage_workbench_field_options_required');
            } else {
                $constraints['options'] = array_values($constraints['options']);
            }
        }
        if ($declared?->setting('target_structure_id') !== null) {
            $target = $constraints['target_structure_id'] ?? null;
            if (!is_string($target) || preg_match('/\Aworkbench\.[a-z][a-z0-9_.:-]{0,109}\z/D', $target) !== 1) {
                throw new InvalidArgumentException('storage_workbench_field_target_invalid');
            }
            $active = false;
            foreach ($this->workbench->listStructures($scopeRef, $actor) as $structure) {
                $active = $active || $structure->structureId === $target;
            }
            if (!$active) {
                throw new InvalidArgumentException('storage_workbench_field_target_invalid');
            }
        }
        $this->assertSettings($type, $declared->version ?? 1, $constraints);

        return $constraints;
    }

    /**
     * Which of the section's sentences explains why one field's value was refused (the receipt's field_reason, the
     * section's own reason or Property's): `section.value_errors.<key>`.
     */
    public static function valueErrorKey(string $reason): string
    {
        return match (true) {
            $reason === 'storage_workbench_required_field_missing' => 'required',
            $reason === 'storage_workbench_number_invalid', str_starts_with($reason, 'property_number_not_canonical') => 'number',
            $reason === 'storage_workbench_integer_invalid', in_array($reason, ['property_integer_not_canonical', 'property_integer_type_mismatch', 'property_integer_out_of_range'], true) => 'integer',
            (bool) preg_match('/^property_(?:number|integer|date|datetime)_min$/', $reason) => 'too_small',
            (bool) preg_match('/^property_(?:number|integer|date|datetime)_max$/', $reason) => 'too_large',
            (bool) preg_match('/^property_(?:string|text)_max_length$/', $reason) => 'too_long',
            (bool) preg_match('/^property_(?:string|text)_min_length$/', $reason) => 'too_short',
            $reason === 'storage_workbench_text_invalid' => 'text',
            $reason === 'property_date_invalid' => 'date',
            $reason === 'property_datetime_invalid' => 'datetime',
            str_starts_with($reason, 'property_choice'), str_starts_with($reason, 'property_lookup') => 'option',
            $reason === 'property_record_reference_invalid' => 'record',
            $reason === 'storage_workbench_file_reference_ineligible' => 'file',
            $reason === 'storage_workbench_boolean_invalid', str_starts_with($reason, 'property_boolean_') => 'boolean',
            default => 'invalid',
        };
    }

    /** «Максимальная длина» of a string field: empty for none, else a whole number from 1 to 65535. */
    private function maxLength(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $length = filter_var(is_string($value) ? trim($value) : $value, FILTER_VALIDATE_INT);
        if (!is_int($length) || $length < 1 || $length > self::MAX_STRING_LENGTH) {
            throw new InvalidArgumentException('storage_workbench_field_max_length_invalid');
        }

        return $length;
    }

    /** @return list<array{value:string,label:string,color?:string,icon?:string}> */
    private function optionsFromText(string $text): array
    {
        return \Larena\Admin\Runtime\PropertyTypeSettingsForm::optionsFromText($text, 'storage_workbench_field_options_invalid', 'storage_workbench_field_options_required');
    }



    private function assertSafeKey(string $key): void
    {
        if (preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $key) !== 1
            || in_array(strtolower($key), self::FORBIDDEN_KEYS, true)
            || preg_match('/(?:^|_)(?:path|disk|storage_key|private|secret|password|token|html|raw)(?:_|$)/i', $key) === 1) {
            throw new InvalidArgumentException('storage_workbench_key_unsafe');
        }
    }
}
