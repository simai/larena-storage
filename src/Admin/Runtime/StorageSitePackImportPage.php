<?php

declare(strict_types=1);

namespace Larena\Storage\Admin\Runtime;

use Illuminate\Contracts\Translation\Translator;
use Larena\Admin\Runtime\AdminControls;
use Larena\Admin\Runtime\AdminElement;
use Larena\Admin\Runtime\AdminSfElement;
use Larena\Admin\Runtime\AdminText;
use Larena\Admin\Runtime\AdminNode;

/**
 * «Загрузить хранилище» (larena-specs docs/architecture/2026-10-22-storage-sitepack.md): everything the import pages
 * show, described here as typed parts (AdminNode, developer remark 3', stage 5) that the shared element Document renderer
 * draws, so the Blade view only prints the result.
 *
 * - The upload: what an archive brings, the size it may have, a .zip file and «Загрузить архив»; the person's latest
 *   imports, each a link to its page.
 * - A run's page, by its state: the check of the archive with its progress and «Отменить»; the plan (source, the
 *   storage it becomes, fields, records, languages, files, references with a local storage for each storage of the
 *   source site, roles, publication, users, warnings and errors in plain words, a replay notice with «Загрузить
 *   копией») with «Проверить снова» and «Загрузить» bound to the plan's digest; the import's progress «n из N
 *   записей» with «Отменить»; a failure's reason with «Повторить»; the end with a link to the new storage.
 *
 * Every action is a CSRF form posting to the run's routes (`<runPath>/plan|apply|cancel|retry`).
 */
final readonly class StorageSitePackImportPage
{
    private const string COPY = 'larena-storage::admin.section.sitepack_import.';
    private const array TONES = [
        'ready' => 'info', 'queued' => 'info', 'running' => 'info', 'done' => 'success', 'failed' => 'danger', 'cancelled' => 'warning',
    ];
    /** Plan issue codes whose subject names something a person recognises (a language, a storage ID, a role). */
    private const array SUBJECTS = ['locale_not_declared', 'already_imported', 'structure_id_taken', 'role_unknown', 'role_binding_skipped',
        'encrypted_values_omitted', 'encrypted_values_decrypted', 'storage_sitepack_site_pages_fields_missing', 'storage_sitepack_site_pages_scope_mismatch', 'site_domain_taken'];
    /** The value of a reference choice that leaves the storage of the same ID. */
    public const string SAME_ID = '__same__';

    public function __construct(private Translator $translator)
    {
    }

    /**
     * @param string $action where the upload posts
     * @param int $maxBytes the largest archive this server accepts
     */
    public function upload(string $action, string $token, int $maxBytes): AdminElement
    {
        return new AdminElement('section', ['class' => 'larena-panel', 'aria-labelledby' => 'sitepack-import-upload', 'data-larena-sitepack-import-upload' => null], [
            new AdminElement('h2', ['id' => 'sitepack-import-upload'], $this->copy('upload_title')),
            new AdminElement('p', [], $this->copy('intro')),
            new AdminElement('ul', ['class' => 'larena-storage-import__rules'], [
                new AdminElement('li', [], $this->copy('rule_check')),
                new AdminElement('li', [], $this->copy('rule_drafts')),
                new AdminElement('li', [], $this->copy('rule_history')),
            ]),
            new AdminElement('form', ['method' => 'post', 'enctype' => 'multipart/form-data', 'action' => $action, 'data-larena-sitepack-import-form' => null], [
                self::token($token),
                new AdminElement('label', ['class' => 'larena-storage-import__file'], [
                    new AdminElement('span', [], $this->copy('choose')),
                    new AdminElement('input', ['type' => 'file', 'name' => 'archive', 'accept' => '.zip,application/zip', 'required' => null]),
                ]),
                new AdminElement('p', ['data-larena-sitepack-import-limit' => null], $this->text('size_note', ['size' => $this->size($maxBytes)])),
                AdminControls::submitNode($this->copy('upload'), 'primary'),
            ]),
        ]);
    }

    /**
     * The person's latest imports, each a link to its page; nothing without any.
     *
     * @param list<array<string, mixed>> $runs
     */
    public function runs(array $runs, string $basePath): ?AdminElement
    {
        if ($runs === []) {
            return null;
        }
        $items = [];
        foreach ($runs as $run) {
            $ref = self::string($run['ref'] ?? null);
            $state = self::string($run['state'] ?? null, 'queued');
            $source = is_array($run['source'] ?? null) ? $run['source'] : [];
            $name = self::string($source['name'] ?? null, self::string($run['structure_id'] ?? null, $ref));
            $items[] = new AdminElement('li', ['class' => 'larena-storage-jobs__item', 'data-larena-sitepack-import-item' => $ref, 'data-larena-job-tone' => self::TONES[$state] ?? 'info'], [
                new AdminElement('p', ['class' => 'larena-storage-jobs__head'], [
                    new AdminElement('a', ['href' => $basePath.'/'.rawurlencode($ref)], $name),
                    new AdminText(' · '.self::when(self::string($run['created_at'] ?? null)).' · '),
                    new AdminElement('span', ['data-larena-job-state' => null], $this->stateText($run)),
                ]),
            ]);
        }

        return new AdminElement('section', ['class' => 'larena-panel', 'aria-labelledby' => 'sitepack-import-runs', 'data-larena-sitepack-import-runs' => null], [
            new AdminElement('h2', ['id' => 'sitepack-import-runs'], $this->copy('runs')),
            new AdminElement('ul', ['class' => 'larena-storage-jobs__list'], $items),
        ]);
    }

    /**
     * One run's page.
     *
     * @param array<string, mixed> $run StorageSitePackImports' projection
     * @param array{run_path: string, storage_path: string, token: string, can_publish: bool, structures: array<string, string>} $context
     *        run_path: this run's page; storage_path: the section, a storage's page is `<storage_path>/<id>`;
     *        structures: the storages of the scope, id => name
     */
    public function run(array $run, array $context): AdminElement
    {
        $ref = self::string($run['ref'] ?? null);
        $state = self::string($run['state'] ?? null, 'queued');
        $parts = [new AdminElement('h2', ['id' => 'sitepack-import-run'], $this->stateText($run)), ...$this->source($run)];
        $path = $context['run_path'];
        $token = $context['token'];
        if (($run['planning'] ?? false) === true) {
            array_push($parts, new AdminElement('p', [], $this->copy('planning_text')),
                $this->progress($run),
                ...$this->actions([self::form($path.'/cancel', $token, $this->copy('cancel'), 'danger', [], $this->copy('cancel_plan_question'))]));
            $parts[] = $this->refresh($path);
        } elseif (($run['active'] ?? false) === true) {
            array_push($parts, new AdminElement('p', [], $this->copy('applying_text')),
                new AdminElement('p', ['data-larena-job-phase' => null], $this->text('phase_now', ['phase' => $this->phase(self::string($run['phase'] ?? null))])),
                $this->progress($run),
                ...$this->actions([self::form($path.'/cancel', $token, $this->copy('cancel'), 'danger', [], $this->copy('cancel_question'))]));
            $parts[] = $this->refresh($path);
        } elseif ($state === 'ready' && is_array($run['plan'] ?? null)) {
            $parts[] = $this->plan($run, $run['plan'], $context);
        } elseif ($state === 'ready') {
            array_push($parts, new AdminElement('p', [], $this->copy('received_text')),
                ...$this->actions([self::form($path.'/plan', $token, $this->copy('check'), 'primary', ['publication' => 'draft'])]));
        } elseif ($state === 'failed') {
            array_push($parts, new AdminElement('div', ['role' => 'alert', 'data-larena-job-reason' => null],
                [AdminSfElement::alert($this->reason(self::string($run['reason'] ?? null, 'other')), 'danger')]),
                ...$this->kept($run),
                ...$this->actions(array_values(array_filter([
                    ($run['can_retry'] ?? false) === true ? self::form($path.'/retry', $token, $this->copy('retry'), 'primary') : null,
                    ($run['can_cancel'] ?? false) === true ? self::form($path.'/cancel', $token, $this->copy('cancel'), 'danger', [], $this->copy('cancel_question')) : null,
                ]))));
        } elseif ($state === 'cancelled') {
            array_push($parts, new AdminElement('p', [], $this->copy('cancelled_text')),
                ...$this->kept($run),
                ...$this->actions(($run['can_retry'] ?? false) === true ? [self::form($path.'/retry', $token, $this->copy('retry'), 'primary')] : []));
        } elseif ($state === 'done') {
            $counts = is_array($run['counts'] ?? null) ? $run['counts'] : [];
            $structure = self::string($run['structure_id'] ?? null);
            $parts[] = new AdminElement('p', ['data-larena-sitepack-import-done' => null], $this->text('done_text', [
                'records' => self::number((int) ($counts['records'] ?? $run['done'] ?? 0)),
                'created' => self::number((int) ($counts['files_created'] ?? 0)),
                'reused' => self::number((int) ($counts['files_reused'] ?? 0)),
            ]));
            if ($structure !== '') {
                $parts[] = new AdminElement('p', [], [new AdminElement('a', ['href' => $context['storage_path'].'/'.rawurlencode($structure), 'data-larena-sitepack-import-open' => null],
                    $this->text('open', ['storage' => $context['structures'][$structure] ?? $structure]))]);
            }
        }

        return new AdminElement('section', ['class' => 'larena-panel', 'aria-labelledby' => 'sitepack-import-run', 'data-larena-sitepack-import-run' => $ref,
            'data-larena-sitepack-import-state' => $state, 'data-larena-job-tone' => self::TONES[$state] ?? 'info'], $parts);
    }

    /** A refusal or a run's reason in plain words; an unknown code gets the general sentence, a broken archive its own. */
    public function reason(string $code): string
    {
        $key = self::COPY.'reasons.'.$code;
        $text = $this->translator->get($key);
        if (is_string($text) && $text !== $key) {
            return $text;
        }

        return $this->copy(str_starts_with($code, 'sitepack_') ? 'reasons.sitepack_archive_invalid' : 'reasons.other');
    }

    /** A plan's error or warning in plain words; a code nobody knows yet still says something. */
    public function issue(string $kind, string $code, int $count, ?string $subject): string
    {
        $key = self::COPY.$kind.'.'.$code;
        $replace = ['count' => self::number(max(1, $count)), 'subject' => in_array($code, self::SUBJECTS, true) && $subject !== null ? $subject : ''];
        $text = $this->translator->get($key, $replace);

        return is_string($text) && $text !== $key ? $text : $this->text($kind.'.other', $replace);
    }

    /**
     * @param array<string, mixed> $run
     * @param array<string, mixed> $plan StorageSitePackPlan::toArray()
     * @param array{run_path: string, storage_path: string, token: string, can_publish: bool, structures: array<string, string>} $context
     */
    private function plan(array $run, array $plan, array $context): AdminElement
    {
        $structure = is_array($plan['structure'] ?? null) ? $plan['structure'] : [];
        $records = self::ints($plan['records'] ?? null);
        $files = self::ints($plan['files'] ?? null);
        $references = self::ints($plan['references'] ?? null);
        $users = self::ints($plan['users'] ?? null);
        $publication = is_array($plan['publication'] ?? null) ? $plan['publication'] : [];
        $options = is_array($plan['options'] ?? null) ? $plan['options'] : [];
        $errors = self::issues($plan['errors'] ?? null);
        $warnings = self::issues($plan['warnings'] ?? null);
        $replay = is_array($plan['replay'] ?? null) ? $plan['replay'] : null;
        $sourceId = self::string($structure['source_id'] ?? null);
        $targetId = self::string($structure['target_id'] ?? null);
        $label = self::string($structure['label'] ?? null, $sourceId);
        $path = $context['run_path'];
        $token = $context['token'];
        $codes = array_column($errors, 'code');

        $parts = [];
        if ($replay !== null) {
            $copyFields = $this->optionFields($options) + ['copy' => '1'];
            $parts[] = new AdminElement('div', ['data-larena-sitepack-import-replay' => null], [
                AdminSfElement::alert($this->text('replay', [
                    'storage' => self::string($replay['structure_id'] ?? null),
                    'date' => self::when(self::string($replay['imported_at'] ?? null)),
                ]), 'warning'),
                ...(($options['copy'] ?? false) === true ? [new AdminElement('p', [], $this->copy('replay_copy_chosen'))]
                    : $this->actions([self::form($path.'/plan', $token, $this->copy('copy'), 'secondary', $copyFields)])),
            ]);
        }
        if ($errors !== []) {
            $parts[] = new AdminElement('div', ['role' => 'alert', 'data-larena-sitepack-import-errors' => null], [
                AdminSfElement::alert($this->copy('has_errors'), 'danger'), $this->issueList('errors', 'error', $errors)]);
        }
        if ($warnings !== []) {
            $parts[] = new AdminElement('div', ['data-larena-sitepack-import-warnings' => null], [
                AdminSfElement::alert($this->copy('has_warnings'), 'warning'), $this->issueList('warnings', 'warning', $warnings)]);
        }

        // What the import will do, section by section.
        $locales = is_array($plan['locales'] ?? null) ? array_values(array_filter($plan['locales'], 'is_string')) : [];
        $localeErrors = array_values(array_filter($errors, static fn (array $issue): bool => $issue['code'] === 'locale_not_declared'));
        $roles = is_array($plan['roles'] ?? null) ? $plan['roles'] : [];
        $domains = is_array($plan['domains'] ?? null) ? $plan['domains'] : [];
        $hosts = is_array($domains['hosts'] ?? null) ? array_values(array_filter($domains['hosts'], 'is_string')) : [];
        $parts[] = new AdminElement('dl', ['class' => 'larena-storage-import__summary', 'data-larena-sitepack-import-summary' => null], [
            self::row('target', $this->copy('target'), $targetId === ''
                ? $this->copy('target_none')
                : $this->text($targetId === $sourceId ? 'target_kept' : 'target_new', ['label' => $label, 'id' => $targetId, 'source' => $sourceId])),
            self::row('fields', $this->copy('fields'), self::number((int) ($structure['fields'] ?? 0))),
            self::row('records', $this->copy('records'), $this->text('records_text', [
                'total' => self::number(self::at($records, 'total')), 'keep' => self::number(self::at($records, 'keep')),
                'new' => self::number(self::at($records, 'new')), 'archived' => self::number(self::at($records, 'archived')),
            ])),
            self::row('languages', $this->copy('languages'), $locales === [] ? $this->copy('languages_none') : implode(', ', $locales)
                .($localeErrors === [] ? '' : ' — '.$this->text('languages_missing', ['locales' => implode(', ', array_filter(array_column($localeErrors, 'subject')))]))),
            self::row('files', $this->copy('files'), self::at($files, 'total') === 0 ? $this->copy('files_none') : $this->text('files_text', [
                'total' => self::number(self::at($files, 'total')), 'reuse' => self::number(self::at($files, 'reuse')),
                'create' => self::number(self::at($files, 'create')), 'remap' => self::number(self::at($files, 'remap')),
                'size' => $this->size(self::at($files, 'bytes')),
            ]).(self::at($files, 'missing') > 0 ? ' '.$this->text('files_missing', ['count' => self::number(self::at($files, 'missing'))]) : '')),
            self::row('references', $this->copy('references'), self::at($references, 'total') === 0 ? $this->copy('references_none') : $this->text('references_text', [
                'total' => self::number(self::at($references, 'total')), 'resolved' => self::number(self::at($references, 'resolved')),
                'unresolved' => self::number(self::at($references, 'unresolved')),
            ])),
            self::row('roles', $this->copy('roles'), $roles === [] ? $this->copy('roles_none') : implode(', ', array_map(fn (mixed $role): string => is_array($role)
                ? $this->text(($role['action'] ?? '') === 'bind' ? 'role_bind' : 'role_skip', ['role' => self::string($role['role_ref'] ?? null)]) : '', $roles))),
            self::row('publication', $this->copy('publication'), $this->text(($publication['mode'] ?? 'draft') === 'keep' ? 'publication_keep_text' : 'publication_draft_text', [
                'published' => self::number((int) ($publication['published'] ?? 0)), 'scheduled' => self::number((int) ($publication['scheduled'] ?? 0)),
            ])),
            self::row('users', $this->copy('users'), self::at($users, 'total') === 0 ? $this->copy('users_none') : $this->text('users_text', [
                'total' => self::number(self::at($users, 'total')), 'missing' => self::number(self::at($users, 'missing')),
            ])),
            ...($hosts === [] ? [] : [self::row('domains', $this->copy('domains'), $this->text('domains_text', ['hosts' => implode(', ', $hosts)]).' '
                .$this->copy(($domains['transfer'] ?? false) === true ? 'domains_transfer_on' : 'domains_transfer_off').'.')]),
        ]);

        // The person's choices, planned again by «Проверить снова».
        $editableId = in_array('structure_id_taken', $codes, true) || $targetId !== $sourceId;
        $form = [
            self::token($token),
            new AdminElement('h3', [], $this->copy('choices')),
            new AdminSfElement('sf-input', AdminControls::inputProps('sitepack-import-label', 'target_label', $this->copy('label'), $label, true, 120)),
        ];
        if ($editableId) {
            $form[] = new AdminSfElement('sf-input', AdminControls::inputProps('sitepack-import-id', 'target_structure_id', $this->copy('structure_id'),
                self::string($options['target_structure_id'] ?? null, $targetId !== '' ? $targetId : $sourceId), true, 120));
            $form[] = new AdminElement('p', [], $this->copy('structure_id_hint'));
        } elseif (is_string($options['target_structure_id'] ?? null)) {
            $form[] = self::hidden('target_structure_id', $options['target_structure_id']);
        }
        $chosen = ($options['publication'] ?? 'draft') === 'keep' ? 'keep' : 'draft';
        if ($context['can_publish']) {
            $choices = [new AdminElement('legend', [], $this->copy('publication'))];
            foreach (['draft' => 'publication_draft', 'keep' => 'publication_keep'] as $value => $text) {
                $choices[] = new AdminElement('label', [], [
                    new AdminElement('input', ['type' => 'radio', 'name' => 'publication', 'value' => $value] + ($chosen === $value ? ['checked' => null] : [])),
                    new AdminText(' '.$this->copy($text)),
                ]);
            }
            $form[] = new AdminElement('fieldset', ['class' => 'larena-sitepack-import__publication', 'data-larena-sitepack-import-publication' => null], $choices);
        } else {
            $form[] = self::hidden('publication', 'draft');
            $form[] = new AdminElement('p', [], $this->copy('publication_draft_only'));
        }
        $source = is_array($run['source'] ?? null) ? $run['source'] : [];
        $external = is_array($source['external'] ?? null) ? array_values(array_filter($source['external'], 'is_string')) : [];
        $targets = is_array($options['reference_targets'] ?? null) ? $options['reference_targets'] : [];
        if ($external !== []) {
            $referenceParts = [new AdminElement('legend', [], $this->copy('references_choose')), new AdminElement('p', [], $this->copy('references_hint'))];
            $choices = [self::SAME_ID => $this->copy('reference_same')];
            foreach ($context['structures'] as $id => $name) {
                $choices[$id] = $name.' ('.$id.')';
            }
            foreach ($external as $index => $id) {
                $current = is_string($targets[$id] ?? null) && isset($choices[$targets[$id]]) ? $targets[$id] : self::SAME_ID;
                $referenceParts[] = self::hidden('reference_from_'.$index, $id);
                $referenceParts[] = new AdminSfElement('sf-dropdown', AdminControls::selectProps('sitepack-import-reference-'.$index, 'reference_to_'.$index,
                    $this->text('reference_for', ['storage' => $id]), $choices, $current));
            }
            $form[] = new AdminElement('fieldset', ['data-larena-sitepack-import-references' => null], $referenceParts);
        } elseif (self::at($references, 'total') > 0) {
            $form[] = new AdminElement('p', [], $this->copy('references_same_only'));
            foreach (array_keys($targets) as $index => $id) {
                $form[] = self::hidden('reference_from_'.$index, (string) $id);
                $form[] = self::hidden('reference_to_'.$index, (string) $targets[$id]);
            }
        }
        if (($options['copy'] ?? false) === true) {
            $form[] = self::hidden('copy', '1');
        }
        // «Перенести домены»: off unless the person turns it on; domains usually differ at the new place.
        if ($hosts !== []) {
            $form[] = new AdminElement('fieldset', ['data-larena-sitepack-import-domains' => null], [
                new AdminElement('legend', [], $this->copy('domains')),
                new AdminElement('label', [], [
                    new AdminElement('input', ['type' => 'checkbox', 'name' => 'transfer_domains', 'value' => '1'] + (($options['transfer_domains'] ?? false) === true ? ['checked' => null] : [])),
                    new AdminText(' '.$this->copy('domains_transfer')),
                ]),
                new AdminElement('p', [], $this->copy('domains_transfer_hint')),
            ]);
        }
        $form[] = AdminControls::submitNode($this->copy('replan'), 'secondary');
        $parts[] = new AdminElement('form', ['method' => 'post', 'action' => $path.'/plan', 'data-larena-sitepack-import-options' => null], $form);

        // «Загрузить» applies exactly the plan above, by its digest.
        $digest = self::string($run['plan_digest'] ?? null);
        $apply = [self::token($token), self::hidden('digest', $digest)];
        if ($errors === [] && ($run['can_apply'] ?? false) === true && $digest !== '') {
            $apply[] = new AdminElement('p', [], $this->text('apply_text', ['label' => $label, 'id' => $targetId, 'records' => self::number(self::at($records, 'total'))]));
            $apply[] = AdminControls::submitNode($this->copy('apply'), 'primary');
        } else {
            $apply[] = new AdminElement('p', [], $this->copy('apply_blocked'));
            $apply[] = new AdminElement('div', ['class' => 'larena-form-submit'], [new AdminSfElement('sf-button', [
                'text' => $this->copy('apply'), 'scheme' => 'primary', 'type' => 'default', 'native-type' => 'submit', 'disabled' => true,
            ])]);
        }
        $parts[] = new AdminElement('form', ['method' => 'post', 'action' => $path.'/apply', 'data-larena-sitepack-import-apply' => null], $apply);
        // Nothing is written before «Загрузить»: cancelling here only drops the archive and its plan.
        array_push($parts, ...$this->actions([self::form($path.'/cancel', $token, $this->copy('cancel'), 'danger', [], $this->copy('cancel_plan_question'))]));

        return new AdminElement('div', ['data-larena-sitepack-import-plan' => null], $parts);
    }

    /**
     * A plan's errors or warnings, each sentence once: issues that read the same (one code for several fields whose
     * names the sentence does not carry) are said together.
     *
     * @param list<array{code: string, count: int, subject: ?string}> $issues
     */
    private function issueList(string $kind, string $attribute, array $issues): AdminElement
    {
        $said = [];
        foreach ($issues as $issue) {
            $text = $this->issue($kind, $issue['code'], $issue['count'], $issue['subject']);
            $said[$text] ??= $issue['code'];
        }
        $items = [];
        foreach ($said as $text => $code) {
            $items[] = new AdminElement('li', ['data-larena-sitepack-import-'.$attribute => $code], (string) $text);
        }

        return new AdminElement('ul', ['class' => 'larena-storage-import__issues'], $items);
    }

    /**
     * The current options as the fields of a form, so «Загрузить копией» keeps them.
     *
     * @param array<array-key, mixed> $options
     * @return array<string, string>
     */
    private function optionFields(array $options): array
    {
        $fields = ['publication' => ($options['publication'] ?? 'draft') === 'keep' ? 'keep' : 'draft'];
        foreach (['target_structure_id', 'target_label'] as $key) {
            if (is_string($options[$key] ?? null) && $options[$key] !== '') {
                $fields[$key] = $options[$key];
            }
        }
        if (($options['transfer_domains'] ?? false) === true) {
            $fields['transfer_domains'] = '1';
        }
        $index = 0;
        foreach (is_array($options['reference_targets'] ?? null) ? $options['reference_targets'] : [] as $from => $to) {
            if (is_string($to)) {
                $fields['reference_from_'.$index] = (string) $from;
                $fields['reference_to_'.$index] = $to;
                $index++;
            }
        }

        return $fields;
    }

    /** @param array<string, mixed> $run @return list<AdminNode> */
    private function source(array $run): array
    {
        $source = is_array($run['source'] ?? null) ? $run['source'] : [];
        $plan = is_array($run['plan'] ?? null) ? $run['plan'] : [];
        $structure = is_array($plan['structure'] ?? null) ? $plan['structure'] : [];
        $parts = [];
        if (is_string($source['name'] ?? null) && $source['name'] !== '') {
            $parts[] = $this->text('source_file', ['name' => $source['name'], 'size' => $this->size((int) ($run['bytes'] ?? 0))]);
        }
        $storage = self::string($structure['source_id'] ?? null, self::string($run['structure_id'] ?? null));
        if ($storage !== '' && ($run['state'] ?? null) !== 'done') {
            $label = self::string($source['source_label'] ?? null);
            $parts[] = $this->text('source_storage', ['storage' => $label !== '' ? $label.' ('.$storage.')' : $storage]);
        }
        if (is_string($source['source_site'] ?? null) && $source['source_site'] !== '') {
            $parts[] = $this->text('source_site', ['site' => $source['source_site']]);
        }

        return $parts === [] ? [] : [new AdminElement('p', ['data-larena-sitepack-import-source' => null], $this->copy('source').': '.implode(' · ', $parts))];
    }

    /** @param array<string, mixed> $run */
    private function progress(array $run): AdminElement
    {
        $done = (int) ($run['done'] ?? 0);
        $total = (int) ($run['total'] ?? 0);

        return new AdminElement('p', ['data-larena-job-progress' => null], $done === 0 && $total === 0
            ? $this->copy('progress_waiting')
            : $this->text('progress', ['done' => self::number($done), 'total' => self::number($total)]));
    }

    /** Files a cancelled or failed import created stay; the page says how many. @param array<string, mixed> $run @return list<AdminNode> */
    private function kept(array $run): array
    {
        $created = (int) ((is_array($run['counts'] ?? null) ? $run['counts'] : [])['files_created'] ?? 0);

        return $created > 0 ? [new AdminElement('p', ['data-larena-sitepack-import-kept' => null], $this->text('files_kept', ['count' => self::number($created)]))] : [];
    }

    private function refresh(string $path): AdminElement
    {
        return new AdminElement('p', [], [new AdminElement('a', ['href' => $path, 'data-larena-sitepack-import-refresh' => null], $this->copy('refresh'))]);
    }

    /** @param list<AdminNode> $forms @return list<AdminNode> */
    private function actions(array $forms): array
    {
        return $forms === [] ? [] : [new AdminElement('div', ['class' => 'larena-storage-jobs__actions'], $forms)];
    }

    /** What a run's state is called on its page («Проверяется…», «Загружается», «Готово»…). @param array<string, mixed> $run */
    public function stateText(array $run): string
    {
        $state = self::string($run['state'] ?? null, 'queued');
        if ($state === 'ready') {
            return $this->copy(is_array($run['plan'] ?? null) ? 'state.planned' : 'state.received');
        }
        if (($run['planning'] ?? false) === true) {
            return $this->copy('state.planning');
        }

        return $this->copy('state.'.(in_array($state, ['queued', 'running', 'done', 'failed', 'cancelled'], true) ? $state : 'queued'));
    }

    private function phase(string $phase): string
    {
        $key = self::COPY.'phase.'.$phase;
        $text = $this->translator->get($key);

        return is_string($text) && $text !== $key ? $text : $phase;
    }

    private function size(int $bytes): string
    {
        foreach ([['size_gb', 1_073_741_824], ['size_mb', 1_048_576], ['size_kb', 1024]] as [$unit, $scale]) {
            if ($bytes >= $scale) {
                return $this->text($unit, ['value' => number_format($bytes / $scale, 1, ',', "\u{00A0}")]);
            }
        }

        return $this->text('size_b', ['value' => (string) max(0, $bytes)]);
    }

    private function copy(string $key): string
    {
        return (string) $this->translator->get(self::COPY.$key);
    }

    /** @param array<string, string> $replace */
    private function text(string $key, array $replace): string
    {
        return (string) $this->translator->get(self::COPY.$key, $replace);
    }

    private static function row(string $key, string $term, string $value): AdminElement
    {
        return new AdminElement('div', ['data-larena-sitepack-import-row' => $key], [new AdminElement('dt', [], $term), new AdminElement('dd', [], $value)]);
    }

    /** @param array<string, string> $fields */
    private static function form(string $action, string $token, string $text, string $scheme, array $fields = [], string $question = ''): AdminElement
    {
        $hidden = [];
        foreach ($fields as $name => $value) {
            $hidden[] = self::hidden($name, $value);
        }

        return new AdminElement('form', ['method' => 'post', 'action' => $action, 'class' => 'larena-storage-jobs__action']
            + ($question === '' ? [] : ['data-larena-confirm-submit' => $question]), [
            self::token($token),
            ...$hidden,
            AdminControls::submitNode($text, in_array($scheme, ['primary', 'secondary', 'danger'], true) ? $scheme : 'secondary'),
        ]);
    }

    private static function token(string $token): AdminElement
    {
        return self::hidden('_token', $token);
    }

    private static function hidden(string $name, string $value): AdminElement
    {
        return new AdminElement('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
    }

    /** @return array<string, int> */
    private static function ints(mixed $map): array
    {
        $out = [];
        foreach (is_array($map) ? $map : [] as $key => $value) {
            $out[(string) $key] = is_numeric($value) ? (int) $value : 0;
        }

        return $out;
    }

    /** @param array<string, int> $map */
    private static function at(array $map, string $key): int
    {
        return $map[$key] ?? 0;
    }

    /** @return list<array{code: string, count: int, subject: ?string}> */
    private static function issues(mixed $list): array
    {
        $out = [];
        foreach (is_array($list) ? $list : [] as $issue) {
            if (is_array($issue) && is_string($issue['code'] ?? null)) {
                $out[] = ['code' => $issue['code'], 'count' => is_int($issue['count'] ?? null) ? $issue['count'] : 1,
                    'subject' => is_string($issue['subject'] ?? null) ? $issue['subject'] : null];
            }
        }

        return $out;
    }

    private static function string(mixed $value, string $default = ''): string
    {
        return is_string($value) && $value !== '' ? $value : $default;
    }

    /** A kept UTC moment in the site's time zone, «UTC» named while the site keeps UTC. */
    private static function when(string $stored): string
    {
        return \Larena\Admin\Settings\AdminSiteSettings::shown($stored);
    }

    private static function number(int $value): string
    {
        return number_format($value, 0, ',', "\u{00A0}");
    }
}
