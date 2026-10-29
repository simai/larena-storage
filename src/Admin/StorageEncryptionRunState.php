<?php

declare(strict_types=1);

namespace Larena\Storage\Admin;

use Illuminate\Contracts\Translation\Translator;

/**
 * What the Storage section says about a storage's encryption run (larena-specs
 * docs/architecture/2026-10-22-storage-field-encryption.md, «Turning encryption on and off», «Keys»), in the section's
 * words, from the storage as the gateway projects it (`encryption_run`, {@see StorageSectionGateway}): fields being
 * encrypted or decrypted, or the storage's key being changed, in the background with their progress; a run that
 * stopped, with its reason in plain words and «Повторить»; and Storage's refusal while a run is open. A run that
 * ended well says nothing: the fields simply are encrypted, or not.
 *
 * While a run is queued, running or failed the structure takes no other change; the field change's notice, lock and
 * refusal ({@see StorageFieldChangeState}) give way to these.
 */
final readonly class StorageEncryptionRunState
{
    public const MODES = ['encrypt', 'decrypt', 'rotate'];

    /** Storage refuses a structure change while a run is queued or running, or failed and not retried. */
    public const RUNNING = 'storage_field_encryption_running';
    public const FAILED = 'storage_field_encryption_failed';

    /** The reasons a stopped run is explained by in its own words; any other one in general words. */
    public const FAILURE_REASONS = [
        'storage_field_encryption_key_still_used',
        'storage_field_encryption_record_corrupt',
        'storage_field_encryption_interrupted',
        'storage_field_encryption_key_unavailable',
        'storage_field_encryption_unavailable',
    ];

    private const COPY = 'larena-storage::admin.section.encryption_run.';

    public function __construct(private Translator $translator)
    {
    }

    /** True while the storage's run is queued, running or failed: the structure takes no other change. @param array<string, mixed> $selected */
    public static function open(array $selected): bool
    {
        return self::run($selected) !== null;
    }

    /** True while the run is queued or running (not failed). @param array<string, mixed> $selected */
    public static function running(array $selected): bool
    {
        $run = self::run($selected);

        return $run !== null && $run['state'] !== 'failed';
    }

    /**
     * The notice the storage's pages show: the run going on, with its progress, or the run that stopped, with its
     * reason; null while no run is open.
     *
     * @param array<string, mixed> $selected
     * @return array{kind:string,tone:'info'|'danger',title:string,text:string,progress:array{value:int,max:int,label:string}|null}|null
     */
    public function notice(array $selected): ?array
    {
        $run = self::run($selected);
        if ($run === null) {
            return null;
        }
        $replace = [':fields' => $this->fieldNames($selected, $run['fields'])];
        $suffix = $run['mode'].($run['mode'] !== 'rotate' && count($run['fields']) > 1 ? '_many' : '');
        if ($run['state'] === 'failed') {
            return ['kind' => 'encryption_failed', 'tone' => 'danger', 'title' => $this->text('failed_'.$suffix, $replace),
                'text' => $this->reason($run['reason']).' '.$this->text('failed_hint'), 'progress' => null];
        }
        $done = min($run['rewritten'] + $run['skipped'], $run['total']);
        $counted = $run['total'] > 0;

        return ['kind' => 'encryption_running', 'tone' => 'info',
            'title' => $this->text(($counted ? 'running_' : 'start_').$suffix, $replace + [':done' => self::number($done), ':total' => self::number($run['total'])]),
            'text' => $this->text('started_'.$run['mode']),
            'progress' => $counted ? ['value' => $done, 'max' => $run['total'], 'label' => $this->text('progress')] : null];
    }

    /** Why a run stopped, in plain words: a known reason in its own, any other in general ones. */
    public function reason(?string $reason): string
    {
        return $this->text('reasons.'.(in_array($reason, self::FAILURE_REASONS, true) ? $reason : 'other'));
    }

    /** What the structure editor says while a run is open; null while none is. @param array<string, mixed> $selected */
    public function locked(array $selected): ?string
    {
        $run = self::run($selected);

        return $run === null ? null : $this->text($run['state'] === 'failed' ? 'locked_failed' : 'locked');
    }

    /**
     * Storage's refusal while a run is open, naming what it does; null for another reason.
     *
     * @param array<string, mixed>|null $selected The storage as it is now, when the page could read it.
     */
    public function refusal(string $reason, ?array $selected): ?string
    {
        if ($reason !== self::RUNNING && $reason !== self::FAILED) {
            return null;
        }
        $run = $selected === null ? null : self::run($selected);
        if ($reason === self::FAILED || ($run !== null && $run['state'] === 'failed')) {
            return $this->text('refused_failed');
        }
        if ($run === null || ($run['mode'] !== 'rotate' && $run['fields'] === [])) {
            return $this->text('refused_unnamed');
        }
        return $this->text('refused_'.$run['mode'].($run['mode'] !== 'rotate' && count($run['fields']) > 1 ? '_many' : ''),
            [':fields' => $this->fieldNames($selected, $run['fields'])]);
    }

    /**
     * «Повторить» of the stopped run's notice; null unless the run failed.
     *
     * @param array<string, mixed> $selected
     * @return array{label:string}|null
     */
    public function retryOffer(array $selected): ?array
    {
        $run = self::run($selected);

        return $run !== null && $run['state'] === 'failed' ? ['label' => $this->text('retry')] : null;
    }

    /** What a save, a key change or a retry that started a run in the background tells the person. @param array<string, mixed>|null $selected */
    public function started(?array $selected): string
    {
        $run = $selected === null ? null : self::run($selected);

        return $this->text('started_'.($run['mode'] ?? 'encrypt'));
    }

    /** What a retry tells the person when the run ended in the same request. */
    public function retryDone(): string
    {
        return $this->text('retry_done');
    }

    /**
     * The fields the open run encrypts or decrypts, each with the word its row shows («шифруется…»,
     * «расшифровывается…»); a key change marks every field it re-encrypts.
     *
     * @param array<string, mixed> $selected
     * @return array<string, string>
     */
    public function fieldMarks(array $selected): array
    {
        $run = self::run($selected);
        if ($run === null || $run['state'] === 'failed') {
            return [];
        }

        return array_fill_keys($run['fields'], $this->text('mark_'.$run['mode']));
    }

    /**
     * The open run as the gateway projects it; null when there is none or it is done.
     *
     * @param array<string, mixed> $selected
     * @return array{mode:string,state:string,fields:list<string>,rewritten:int,skipped:int,total:int,reason:?string}|null
     */
    private static function run(array $selected): ?array
    {
        $run = $selected['encryption_run'] ?? null;
        if (!is_array($run) || !in_array($run['state'] ?? null, ['queued', 'running', 'failed'], true)
            || !in_array($run['mode'] ?? null, self::MODES, true)) {
            return null;
        }

        return ['mode' => (string) $run['mode'], 'state' => (string) $run['state'],
            'fields' => array_values(array_filter(is_array($run['fields'] ?? null) ? $run['fields'] : [], 'is_string')),
            'rewritten' => max(0, (int) ($run['rewritten'] ?? 0)), 'skipped' => max(0, (int) ($run['skipped'] ?? 0)),
            'total' => max(0, (int) ($run['total'] ?? 0)), 'reason' => is_string($run['reason'] ?? null) ? $run['reason'] : null];
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

    private static function number(int $value): string
    {
        return number_format($value, 0, ',', "\u{00A0}");
    }

    /** @param array<string, string> $replace */
    private function text(string $key, array $replace = []): string
    {
        $text = $this->translator->get(self::COPY.$key);

        return strtr(is_string($text) ? $text : '', $replace);
    }
}
