<?php

declare(strict_types=1);

namespace Larena\Storage\Admin;

use Illuminate\Contracts\Translation\Translator;

/**
 * What the Storage section says about a storage's field change and its витрина, in the section's
 * words, from the storage as the gateway projects it ({@see StorageSectionGateway}): a change that
 * runs in the background (which fields, how many records are checked), the last one that failed or
 * was cancelled, the fields whose list column is still being filled, and Storage's refusal while a
 * change runs. A change that ended well says nothing: the storage simply has its new fields.
 *
 * An open encryption run ({@see StorageEncryptionRunState}) speaks instead: its notice, its lock, its refusal and its
 * field marks stand where a field change's would.
 */
final readonly class StorageFieldChangeState
{
    /** The reasons a failed change is explained by in its own words; any other one in general words. */
    public const FAILURE_REASONS = [
        'storage_schema_migration_record_unconvertible',
        'storage_field_change_schema_moved',
    ];

    public function __construct(private Translator $translator)
    {
    }

    /**
     * True while a field change runs, or an encryption run is queued, running or failed: the structure takes no other
     * change and is not archived.
     *
     * @param array<string, mixed> $selected
     */
    public static function changing(array $selected): bool
    {
        $change = self::change($selected);

        return ($selected['changing'] ?? false) === true || ($change !== null && $change['state'] === 'running')
            || StorageEncryptionRunState::open($selected);
    }

    /**
     * The notice the storage's pages show: a change running (with its progress), or the last one that
     * failed or was cancelled; null when there is nothing to say.
     *
     * @param array<string, mixed> $selected
     * @return array{kind:string,tone:'info'|'warning'|'danger',title:string,text:string,progress:array{value:int,max:int,label:string}|null}|null
     */
    public function notice(array $selected): ?array
    {
        $encryption = $this->encryption()->notice($selected);
        if ($encryption !== null) {
            return $encryption;
        }
        $change = self::change($selected);
        if ($change === null) {
            return null;
        }
        $fields = $this->fieldNames($selected, $change['fields']);
        $many = count($change['fields']) > 1;
        if ($change['state'] === 'running') {
            $counted = $change['total'] > 0;

            return ['kind' => 'running', 'tone' => 'info',
                'title' => $this->text($counted ? ($many ? 'running_many' : 'running') : ($many ? 'running_start_many' : 'running_start'), [
                    ':fields' => $fields, ':checked' => self::number(min($change['checked'], $change['total'])), ':total' => self::number($change['total'])]),
                'text' => $this->text('running_hint'),
                'progress' => $counted ? ['value' => min($change['checked'], $change['total']), 'max' => $change['total'], 'label' => $this->text('progress')] : null];
        }
        if ($change['state'] === 'failed') {
            return ['kind' => 'failed', 'tone' => 'danger', 'title' => $this->text($many ? 'failed_many' : 'failed', [':fields' => $fields]),
                'text' => $this->reason($change['reason']), 'progress' => null];
        }
        if ($change['state'] === 'cancelled') {
            return ['kind' => 'cancelled', 'tone' => 'warning', 'title' => $this->text($many ? 'cancelled_many' : 'cancelled', [':fields' => $fields]),
                'text' => $this->text('cancelled_text'), 'progress' => null];
        }

        return null;
    }

    /** Why a change failed, in plain words: a known reason in its own, any other in general ones. */
    public function reason(?string $reason): string
    {
        return $this->text('reasons.'.(in_array($reason, self::FAILURE_REASONS, true) ? $reason : 'other'));
    }

    /** What the structure editor says while a change runs; null when it may be changed. @param array<string, mixed> $selected */
    public function locked(array $selected): ?string
    {
        return $this->encryption()->locked($selected) ?? (self::changing($selected) ? $this->text('locked') : null);
    }

    /**
     * Storage's refusal while a field change runs, naming the field it changes; null for another reason.
     *
     * @param array<string, mixed>|null $selected The storage as it is now, when the page could read it.
     */
    public function refusal(string $reason, ?array $selected): ?string
    {
        $encryption = $this->encryption()->refusal($reason, $selected);
        if ($encryption !== null) {
            return $encryption;
        }
        // A page opened before the run started is refused as «changing»: the run is named, not a field change.
        if ($reason === StorageSectionGateway::FIELD_CHANGE_RUNNING && $selected !== null && StorageEncryptionRunState::open($selected)) {
            return $this->encryption()->refusal(StorageEncryptionRunState::RUNNING, $selected);
        }
        if ($reason !== StorageSectionGateway::FIELD_CHANGE_RUNNING) {
            return null;
        }
        $change = $selected === null ? null : self::change($selected);
        if ($selected === null || $change === null || $change['fields'] === []) {
            return $this->text('refused_unnamed');
        }

        return $this->text(count($change['fields']) > 1 ? 'refused_many' : 'refused', [':fields' => $this->fieldNames($selected, $change['fields'])]);
    }

    /**
     * What a save that started a background change tells the person: a field change, or the encryption run it
     * started («Шифрование поля идёт в фоне — список работает как обычно»).
     *
     * @param array<string, mixed>|null $selected The storage after the save, when the page read it.
     */
    public function started(?array $selected = null): string
    {
        return $selected !== null && StorageEncryptionRunState::open($selected) ? $this->encryption()->started($selected) : $this->text('started');
    }

    /**
     * The «Отменить изменение» button of the running change's notice and the question it asks first,
     * naming the fields it changes; null when no change runs.
     *
     * @param array<string, mixed> $selected
     * @return array{label:string,question:string}|null
     */
    public function cancelOffer(array $selected): ?array
    {
        $change = self::change($selected);
        if ($change === null || $change['state'] !== 'running') {
            return null;
        }
        return ['label' => $this->text('cancel'), 'question' => $this->text(count($change['fields']) > 1 ? 'cancel_confirm_many' : 'cancel_confirm',
            [':fields' => $this->fieldNames($selected, $change['fields'])])];
    }

    /** What a cancel that stopped the change tells the person. */
    public function cancelDone(): string
    {
        return $this->text('cancel_done');
    }

    /** What a cancel tells the person when the change had already ended. */
    public function cancelEnded(): string
    {
        return $this->text('cancel_ended');
    }

    /**
     * The fields whose list column is still being filled, and those the running change converts, each
     * with the word its row shows («индексируется…», «меняется…»).
     *
     * @param array<string, mixed> $selected
     * @return array<string, string>
     */
    public function fieldMarks(array $selected): array
    {
        $marks = [];
        foreach (is_array($selected['view_pending_fields'] ?? null) ? $selected['view_pending_fields'] : [] as $key) {
            if (is_string($key)) {
                $marks[$key] = $this->text('pending');
            }
        }
        $change = self::change($selected);
        if ($change !== null && $change['state'] === 'running') {
            foreach ($change['fields'] as $key) {
                $marks[$key] = $this->text('changing');
            }
        }

        return $this->encryption()->fieldMarks($selected) + $marks;
    }

    /** The note over the field list while the storage's list still fills rows written before a change. @param array<string, mixed> $selected */
    public function backfill(array $selected): ?string
    {
        return ($selected['view_backfill_pending'] ?? false) === true ? $this->text('backfill') : null;
    }

    /** The word over the fields' marks in the list of fields. */
    public function columnLabel(): string
    {
        return $this->text('column');
    }

    /**
     * @param array<string, mixed> $selected
     * @return array{state:string,checked:int,total:int,fields:list<string>,reason:?string}|null
     */
    private static function change(array $selected): ?array
    {
        $change = $selected['field_change'] ?? null;
        if (!is_array($change) || !is_string($change['state'] ?? null)) {
            return null;
        }

        return ['state' => $change['state'], 'checked' => max(0, (int) ($change['checked'] ?? 0)), 'total' => max(0, (int) ($change['total'] ?? 0)),
            'fields' => array_values(array_filter(is_array($change['fields'] ?? null) ? $change['fields'] : [], 'is_string')),
            'reason' => is_string($change['reason'] ?? null) ? $change['reason'] : null];
    }

    /** The fields by their labels, each in «»; a field the storage no longer names, by its key. @param array<string, mixed> $selected @param list<string> $keys */
    private function fieldNames(array $selected, array $keys): string
    {
        $labels = [];
        foreach (is_array($selected['fields'] ?? null) ? $selected['fields'] : [] as $field) {
            if (is_array($field) && is_string($field['key'] ?? null)) {
                $labels[$field['key']] = is_string($field['label'] ?? null) && trim($field['label']) !== '' ? trim($field['label']) : $field['key'];
            }
        }

        return implode(', ', array_map(static fn (string $key): string => '«'.($labels[$key] ?? $key).'»', $keys));
    }

    private function encryption(): StorageEncryptionRunState
    {
        return new StorageEncryptionRunState($this->translator);
    }

    private static function number(int $value): string
    {
        return number_format($value, 0, ',', "\u{00A0}");
    }

    /** @param array<string, string> $replace */
    private function text(string $key, array $replace = []): string
    {
        $text = $this->translator->get('larena-storage::admin.section.field_change.'.$key);

        return strtr(is_string($text) ? $text : '', $replace);
    }
}
