<?php

declare(strict_types=1);

namespace Larena\Storage\Admin;

use Larena\Access\Contracts\ActorOperationAuthorizer;
use Larena\Storage\Admin\Contracts\StorageWorkbenchGateway;
use Larena\Admin\Record\AdminRecordLayoutStore;
use Larena\Admin\Runtime\PropertyTypeSettingsForm;
use Larena\Admin\Runtime\PropertyViewPort;
use Larena\Property\Contracts\PropertyField;
use Larena\Property\Contracts\PropertyOptionSource;
use Larena\Property\Contracts\PropertySettingDescriptor as Setting;
use Larena\Property\Contracts\PropertyTypeSettings;
use Larena\Property\Enums\PropertySettingKind as Kind;
use Larena\Property\Runtime\ListPropertyOptionSource;
use Larena\Property\Runtime\PropertyTypeSettingsCatalog;
use Larena\Storage\Encryption\StorageEncryptionPolicy;
use Larena\Storage\Runtime\StorageStructureOptionSource;

/**
 * A storage's field as a record of the Framework property view (admin page standard, owner decision 2026-10-16):
 * collection «storage.fields.<structure>», record = the field's key. It is viewed, edited and created in the record
 * panel over the storage settings list; every write goes through the storage's own change (structure.configure),
 * and a change that rewrites records (type, «required», options, removal) takes its migration.
 *
 * «Шифровать» (larena-specs docs/architecture/2026-10-22-storage-field-encryption.md) is offered for a string, text,
 * integer, number, date or date-time field to a person with storage.encryption.manage only; an encrypted field is
 * neither searched nor indexed and is seen in the admin only, and its panel says so. Switching it on or off an existing
 * field waits for its confirmation («Подтверждаю включение шифрования», which lists what changes, or «Подтверждаю
 * расшифровку», sent to Storage as `encryption_disable_confirmed`); Storage then seals or opens the stored values in
 * the background, and each of its refusals comes back in the section's words.
 *
 * The editor is built from the type's declared settings (settings system, stage 3): every setting a type declares
 * (PropertyTypeSettingsCatalog) is a field of the editor, drawn by its kind (a number, a date, a text, a list one per
 * line, the options «value | Label | colour | icon», a storage to point to, a source to choose from), labelled
 * `larena-property::settings.<key>`; one locked after creation is read only on an existing field. The editor holds the
 * settings of every type, each with its condition (port 1.14.0 `when`): shown while the type chosen is one that
 * declares it, so switching the type shows its own settings. A change that touches data (Property's
 * dataChangingKeys(), a retype, «required») is first checked over the stored records: when some would not fit the
 * save is refused under the changed fields; otherwise the panel asks for a confirmation in its save bar (port 1.14.0
 * `confirm_required`: «Новые настройки затронут данные: проверено M из T записей…», with the digest of the storage
 * revision, the field and the new settings), and the same save raised with that digest has Storage rebuild the
 * records in the background (storage.schema.migrate). A change that touches no data applies at once. Property and
 * Storage stay the judges of every value.
 */
final class StorageFieldPropertyViewPort extends PropertyViewPort
{
    public const COLLECTION_PREFIX = 'storage.fields.';

    /** The editor's field for the options of a choice («value | Label | colour | icon», one per line). */
    public const OPTIONS_FIELD = 'options_text';

    /** The panel's refusals of a change that touches data: some records would not fit, or it is not this person's. */
    public const RECORDS_INVALID = 'storage_field_change_records_invalid';
    public const MIGRATE_DENIED = 'storage_field_change_migrate_denied';
    /** A setting the editor could not read as its kind, said under that setting. */
    public const SETTING_INVALID = 'storage_field_setting_invalid';

    /** The types a field may be localized for (Storage keeps the flag on any field; translations are text). */
    private const LOCALIZED_TYPES = ['string', 'text'];

    /** What the last check of a change found (Storage's checkFieldChange()), for the panel's words. @var array<string,mixed>|null */
    private ?array $check = null;

    /** The storage the last read or write saw, so a refusal while its field change runs names that field. @var array<string,mixed>|null */
    private ?array $seen = null;

    /** The operation that makes and switches encrypted fields (critical, Storage asks for it too). */
    public const MANAGE_OPERATION = 'storage.encryption.manage';

    /** Whether the person of the last read may make and switch encrypted fields. */
    private bool $manage = false;

    /** The storages of the scope the last read saw, by id: a «Storage record» field points to an active one. @var array<string,array{label:string,active:bool}> */
    private array $targets = [];

    /** The editor's confirmation of switching encryption on or off an existing field; never stored. */
    public const CONFIRM_FIELD = 'encryption_confirmed';

    public function __construct(private StorageWorkbenchGateway $gateway, AdminRecordLayoutStore $layouts,
        private ?ActorOperationAuthorizer $authorizer = null)
    {
        parent::__construct($layouts);
    }

    protected function collectionPattern(): string
    {
        return '/^storage\.fields\.workbench\.[a-z][a-z0-9_.:-]{0,109}$/';
    }

    protected function recordPattern(): string
    {
        return '/^[a-z][a-z0-9_]{0,63}$/';
    }

    protected function conflictReason(): string
    {
        return 'storage_workbench_revision_conflict';
    }

    protected function deleteQuestion(): string
    {
        return $this->word('field_delete_question', 'Delete this field? Its values go from every record of the storage.');
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>}|array<string,mixed> */
    protected function readObject(string $actor, string $scope, string $collection, string $recordId): array
    {
        $structure = $this->structure($actor, $collection);
        if ($structure === null) {
            return ['answer' => 'refused', 'reason' => 'structure_unknown'];
        }
        $field = null;
        foreach ($structure['fields'] as $candidate) {
            if (is_array($candidate) && ($candidate['key'] ?? null) === $recordId) {
                $field = $candidate;
            }
        }
        if ($field === null) {
            return ['answer' => 'refused', 'reason' => 'record_unknown'];
        }

        // While a field change runs the field is read, not changed; the panel says what goes on with it.
        $state = $this->fieldState($structure, $recordId);

        return [$this->collectionOf($structure, false, $field, $state !== null), ['record_id' => $recordId, 'revision' => (int) $structure['version'],
            'values' => $this->valuesOf($field) + ($state !== null ? ['field_state' => $state] : []),
            'editable' => $structure['configure'] && !$structure['changing'], 'deletable' => $structure['migrate'] && !$structure['changing']]];
    }

    /** @param array<string,mixed> $values @return array<string,mixed> */
    protected function writeObject(string $actor, string $scope, string $collection, string $recordId, int|string $revision, array $values): array
    {
        $structure = $this->structure($actor, $collection);
        if ($structure === null || !$structure['configure']) {
            return ['status' => 'rejected', 'reason_code' => 'access_denied'];
        }
        if ((string) $revision !== (string) $structure['version']) {
            return ['status' => 'rejected', 'reason_code' => $this->conflictReason()];
        }
        if ($structure['changing']) {
            return ['status' => 'rejected', 'reason_code' => StorageSectionGateway::FIELD_CHANGE_RUNNING];
        }
        $field = null;
        foreach ($structure['fields'] as $candidate) {
            if (is_array($candidate) && ($candidate['key'] ?? null) === $recordId) {
                $field = $candidate;
            }
        }
        $candidate = $this->candidate($values, $field);
        if (isset($candidate['refused'])) {
            return $candidate['refused'];
        }
        $change = ['key' => $recordId] + $candidate;
        // Switching encryption on or off an existing field is confirmed first: its values are sealed or opened in the
        // background, and the field leaves or comes back to search, sorting and filters.
        $disable = false;
        $switched = false;
        if (is_array($field) && array_key_exists('encrypted', $change) && $change['encrypted'] !== StorageEncryptedFields::isEncrypted($field)) {
            if (!self::flag($values[self::CONFIRM_FIELD] ?? null)) {
                return ['status' => 'rejected', 'reason_code' => $change['encrypted'] === true
                    ? StorageSectionGateway::ENCRYPTION_CONFIRM_REQUIRED : StorageEncryptionPolicy::DISABLE_UNCONFIRMED];
            }
            $disable = $change['encrypted'] !== true;
            // The encryption switch has its own confirmation, which says what it does to the stored values.
            $switched = true;
        }

        // A change that touches the stored records is checked over them and confirmed first (port 1.14.0: the same save
        // raised again with the digest of this very change); one that does not applies.
        $digest = self::changeDigest($structure, $change);
        $confirmed = $structure['migrate'] && ($switched || $this->confirmation() === $digest);
        $receipt = $this->configure($actor, $structure, [$change], $confirmed, $disable);
        if (($receipt['reason_code'] ?? null) !== StorageSectionGateway::MIGRATION_UNCONFIRMED) {
            return $receipt;
        }
        if (!$structure['migrate']) {
            return ['status' => 'rejected', 'reason_code' => self::MIGRATE_DENIED];
        }
        $this->check = $this->checkChange($actor, $structure, [$change], $disable);
        // A storage without records has nothing the change could touch: it applies as it is.
        if (is_array($this->check) && (int) ($this->check['total'] ?? -1) === 0) {
            return $this->configure($actor, $structure, [$change], true, $disable);
        }
        // Records the new settings would not fit refuse the change outright, under the fields it changes.
        if (is_array($this->check) && (int) ($this->check['invalid'] ?? 0) > 0) {
            return ['status' => 'rejected', 'reason_code' => self::RECORDS_INVALID];
        }

        return ['status' => 'rejected', 'reason_code' => PropertyViewPort::CONFIRM_REQUIRED,
            'confirm' => ['summary' => $this->checkMessage(PropertyViewPort::CONFIRM_REQUIRED), 'digest' => $digest]];
    }

    /**
     * The digest a change that touches data is confirmed with: the storage's revision, the field and its new settings,
     * so a confirmation given before the storage or the change moved is asked again.
     *
     * @param array<string,mixed> $structure @param array<string,mixed> $change
     */
    private static function changeDigest(array $structure, array $change): string
    {
        $canonical = static function (mixed $value) use (&$canonical): mixed {
            if (!is_array($value)) {
                return $value;
            }
            if (!array_is_list($value)) {
                ksort($value);
            }

            return array_map($canonical, $value);
        };

        return hash('sha256', 'field-change|'.json_encode([$structure['structure_id'], (int) $structure['version'], $canonical($change)],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * What a change would do to the stored records (Storage's check, through the section): how many were checked and
     * how many would not fit; null when the check is not available.
     *
     * @param array<string,mixed> $structure @param list<array<string,mixed>> $changes
     * @return array<string,mixed>|null
     */
    private function checkChange(string $actor, array $structure, array $changes, bool $disableConfirmed): ?array
    {
        $receipt = $this->gateway->dispatch($actor, null, 'structure.configure_check', $this->payload($structure, $changes, false, $disableConfirmed));
        $check = $receipt['redirect']['check'] ?? null;

        return ($receipt['status'] ?? null) === 'ok' && is_array($check) ? $check : null;
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>}|array<string,mixed> */
    protected function readCollection(string $actor, string $scope, string $collection): array
    {
        $structure = $this->structure($actor, $collection);
        if ($structure === null) {
            return ['answer' => 'refused', 'reason' => 'structure_unknown'];
        }

        return [$this->collectionOf($structure, true), ['creatable' => $structure['configure'] && !$structure['changing']]];
    }

    /** @param array<string,mixed> $values @return array<string,mixed> */
    protected function createObject(string $actor, string $scope, string $collection, array $values): array
    {
        $structure = $this->structure($actor, $collection);
        if ($structure === null || !$structure['configure']) {
            return ['status' => 'rejected', 'reason_code' => 'access_denied'];
        }
        if ($structure['changing']) {
            return ['status' => 'rejected', 'reason_code' => StorageSectionGateway::FIELD_CHANGE_RUNNING];
        }
        $key = is_string($values['key'] ?? null) ? trim($values['key']) : '';
        if (preg_match($this->recordPattern(), $key) !== 1) {
            return ['status' => 'rejected', 'reason_code' => 'key'];
        }
        // A key another field has would change that field instead of making a new one.
        foreach ($structure['fields'] as $existing) {
            if (is_array($existing) && ($existing['key'] ?? null) === $key) {
                return ['status' => 'rejected', 'reason_code' => 'storage_workbench_key_taken'];
            }
        }
        $positions = array_map(static fn (array $field): int => (int) ($field['position'] ?? 0), array_filter($structure['fields'], 'is_array'));
        $candidate = $this->candidate($values, null);
        if (isset($candidate['refused'])) {
            return $candidate['refused'];
        }
        $receipt = $this->configure($actor, $structure, [['key' => $key, 'position' => ($positions === [] ? 0 : max($positions)) + 10] + $candidate], false);

        return ($receipt['status'] ?? null) === 'ok' ? $receipt + ['redirect' => ['record_id' => $key]] : $receipt;
    }

    protected function deleteObject(string $actor, string $scope, string $collection, string $recordId, int|string $revision): array
    {
        $structure = $this->structure($actor, $collection);
        if ($structure === null || !$structure['migrate']) {
            return ['status' => 'rejected', 'reason_code' => 'access_denied'];
        }
        if ((string) $revision !== (string) $structure['version']) {
            return ['status' => 'rejected', 'reason_code' => $this->conflictReason()];
        }
        if ($structure['changing']) {
            return ['status' => 'rejected', 'reason_code' => StorageSectionGateway::FIELD_CHANGE_RUNNING];
        }

        return $this->configure($actor, $structure, [['key' => $recordId, 'remove' => true]], true);
    }

    /**
     * The storage this collection names, as this person sees it: its fields, version and the rights to configure
     * and to migrate (a change that rewrites records), and whether a field change runs in the background.
     *
     * @return array{structure_id:string,label:string,version:int,fields:list<mixed>,configure:bool,migrate:bool,changing:bool,selected:array<string,mixed>,targets:array<string,array{label:string,active:bool}>}|null
     */
    private function structure(string $actor, string $collection): ?array
    {
        $structureId = substr($collection, strlen(self::COLLECTION_PREFIX));
        $state = $this->gateway->snapshot($actor, null, ['surface' => 'storage_section', 'structure_id' => $structureId, 'include_archived_structures' => true, 'records' => 'skip']);
        $selected = $state['selected_structure'] ?? null;
        if (($state['status'] ?? null) !== 'ok' || !is_array($selected) || ($selected['structure_id'] ?? null) !== $structureId) {
            return null;
        }
        $permissions = is_array($state['permissions'] ?? null) ? $state['permissions'] : [];
        $configure = ($permissions['structure.configure'] ?? false) === true && ($selected['archived'] ?? false) !== true;
        $this->seen = $selected;
        $this->manage = $configure && $this->mayManageEncryption($actor);

        // The storages of the scope a «Storage record» field may point to (active ones), and every storage's name for
        // the target an existing field shows.
        $targets = [];
        foreach (is_array($state['structures'] ?? null) ? $state['structures'] : [] as $listed) {
            if (is_array($listed) && is_string($listed['structure_id'] ?? null)) {
                $targets[$listed['structure_id']] = ['label' => is_string($listed['label'] ?? null) && trim($listed['label']) !== '' ? $listed['label'] : $listed['structure_id'],
                    'active' => ($listed['archived'] ?? false) !== true];
            }
        }

        $this->targets = $targets;

        return ['structure_id' => $structureId, 'label' => (string) ($selected['label'] ?? $structureId), 'version' => (int) ($selected['version'] ?? 0),
            'fields' => array_values(is_array($selected['fields'] ?? null) ? $selected['fields'] : []),
            'configure' => $configure, 'migrate' => $configure && ($permissions['structure.migrate'] ?? false) === true,
            'changing' => StorageFieldChangeState::changing($selected), 'selected' => $selected, 'targets' => $targets];
    }

    private function mayManageEncryption(string $actor): bool
    {
        if ($this->authorizer === null) {
            return false;
        }
        try {
            $this->authorizer->assertAllowed($actor, self::MANAGE_OPERATION);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** The editor's own hints stand under their fields in the arrangement every layer starts from. */
    protected function defaultLayout(array $fields): array
    {
        $layout = parent::defaultLayout($fields);
        foreach ($fields as $field) {
            if (is_string($field['key'] ?? null) && is_string($field['hint'] ?? null) && $field['hint'] !== ''
                && isset($layout['fields'][AdminRecordLayoutStore::part($field['key'])])) {
                $layout['fields'][AdminRecordLayoutStore::part($field['key'])]['hint'] = $field['hint'];
            }
        }

        return $layout;
    }

    /** While the storage's field change runs, a write from a panel opened before it is refused in words that name the field. */
    protected function lockedMessage(): ?string
    {
        return $this->seen !== null && StorageFieldChangeState::changing($this->seen)
            ? (new StorageFieldChangeState(\Illuminate\Container\Container::getInstance()->make('translator')))->refusal(StorageSectionGateway::FIELD_CHANGE_RUNNING, $this->seen)
            : null;
    }

    /** Storage's refusals of the search and index options, in the section's words. */
    protected function reasonMessage(string $reason): ?string
    {
        if (in_array($reason, [StorageSectionGateway::FIELD_CHANGE_RUNNING, StorageEncryptionRunState::RUNNING, StorageEncryptionRunState::FAILED], true)) {
            return $this->changeState()->refusal($reason, $this->seen);
        }
        if (in_array($reason, [self::RECORDS_INVALID, self::MIGRATE_DENIED], true)) {
            return $this->checkMessage($reason);
        }
        $key = match (true) {
            in_array($reason, StorageSectionGateway::FIELD_OPTION_REASONS, true) => 'larena-storage::admin.section.field_option_errors.'.$reason,
            in_array($reason, StorageSectionGateway::ENCRYPTION_REASONS, true) => 'larena-storage::admin.section.encryption.errors.'.$reason,
            $reason === 'key' || $reason === self::SETTING_INVALID || in_array($reason, StorageSectionGateway::FIELD_INPUT_REASONS, true) => 'larena-storage::admin.section.field_errors.'.$reason,
            $reason === 'storage_workbench_field_options_invalid' => 'larena-storage::admin.section.notice_options_invalid',
            $reason === 'storage_workbench_records_unconvertible' => 'larena-storage::admin.section.notice_unconvertible',
            default => null,
        };
        $text = $key === null ? '' : $this->text($key, '');

        return $text !== '' ? $text : null;
    }

    /** The editor's field a refusal of Storage or of the section is about, for the message to stand under it. */
    protected function reasonField(string $reason): ?string
    {
        return match (true) {
            in_array($reason, ['key', 'storage_workbench_key_unsafe', 'storage_workbench_key_taken'], true) => 'key',
            $reason === 'storage_workbench_field_target_invalid' => 'target_structure_id',
            in_array($reason, ['storage_workbench_field_max_length_invalid', 'storage_field_indexed_max_length_invalid'], true) => 'max_length',
            $reason === 'storage_workbench_field_type_invalid' => 'type',
            $reason === 'storage_workbench_text_invalid' => 'label',
            $reason === 'storage_workbench_field_options_invalid' => self::OPTIONS_FIELD,
            $reason === 'storage_field_searchable_type_unsupported' => 'searchable',
            in_array($reason, ['storage_field_indexed_type_unsupported', 'storage_field_index_limit_exceeded'], true) => 'indexed',
            in_array($reason, [StorageSectionGateway::ENCRYPTION_CONFIRM_REQUIRED, StorageEncryptionPolicy::DISABLE_UNCONFIRMED], true) => self::CONFIRM_FIELD,
            in_array($reason, StorageSectionGateway::ENCRYPTION_REASONS, true) => 'encrypted',
            default => null,
        };
    }

    /**
     * The words of a change that touches data: how many records were checked and how many would not fit, or why it
     * cannot be made here.
     */
    private function checkMessage(string $reason): string
    {
        $check = $this->check ?? [];
        $samples = array_slice(array_values(array_filter(is_array($check['samples'] ?? null) ? $check['samples'] : [], 'is_string')), 0, 3);
        $replace = [':checked' => (string) (int) ($check['checked'] ?? 0), ':total' => (string) (int) ($check['total'] ?? 0),
            ':invalid' => (string) (int) ($check['invalid'] ?? 0), ':samples' => implode(', ', $samples)];
        $key = match (true) {
            $reason === self::RECORDS_INVALID => 'records_invalid',
            $reason === self::MIGRATE_DENIED => 'migrate_denied',
            $this->check === null => 'confirm_unchecked',
            ($check['complete'] ?? true) === false => 'confirm_required_partial',
            default => 'confirm_required',
        };

        return strtr($this->text('larena-storage::admin.section.data_change.'.$key, match ($key) {
            'records_invalid' => 'The new settings do not fit :invalid of the :checked records checked (for example :samples).',
            'migrate_denied' => 'This change touches the stored records, and you may not rebuild the storage.',
            'confirm_unchecked' => 'The new settings will touch the stored records: they are rebuilt in the background.',
            default => 'The new settings will touch the data: :checked of :total records checked, :invalid do not fit.',
        }), $replace);
    }

    /**
     * The field editor: a new field, or the one being viewed. Its settings are every setting the types declare, each
     * drawn by its kind; those of the field's own type carry its values, and one locked after creation is read only.
     * The search, index, translation and encryption options follow as before.
     *
     * @param array<string,mixed> $structure @param array<string,mixed>|null $current
     * @return array<string,mixed>
     */
    private function collectionOf(array $structure, bool $create, ?array $current = null, bool $stated = false): array
    {
        $types = [];
        foreach (PropertyTypeSettingsCatalog::builtIns()->typeKeys() as $type) {
            // A block assembly is edited by the page that owns it (the site pages' content), not as a storage field.
            if ($type === 'content') {
                continue;
            }
            $types[] = ['value' => $type, 'label' => $this->text('larena-storage::admin.section.types.'.$type, $type)];
        }
        $own = $current === null ? null : self::declared($current);
        $settings = [];
        foreach (self::editorSettings() as $key => $entry) {
            $locked = $own !== null && $own->setting($entry['setting']->key) === $entry['setting'] && $entry['setting']->afterCreation === Setting::AFTER_CREATION_LOCKED;
            $settings[] = $this->settingField($key, $entry['setting'], $locked);
        }
        // One field description each (Property's PropertyField); the property panel builds them all the same way.
        $fields = [
            new PropertyField('label', 'string', 1, $this->text('larena-storage::admin.section.label', 'Label'), ['max_length' => 120], required: true),
            // The key names the field in every record: given once, when the field is made.
            new PropertyField('key', 'string', 1, $this->text('larena-storage::admin.section.field_key', 'Key'), ['max_length' => 64],
                required: $create, readonly: !$create),
            // A new field starts as a String, neither required, hidden, translated nor encrypted: each choice has a value from the start.
            new PropertyField('type', 'choice', 1, $this->text('larena-storage::admin.section.type', 'Type'), ['options' => $types], required: true,
                default: $create ? 'string' : null),
            ...$settings,
            new PropertyField('required', 'boolean', 1, $this->text('larena-storage::admin.section.required', 'Required'),
                default: $create ? false : null),
            new PropertyField('hidden', 'boolean', 1, $this->text('larena-storage::admin.section.hidden', 'Hidden'),
                default: $create ? false : null),
            ...$this->optionFields($create, $current),
            new PropertyField('localized', 'boolean', 1, $this->text('larena-storage::admin.section.localized', 'Translated into other languages'),
                hint: $this->text('larena-storage::admin.section.localized_hint', ''), default: $create ? false : null),
            ...$this->encryptionField($create, $current),
            ...$this->confirmField($create, $current),
            // What goes on with the field in the background, shown and never written.
            ...($stated ? [new PropertyField('field_state', 'text', 1, $this->changeState()->columnLabel(), readonly: true)] : []),
        ];

        return ['structure_id' => self::COLLECTION_PREFIX.$structure['structure_id'], 'label' => $structure['label'],
            'fields' => array_map(static fn (PropertyField $field): array => self::conditioned($field->toArray()), $fields)];
    }

    /**
     * When an editor field is shown (port 1.14.0 `when`): a setting, the search, index, translation and encryption
     * options while the type chosen is one that takes them (fieldTypes()); search and the index not while «Шифровать» is
     * on. The component evaluates it over the values on screen, so switching the type shows that type's settings.
     *
     * @param array<string,mixed> $field
     * @return array<string,mixed>
     */
    private static function conditioned(array $field): array
    {
        $types = self::fieldTypes()[$field['key']] ?? null;
        if (!is_array($types)) {
            return $field;
        }
        $visible = ['field' => 'type', 'op' => 'in', 'value' => $types];
        if (in_array($field['key'], ['searchable', 'indexed'], true)) {
            $visible = ['all' => [$visible, ['not' => ['field' => 'encrypted', 'op' => 'eq', 'value' => true]]]];
        }

        return $field + ['when' => ['visible' => $visible]];
    }

    /**
     * Every setting the types declare, by the editor's key (PropertyTypeSettingsForm::editorSettings()).
     *
     * @return array<string, array{setting: Setting, types: list<string>}>
     */
    public static function editorSettings(): array
    {
        return PropertyTypeSettingsForm::editorSettings();
    }

    /** The editor key of one setting. */
    public static function editorKey(Setting $setting, ?bool $ambiguous = null): string
    {
        return PropertyTypeSettingsForm::editorKey($setting, $ambiguous);
    }

    /**
     * Which editor fields belong to which types, for the editor to show those of the type chosen (their `when`): the
     * settings, and the search, index, translation and encryption options.
     *
     * @return array<string, list<string>>
     */
    public static function fieldTypes(): array
    {
        $catalog = PropertyTypeSettingsCatalog::builtIns();
        $map = array_map(static fn (array $entry): array => $entry['types'], self::editorSettings());

        return $map + ['searchable' => $catalog->typesWith('searchable'), 'indexed' => $catalog->typesWith('indexable'),
            'localized' => self::LOCALIZED_TYPES, 'encrypted' => $catalog->typesWith('encryptable')];
    }

    /** The settings a field's type declares at its version (its latest, for a type the catalog does not hold at it). @param array<string,mixed> $field */
    private static function declared(array $field): ?PropertyTypeSettings
    {
        $catalog = PropertyTypeSettingsCatalog::builtIns();
        $type = (string) ($field['type'] ?? 'string');

        return (is_int($field['type_version'] ?? null) ? $catalog->for($type, $field['type_version']) : null) ?? $catalog->latest($type);
    }

    /** One setting as a field of the editor, by its kind; its label is Property's. */
    private function settingField(string $key, Setting $setting, bool $locked): PropertyField
    {
        return PropertyTypeSettingsForm::field($key, $setting, $locked, $this->text(...),
            self::types()->optionSources()?->choices($this->viewLocale()) ?? [], StorageSectionGateway::MAX_STRING_LENGTH);
    }

    /**
     * A «Storage record» field points to an active storage of this scope (the storages the section lists for this
     * person); an existing one shows the storage it points to by name.
     *
     * @param array<string,mixed> $field
     */
    protected function lookupSource(string $actor, string $scope, array $field): ?PropertyOptionSource
    {
        if (($field['constraints']['source'] ?? null) !== StorageStructureOptionSource::KEY || $this->seen === null) {
            return parent::lookupSource($actor, $scope, $field);
        }
        $targets = $this->targets;

        return new ListPropertyOptionSource(StorageStructureOptionSource::KEY, static function (?string $locale) use ($targets): array {
            $options = [];
            foreach ($targets as $id => $target) {
                if ($target['active']) {
                    $options[] = ['value' => (string) $id, 'label' => $target['label']];
                }
            }

            return $options;
        }, static fn (?string $locale): string => StorageStructureOptionSource::KEY);
    }

    /**
     * What the field panel says about the field: the storage's running field change (with its progress)
     * and that its structure cannot change meanwhile, the last change that failed, or «индексируется…»
     * while the field's list column is still filled; null when there is nothing to say.
     *
     * @param array{selected:array<string,mixed>} $structure
     */
    private function fieldState(array $structure, string $key): ?string
    {
        $state = $this->changeState();
        $lines = [];
        $notice = $state->notice($structure['selected']);
        if ($notice !== null) {
            $lines[] = $notice['title'].'. '.$notice['text'];
        }
        $locked = $state->locked($structure['selected']);
        if ($locked !== null) {
            $lines[] = $locked;
        }
        $pending = $structure['selected']['view_pending_fields'] ?? [];
        if (is_array($pending) && in_array($key, $pending, true)) {
            $lines[] = $this->text('larena-storage::admin.section.field_change.pending', 'indexing…');
        }

        return $lines === [] ? null : implode("\n", $lines);
    }

    private function changeState(): StorageFieldChangeState
    {
        return new StorageFieldChangeState(\Illuminate\Container\Container::getInstance()->make('translator'));
    }

    /** @param array<string,mixed> $field @return array<string,mixed> */
    private function valuesOf(array $field): array
    {
        $constraints = is_array($field['constraints'] ?? null) ? $field['constraints'] : [];
        $type = (string) ($field['type'] ?? 'string');
        // The settings of the field's own type, each under its editor key, as the editor shows them.
        $settings = [];
        $editor = self::editorSettings();
        foreach (self::declared($field)->settings ?? [] as $setting) {
            $key = self::editorKey($setting, !isset($editor[$setting->key]) || $editor[$setting->key]['setting']->kind !== $setting->kind);
            $value = $constraints[$setting->key] ?? null;
            $settings[$key] = match ($setting->kind) {
                Kind::Options => self::optionsText(is_array($value) ? $value : []),
                Kind::StringList => is_array($value) ? implode("\n", array_filter($value, 'is_string')) : null,
                default => is_scalar($value) ? $value : null,
            };
        }

        return ['label' => (string) ($field['label'] ?? $field['key']), 'key' => (string) $field['key'], 'type' => $type,
            'required' => ($field['required'] ?? false) === true, 'hidden' => ($field['hidden'] ?? false) === true]
            + $settings
            // A string or text is searched unless it says otherwise; a string is indexed only on request; an
            // encrypted field is neither, whatever it stored.
            + (self::searchable($type)
                ? ['searchable' => !StorageEncryptedFields::isEncrypted($field) && ($field['searchable'] ?? true) !== false] : [])
            + (self::indexedType($type)
                ? ['indexed' => !StorageEncryptedFields::isEncrypted($field) && ($field['indexed'] ?? false) === true] : [])
            + (in_array($type, self::LOCALIZED_TYPES, true) ? ['localized' => ($field['localized'] ?? false) === true] : [])
            + (StorageEncryptedFields::isEncrypted($field) || $this->manage && StorageEncryptedFields::encryptable($type)
                ? ['encrypted' => StorageEncryptedFields::isEncrypted($field)] : [])
            // The confirmation of switching encryption starts unticked on every read.
            + ($this->confirmable($field) ? [self::CONFIRM_FIELD => false] : []);
    }

    /** @param array<array-key,mixed> $options */
    private static function optionsText(array $options): ?string
    {
        return PropertyTypeSettingsForm::optionsText($options);
    }

    /**
     * «Подтверждаю включение шифрования» (with what changes: search, sorting and filters, old backups) for a field that
     * is not encrypted, «Подтверждаю расшифровку» for one that is; offered with «Шифровать» on an existing field to a
     * person who may manage encryption.
     *
     * @param array<string,mixed>|null $current
     * @return list<PropertyField>
     */
    private function confirmField(bool $create, ?array $current): array
    {
        if ($create || !is_array($current) || !$this->confirmable($current)) {
            return [];
        }
        $encrypted = StorageEncryptedFields::isEncrypted($current);

        // Asked only once «Шифровать» is switched in the form (port 1.14.0 `when`), never a row of the field's view.
        return [new PropertyField(self::CONFIRM_FIELD, 'boolean', 1, StorageEncryptedFields::word($encrypted ? 'confirm_disable_label' : 'confirm_enable_label',
            $encrypted ? 'I confirm decryption' : 'I confirm turning encryption on'),
            hint: StorageEncryptedFields::word($encrypted ? 'confirm_disable_hint' : 'confirm_enable_hint', ''),
            attributes: ['when' => ['visible' => ['field' => 'encrypted', 'op' => 'ne', 'value' => $encrypted]]])];
    }

    /** An existing field whose encryption this person may switch. @param array<string,mixed> $field */
    private function confirmable(array $field): bool
    {
        return $this->manage && (StorageEncryptedFields::isEncrypted($field) || StorageEncryptedFields::encryptable((string) ($field['type'] ?? 'string')));
    }

    private static function flag(mixed $value): bool
    {
        return $value === true || $value === '1' || $value === 1;
    }

    /**
     * «Шифровать»: offered to a person who may manage encryption, for a new field and for an existing one whose type
     * can be encrypted; an encrypted field shows it to everyone, read-only without that right.
     *
     * @param array<string,mixed>|null $current
     * @return list<PropertyField>
     */
    private function encryptionField(bool $create, ?array $current): array
    {
        $encrypted = is_array($current) && StorageEncryptedFields::isEncrypted($current);
        $offered = $this->manage && ($create || StorageEncryptedFields::encryptable((string) ($current['type'] ?? 'string')));
        if (!$offered && !$encrypted) {
            return [];
        }
        $hint = StorageEncryptedFields::word('field_hint', 'The value cannot be searched, sorted or filtered; only an administrator can read it.')
            .' '.StorageEncryptedFields::word('field_visibility_hint', 'The field is seen in the admin only.')
            .($create ? ' '.StorageEncryptedFields::word('field_types_hint', 'For String, Text, Integer, Number, Date and Date and time fields.') : '');

        return [new PropertyField('encrypted', 'boolean', 1, StorageEncryptedFields::word('field_label', 'Encrypt'), readonly: !$offered, hint: $hint,
            default: $create ? false : null)];
    }

    /**
     * «Searched» for a string or text field (and a new one, whose type is chosen in the same form: it is
     * sent only for those types); «Fast sorting and filter» for an existing string, open to change only
     * while its maximum length is at most 255.
     *
     * @param array<string,mixed>|null $current
     * @return list<PropertyField>
     */
    private function optionFields(bool $create, ?array $current): array
    {
        $type = $create ? null : (string) ($current['type'] ?? 'string');
        // An encrypted field is neither searched nor indexed: both stand off and closed, with the reason.
        $sealed = is_array($current) && StorageEncryptedFields::isEncrypted($current)
            ? StorageEncryptedFields::word('option_closed_hint', 'Not available for an encrypted field') : null;
        $options = [];
        if ($create || ($type !== null && self::searchable($type))) {
            $options[] = new PropertyField('searchable', 'boolean', 1, $this->text('larena-storage::admin.section.searchable', 'Searched'),
                readonly: $sealed !== null,
                hint: $create ? $this->text('larena-storage::admin.section.searchable_hint', 'For string and text fields') : $sealed,
                default: $create ? true : null);
        }
        if ($type !== null && self::indexedType($type) && is_array($current)) {
            $indexable = self::indexable($current);
            $options[] = new PropertyField('indexed', 'boolean', 1, $this->text('larena-storage::admin.section.indexed', 'Fast sorting and filter'),
                readonly: $sealed !== null || !$indexable,
                hint: $sealed ?? ($indexable ? null : $this->text('larena-storage::admin.section.indexed_hint', 'Needs a maximum length of at most 255')));
        }

        return $options;
    }

    /** A string whose maximum length a field index takes. @param array<string,mixed> $field */
    private static function indexable(array $field): bool
    {
        return PropertyTypeSettingsCatalog::builtIns()->indexableField($field);
    }

    /** Whether a type is searched unless the field says otherwise (Property: searchable). */
    private static function searchable(string $type): bool
    {
        return PropertyTypeSettingsCatalog::builtIns()->can($type, 'searchable');
    }

    /** Whether a type may take an index on request (Property: indexable). */
    private static function indexedType(string $type): bool
    {
        return PropertyTypeSettingsCatalog::builtIns()->can($type, 'indexable');
    }

    /**
     * What the storage's change takes from the panel's values: the label, type, «required» and «hidden»; the settings
     * of the field's type (a new or retyped field: of the type chosen) under `settings`, each read as its kind, null
     * for one left empty, a locked one of an existing field never; the options as typed; the search and index options
     * for a field that can take them (searched for a string or text, indexed for a string kept as it is whose maximum
     * length an index takes); «Шифровать» from a person who may manage encryption. A setting that cannot be read as
     * its kind refuses the change under that setting (`refused`).
     *
     * @param array<string,mixed> $values @param array<string,mixed>|null $current
     * @return array<string,mixed>
     */
    private function candidate(array $values, ?array $current): array
    {
        $candidate = [];
        foreach (['label', 'type'] as $key) {
            if (is_string($values[$key] ?? null)) {
                $candidate[$key] = $values[$key];
            }
        }
        foreach (['required', 'hidden'] as $key) {
            if (array_key_exists($key, $values)) {
                $candidate[$key] = self::flag($values[$key]);
            }
        }
        $type = is_string($values['type'] ?? null) ? $values['type'] : (string) ($current['type'] ?? '');
        $kept = is_array($current) && $type === ($current['type'] ?? null);
        $declared = $kept ? self::declared($current) : PropertyTypeSettingsCatalog::builtIns()->latest($type);
        $editor = self::editorSettings();
        $settings = [];
        foreach ($declared->settings ?? [] as $setting) {
            $key = self::editorKey($setting, !isset($editor[$setting->key]) || $editor[$setting->key]['setting']->kind !== $setting->kind);
            // A locked setting is given when the field is made (or retyped), never changed afterwards.
            if ($kept && $setting->afterCreation === Setting::AFTER_CREATION_LOCKED) {
                continue;
            }
            // An existing field's form holds every setting of its type: one missing was emptied.
            if (!array_key_exists($key, $values) && !$kept) {
                continue;
            }
            if ($setting->kind === Kind::Options) {
                if (is_string($values[$key] ?? null) && trim($values[$key]) !== '') {
                    $candidate[self::OPTIONS_FIELD] = $values[$key];
                }
                continue;
            }
            try {
                $settings[$setting->key] = self::settingValue($setting, $values[$key] ?? null);
            } catch (\InvalidArgumentException) {
                return ['refused' => ['status' => 'rejected', 'reason_code' => self::SETTING_INVALID, 'field' => $key]];
            }
        }
        if ($settings !== []) {
            $candidate['settings'] = $settings;
        }
        if (array_key_exists('searchable', $values) && self::searchable($type)) {
            $candidate['searchable'] = self::flag($values['searchable']);
        }
        // An index is open to a string whose maximum length it takes, as stored or as this same change sets it.
        $lengthNow = array_key_exists('max_length', $settings) ? ['type' => $type, 'constraints' => ['max_length' => $settings['max_length']]] : $current;
        if (array_key_exists('indexed', $values) && $kept && is_array($lengthNow) && self::indexable($lengthNow)) {
            $candidate['indexed'] = self::flag($values['indexed']);
        }
        if (array_key_exists('localized', $values) && in_array($type, self::LOCALIZED_TYPES, true)) {
            $candidate['localized'] = self::flag($values['localized']);
        }
        // «Шифровать» goes only from a person who may manage encryption; an encrypted field is neither searched nor
        // indexed, whatever the form says (the storage's change enforces the same).
        if (array_key_exists('encrypted', $values) && $this->manage) {
            $candidate['encrypted'] = self::flag($values['encrypted']);
        }
        if (($candidate['encrypted'] ?? (is_array($current) && StorageEncryptedFields::isEncrypted($current))) === true) {
            unset($candidate['searchable'], $candidate['indexed']);
        }

        return $candidate;
    }

    /**
     * One setting's value as its kind takes it, null when it is left empty (PropertyTypeSettingsForm::value()).
     *
     * @throws \InvalidArgumentException when the value cannot be read as the setting's kind or Property refuses it
     */
    private static function settingValue(Setting $setting, mixed $raw): mixed
    {
        return PropertyTypeSettingsForm::value($setting, $raw, self::SETTING_INVALID);
    }

    /**
     * @param array<string,mixed> $structure
     * @param list<array<string,mixed>> $changes
     * @return array<string,mixed>
     */
    private function configure(string $actor, array $structure, array $changes, bool $migration, bool $disableConfirmed = false): array
    {
        $receipt = $this->gateway->dispatch($actor, null, 'structure.configure', $this->payload($structure, $changes, $migration, $disableConfirmed));

        return ($receipt['status'] ?? null) === 'ok' ? ['status' => 'ok'] : ['status' => ($receipt['status'] ?? null) === 'unavailable' ? 'unavailable' : 'rejected',
            'reason_code' => (string) ($receipt['reason_code'] ?? 'storage_workbench_action_rejected')];
    }

    /**
     * The storage's change as the section takes it. Fields the change does not name keep their settings; a named one
     * keeps what it does not change (the search and index options stay as stored unless the change names them).
     *
     * @param array<string,mixed> $structure @param list<array<string,mixed>> $changes
     * @return array<string,mixed>
     */
    private function payload(array $structure, array $changes, bool $migration, bool $disableConfirmed): array
    {
        $current = [];
        foreach ($structure['fields'] as $field) {
            if (is_array($field) && is_string($field['key'] ?? null)) {
                $current[$field['key']] = ['key' => $field['key'], 'label' => (string) ($field['label'] ?? $field['key']),
                    'position' => (int) ($field['position'] ?? 0), 'hidden' => ($field['hidden'] ?? false) === true, 'type' => (string) ($field['type'] ?? 'string')];
            }
        }
        return [
            'structure_id' => $structure['structure_id'], 'label' => $structure['label'], 'expected_version' => $structure['version'],
            'fields' => array_map(static fn (array $change): array => $change + ($current[$change['key']] ?? []), $changes),
            'confirm_migration' => $migration ? '1' : '0',
        ] + ($disableConfirmed ? [StorageSectionGateway::ENCRYPTION_DISABLE_CONFIRMED => '1'] : []);
    }

    private function text(string $key, string $fallback): string
    {
        $text = \Illuminate\Container\Container::getInstance()->make('translator')->get($key);

        return is_string($text) && $text !== $key ? $text : $fallback;
    }
}
