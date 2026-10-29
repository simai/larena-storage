<?php

declare(strict_types=1);

namespace Larena\Storage\Admin\Runtime;

use Illuminate\Contracts\Translation\Translator;
use Larena\Admin\Runtime\AdminElement;
use Larena\Admin\Runtime\AdminFrameworkForm;
use Larena\Admin\Runtime\AdminRenderedPart;
use Larena\Admin\Runtime\AdminSfElement;
use Larena\Admin\Runtime\AdminText;
use Larena\Admin\Runtime\AdminNode;

/**
 * A storage's CSV import page as described parts (developer remark 3', stage 5): the refusal of a write, the upload with
 * its rules, a file the queue checks (its state, progress and actions), the plan (counts, columns, the first errors, or
 * «Применить» bound to the plan's digest) and the person's jobs here. StorageSectionController gathers the values; this
 * class describes them and AdminElementDocumentRenderer::tree() draws them; the Blade host prints the result with {{ }}.
 * The Framework controls (the submits and the form's name list) are drawn by AdminFrameworkForm, as on every form page.
 */
final readonly class StorageImportPage
{
    private const string COPY = 'larena-storage::admin.section.';

    public function __construct(private Translator $translator)
    {
    }

    /**
     * @param array{
     *     upload_url: string, token: string, records_path: string, jobs_path: ?string, form: AdminFrameworkForm,
     *     error: ?string, queued: bool,
     *     checking: ?array{ref: string, title: string, text: string, state: string, progress: string, reason: ?string, actions: list<AdminNode>, refresh: string, refresh_text: string},
     *     plan: ?array{counts: array{create: int, update: int, same: int}, columns: list<array{header: string, field: ?string, skipped: ?string}>,
     *         errors: list<array{line: int, column: string, reason: string}>, error_count: int, more_errors: int},
     *     apply: ?array{action: string, fields: array<string, string>, text: string},
     *     jobs: list<array<string, mixed>>, panel: StorageJobsPanel
     * } $page
     * @return list<AdminNode>
     */
    public function describe(array $page): array
    {
        $form = $page['form'];
        $plan = $page['plan'];
        $parts = [];
        if ($page['error'] !== null) {
            $parts[] = new AdminElement('div', ['role' => 'alert'], [AdminSfElement::alert($page['error'], 'danger')]);
        }
        $parts[] = new AdminElement('section', ['class' => 'larena-panel', 'aria-labelledby' => 'storage-import-file'], [
            new AdminElement('h2', ['id' => 'storage-import-file'], $this->copy('import.file')),
            new AdminElement('p', [], $this->copy('import.intro')),
            new AdminElement('ul', ['class' => 'larena-storage-import__rules'], array_map(
                fn (string $rule): AdminElement => new AdminElement('li', [], $this->copy('import.rule_'.$rule)),
                ['id', 'columns', 'values', 'references'],
            )),
            new AdminElement('form', ['method' => 'post', 'enctype' => 'multipart/form-data', 'action' => $page['upload_url'], 'data-larena-storage-import-upload' => null], [
                self::csrf($page['token']),
                new AdminElement('label', ['class' => 'larena-storage-import__file'], [
                    new AdminElement('span', [], $this->copy('import.choose')),
                    new AdminElement('input', ['type' => 'file', 'name' => 'file', 'accept' => '.csv,text/csv,text/plain', 'required' => null]),
                ]),
                AdminRenderedPart::control($form->submit($this->copy('import.check'), $plan === null)),
                AdminRenderedPart::control($form->names()),
            ]),
        ]);

        $checking = $page['checking'];
        if ($checking !== null) {
            // A large file the queue checks; the controller made every text and action.
            $parts[] = new AdminElement('section', ['class' => 'larena-panel', 'aria-labelledby' => 'storage-import-checking',
                'data-larena-storage-import-checking' => null, 'data-larena-storage-job' => $checking['ref']], [
                new AdminElement('h2', ['id' => 'storage-import-checking'], $checking['title']),
                new AdminElement('p', [], $checking['text']),
                new AdminElement('p', [], [
                    new AdminElement('span', ['data-larena-job-state' => null], $checking['state']),
                    new AdminText(' · '),
                    new AdminElement('span', ['data-larena-job-progress' => null], $checking['progress']),
                ]),
                ...($checking['reason'] !== null ? [new AdminElement('p', ['data-larena-job-reason' => null], $checking['reason'])] : []),
                ...($checking['actions'] !== [] ? [StorageJobsPanel::actions($checking['actions'])] : []),
                new AdminElement('p', [], [new AdminElement('a', ['href' => $checking['refresh']], $checking['refresh_text'])]),
            ]);
        }

        if ($plan !== null) {
            $parts[] = $this->plan($plan, $page['apply'], $page['token'], $page['queued'], $form);
        }

        if ($page['jobs_path'] !== null && $page['jobs'] !== []) {
            $parts[] = $page['panel']->describe($page['jobs']);
        }

        $links = [];
        if ($page['jobs_path'] !== null) {
            $links[] = new AdminElement('a', ['href' => $page['jobs_path']], $this->copy('jobs.title'));
            $links[] = new AdminText(' · ');
        }
        $links[] = new AdminElement('a', ['href' => $page['records_path']], $this->copy('import.back'));
        $parts[] = new AdminElement('p', [], $links);

        return $parts;
    }

    /**
     * @param array{counts: array{create: int, update: int, same: int}, columns: list<array{header: string, field: ?string, skipped: ?string}>,
     *     errors: list<array{line: int, column: string, reason: string}>, error_count: int, more_errors: int} $plan
     * @param ?array{action: string, fields: array<string, string>, text: string} $apply
     */
    private function plan(array $plan, ?array $apply, string $token, bool $queued, AdminFrameworkForm $form): AdminElement
    {
        $count = fn (string $key, int $value): AdminElement => new AdminElement('div', [], [
            new AdminElement('dt', [], $this->copy('import.'.$key)),
            new AdminElement('dd', ['data-larena-import-count' => $key], (string) $value),
        ]);
        $content = [
            new AdminElement('h2', ['id' => 'storage-import-plan'], $this->copy('import.plan')),
            new AdminElement('dl', ['class' => 'larena-storage-import__counts'], [
                $count('create', $plan['counts']['create']),
                $count('update', $plan['counts']['update']),
                $count('same', $plan['counts']['same']),
                $count('errors', $plan['error_count']),
            ]),
        ];
        if ($plan['columns'] !== []) {
            $content[] = new AdminElement('h3', [], $this->copy('import.columns'));
            $content[] = new AdminElement('ul', ['class' => 'larena-storage-import__columns'], array_map(
                fn (array $column): AdminElement => new AdminElement('li', $column['skipped'] !== null ? ['data-larena-import-skipped' => null] : [], [
                    new AdminElement('strong', [], $column['header'] !== '' ? $column['header'] : '—'),
                    new AdminText(' → '.($column['skipped'] !== null ? $this->copy('import.skipped.'.$column['skipped']) : (string) $column['field'])),
                ]),
                $plan['columns'],
            ));
        }
        if ($plan['error_count'] > 0) {
            $content[] = AdminSfElement::alert($this->copy('import.has_errors'), 'warning');
            $cell = static fn (string $text): AdminElement => new AdminElement('td', [], $text);
            $content[] = new AdminElement('table', ['class' => 'larena-storage-import__errors', 'data-larena-import-errors' => null], [
                new AdminElement('thead', [], [new AdminElement('tr', [], array_map(
                    fn (string $key): AdminElement => new AdminElement('th', [], $this->copy('import.'.$key)),
                    ['line', 'column', 'reason'],
                ))]),
                new AdminElement('tbody', [], array_map(fn (array $error): AdminElement => new AdminElement('tr', [], [
                    $cell($error['line'] > 0 ? (string) $error['line'] : '—'),
                    $cell($error['column'] !== '' ? $error['column'] : '—'),
                    $cell($this->copy('import.reasons.'.$error['reason'])),
                ]), $plan['errors'])),
            ]);
            if ($plan['more_errors'] > 0) {
                $content[] = new AdminElement('p', [], $this->text('import.more_errors', ['count' => $plan['more_errors']]));
            }
        } elseif ($plan['counts']['create'] + $plan['counts']['update'] === 0) {
            $content[] = AdminSfElement::alert($this->copy('import.nothing'), 'info');
        } elseif ($apply !== null) {
            $content[] = new AdminElement('form', ['method' => 'post', 'action' => $apply['action'], 'data-larena-storage-import-apply' => null], [
                self::csrf($token),
                ...AdminElement::hiddenInputs($apply['fields']),
                new AdminElement('p', [], $this->text('import.apply_text', ['create' => $plan['counts']['create'], 'update' => $plan['counts']['update']])),
                // A large file is written by the queue in parts; its progress is on the jobs page.
                ...($queued ? [AdminSfElement::alert($this->copy('import.queued_note'), 'info')] : []),
                AdminRenderedPart::control($form->submit($apply['text'])),
                AdminRenderedPart::control($form->names()),
            ]);
        }

        return new AdminElement('section', ['class' => 'larena-panel', 'aria-labelledby' => 'storage-import-plan', 'data-larena-storage-import-plan' => null], $content);
    }

    /** The form's CSRF field, as Blade's @csrf writes it. */
    private static function csrf(string $token): AdminElement
    {
        return new AdminElement('input', ['type' => 'hidden', 'name' => '_token', 'value' => $token, 'autocomplete' => 'off']);
    }

    private function copy(string $key): string
    {
        return (string) $this->translator->get(self::COPY.$key);
    }

    /** @param array<string, int|string> $replace */
    private function text(string $key, array $replace): string
    {
        return (string) $this->translator->get(self::COPY.$key, $replace);
    }
}
