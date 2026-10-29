<?php

declare(strict_types=1);

namespace Larena\Storage\Admin\Runtime;

use Illuminate\Contracts\Translation\Translator;
use Larena\Admin\Runtime\AdminControls;
use Larena\Admin\Runtime\AdminElement;
use Larena\Admin\Runtime\AdminText;
use Larena\Admin\Runtime\AdminNode;

/**
 * The jobs panel of a storage: the person's imports and exports with their state, progress (n of N), errors,
 * and the actions they may take — «Повторить», «Отменить», «Скачать», and for an import the queue checked,
 * «Применить» with a link to its plan on the import page. Everything the page shows is made here: items() gives
 * each job's texts and its actions as described parts (AdminNode), describe() the panel as a typed tree that the
 * shared element Document renderer draws (developer remark 3', stage 5); each action is a CSRF form posting to the
 * section's own job routes.
 */
final readonly class StorageJobsPanel
{
    private const TONES = [
        'queued' => 'info', 'waiting' => 'info', 'running' => 'info', 'retrying' => 'info', 'cancelling' => 'warning',
        'completed' => 'success', 'failed' => 'danger', 'cancelled' => 'warning', 'missing' => 'warning',
    ];
    private const SHOWN_ERRORS = 20;

    public function __construct(private Translator $translator)
    {
    }

    /**
     * @param list<array{ref: string, kind: string, stage?: string, state: string, created_at: string, done: int, total: int, total_exact: bool,
     *     created: int, updated: int, failed: int, errors: list<array{line: int, column: string, reason: string}>, reason: ?string,
     *     plan?: ?array{counts: array{create: int, update: int, same: int}, error_count: int, digest: string},
     *     can_retry: bool, can_cancel: bool, can_download: bool, can_apply?: bool}> $jobs
     * @param bool $mayImport whether the person may still import here: an import's retry, cancel and apply need it
     * @param string $importPath the storage's import page, where a checked import shows its plan
     * @return list<array{ref: string, title: string, when: string, state: string, tone: string, progress: string, summary: ?string,
     *     reason: ?string, errors: list<string>, more_errors: int, actions: list<AdminNode>}>
     */
    public function items(array $jobs, string $jobsPath, string $token, bool $mayImport, string $importPath = ''): array
    {
        $items = [];
        foreach ($jobs as $job) {
            $import = $job['kind'] === 'import';
            $check = $import && ($job['stage'] ?? 'apply') === 'check';
            $plan = $check ? ($job['plan'] ?? null) : null;
            $actions = [];
            if ($plan !== null && $importPath !== '') {
                $actions[] = new AdminElement('a', ['class' => 'larena-storage-jobs__plan', 'href' => $importPath.'?job='.$job['ref']], $this->copy('open_plan'));
            }
            if ($plan !== null && $mayImport && ($job['can_apply'] ?? false) === true) {
                $actions[] = self::form($jobsPath.'/'.$job['ref'].'/apply', $token, $this->copy('apply'), 'primary', ['digest' => $plan['digest']]);
            }
            if ($job['can_download']) {
                $actions[] = new AdminElement('a', ['class' => 'larena-storage-jobs__download', 'href' => $jobsPath.'/'.$job['ref'].'/download', 'download' => null],
                    $this->copy('download'));
            }
            if (!$import || $mayImport) {
                if ($job['can_retry']) {
                    $actions[] = self::form($jobsPath.'/'.$job['ref'].'/retry', $token, $this->copy('retry'), 'secondary');
                }
                if ($job['can_cancel']) {
                    $actions[] = self::form($jobsPath.'/'.$job['ref'].'/cancel', $token, $this->copy('cancel'), 'danger');
                }
            }
            $errors = [];
            foreach (array_slice($job['errors'], 0, self::SHOWN_ERRORS) as $error) {
                $errors[] = (string) $this->translator->get('larena-storage::admin.section.jobs.error_line', [
                    'line' => $error['line'] > 0 ? (string) $error['line'] : '—',
                    'column' => $error['column'] !== '' ? $error['column'] : '—',
                    'reason' => $this->reason('larena-storage::admin.section.import.reasons.'.$error['reason']),
                ]);
            }
            // A check that runs says «Проверяется…»; a finished one «Проверено», with what its plan would do.
            $state = $check && in_array($job['state'], ['running', 'completed'], true) ? ($job['state'] === 'running' ? 'checking' : 'checked') : $job['state'];
            $items[] = [
                'ref' => $job['ref'],
                'title' => $this->copy($check ? 'kind_import_check' : ($import ? 'kind_import' : 'kind_export')),
                'when' => self::when($job['created_at']),
                'state' => $this->copy('state.'.$state),
                'tone' => self::TONES[$job['state']] ?? 'info',
                'progress' => $this->progress($job),
                'summary' => $plan !== null ? (string) $this->translator->get('larena-storage::admin.section.jobs.plan_summary', [
                    'create' => $plan['counts']['create'], 'update' => $plan['counts']['update'], 'same' => $plan['counts']['same'], 'errors' => $plan['error_count']])
                    : ($import && ($job['created'] + $job['updated'] + $job['failed']) > 0
                    ? (string) $this->translator->get('larena-storage::admin.section.jobs.import_summary',
                        ['create' => $job['created'], 'update' => $job['updated'], 'failed' => $job['failed']])
                    : null),
                'reason' => $job['reason'] === null ? null : $this->reason('larena-storage::admin.section.jobs.reasons.'.$job['reason'],
                    'larena-storage::admin.section.jobs.reasons.other'),
                'errors' => $errors,
                'more_errors' => max(0, max($job['failed'], count($job['errors'])) - count($errors)),
                'actions' => $actions,
            ];
        }

        return $items;
    }

    /** @param array{kind: string, stage?: string, done: int, total: int, total_exact: bool} $job */
    private function progress(array $job): string
    {
        // Nothing counted yet: the job has not started, so «0 of 0» would say nothing.
        if ($job['done'] === 0 && $job['total'] === 0) {
            return $this->copy('progress_waiting');
        }
        $total = $job['total_exact'] ? self::number($job['total']) : '~'.self::number($job['total']);

        $key = $job['kind'] !== 'import' ? 'progress_records' : (($job['stage'] ?? 'apply') === 'check' ? 'progress_checked' : 'progress_rows');

        return (string) $this->translator->get('larena-storage::admin.section.jobs.'.$key,
            ['done' => self::number($job['done']), 'total' => $total]);
    }

    private function copy(string $key): string
    {
        return (string) $this->translator->get('larena-storage::admin.section.jobs.'.$key);
    }

    /** A known reason's text, or the fallback's when the key has none. */
    private function reason(string $key, ?string $fallback = null): string
    {
        $text = $this->translator->get($key);

        return is_string($text) && $text !== $key ? $text : ($fallback === null ? $key : (string) $this->translator->get($fallback));
    }

    /**
     * The panel as a typed tree: the person's jobs, each with its state, progress, summary, errors and actions.
     *
     * @param list<array{ref: string, title: string, when: string, state: string, tone: string, progress: string, summary: ?string,
     *     reason: ?string, errors: list<string>, more_errors: int, actions: list<AdminNode>}> $items items()
     */
    public function describe(array $items): AdminElement
    {
        $content = [new AdminElement('h2', ['id' => 'storage-jobs-title'], $this->copy('panel'))];
        if ($items === []) {
            $content[] = new AdminElement('p', [], $this->copy('empty'));
        } else {
            $list = [];
            foreach ($items as $item) {
                $parts = [
                    new AdminElement('p', ['class' => 'larena-storage-jobs__head'], [
                        new AdminElement('strong', [], $item['title']),
                        new AdminText(' · '.$item['when'].' · '),
                        new AdminElement('span', ['data-larena-job-state' => null], $item['state']),
                    ]),
                    new AdminElement('p', ['data-larena-job-progress' => null], $item['progress']),
                ];
                if ($item['summary'] !== null) {
                    $parts[] = new AdminElement('p', [], $item['summary']);
                }
                if ($item['reason'] !== null) {
                    $parts[] = new AdminElement('p', ['data-larena-job-reason' => null], $item['reason']);
                }
                if ($item['errors'] !== []) {
                    $parts[] = new AdminElement('ul', ['class' => 'larena-storage-jobs__errors', 'data-larena-job-errors' => null],
                        array_map(static fn (string $error): AdminElement => new AdminElement('li', [], $error), $item['errors']));
                    if ($item['more_errors'] > 0) {
                        $parts[] = new AdminElement('p', [], (string) $this->translator->get('larena-storage::admin.section.import.more_errors', ['count' => $item['more_errors']]));
                    }
                }
                if ($item['actions'] !== []) {
                    $parts[] = self::actions($item['actions']);
                }
                $list[] = new AdminElement('li', ['class' => 'larena-storage-jobs__item', 'data-larena-storage-job' => $item['ref'], 'data-larena-job-tone' => $item['tone']], $parts);
            }
            $content[] = new AdminElement('ul', ['class' => 'larena-storage-jobs__list'], $list);
        }

        return new AdminElement('section', ['class' => 'larena-panel larena-storage-jobs', 'aria-labelledby' => 'storage-jobs-title', 'data-larena-storage-jobs' => null], $content);
    }

    /** A job's actions in their row. @param list<AdminNode> $actions */
    public static function actions(array $actions): AdminElement
    {
        return new AdminElement('div', ['class' => 'larena-storage-jobs__actions'], $actions);
    }

    /** @param array<string, string> $fields */
    private static function form(string $action, string $token, string $text, string $scheme, array $fields = []): AdminElement
    {
        return new AdminElement('form', ['method' => 'post', 'action' => $action, 'class' => 'larena-storage-jobs__action'], [
            new AdminElement('input', ['type' => 'hidden', 'name' => '_token', 'value' => $token]),
            ...AdminElement::hiddenInputs($fields),
            AdminControls::submitNode($text, in_array($scheme, ['danger', 'primary'], true) ? $scheme : 'secondary'),
        ]);
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
