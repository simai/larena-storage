<?php

declare(strict_types=1);

namespace Larena\Storage\Admin\Runtime;

use Illuminate\Contracts\Translation\Translator;
use Larena\Admin\Runtime\AdminControls;
use Larena\Admin\Runtime\AdminElement;
use Larena\Admin\Runtime\AdminElementDocumentRenderer;
use Larena\Admin\Runtime\AdminSfElement;
use Larena\Admin\Runtime\AdminText;

/**
 * «Перенос на другой сайт» on a storage's settings page: what the export carries, «Выгрузить хранилище», and the
 * person's latest exports of the storage with their state, phase, progress (n of N), what they hold (records,
 * files, size), the reason in plain words and «Отменить», «Повторить», «Скачать». Everything the page shows is made
 * here; every action is a CSRF form posting to the section's SitePack routes, and the download is a link to the
 * person's own archive.
 *
 * A storage with encrypted fields says that their values stay out of the pack; a person who may read them (decrypt
 * and export) is offered «Включить зашифрованные поля в открытом виде» with its warning.
 */
final readonly class StorageSitePackPanel
{
    private const array TONES = [
        'queued' => 'info', 'running' => 'info', 'done' => 'success', 'failed' => 'danger', 'cancelled' => 'warning',
    ];
    private const string COPY = 'larena-storage::admin.section.sitepack.';

    public function __construct(private Translator $translator)
    {
    }

    /**
     * @param list<array<string, mixed>> $runs the person's exports of this storage (StorageSitePackExports::runs())
     * @param string $exportPath where «Выгрузить хранилище» posts
     * @param string $runsPath the section's SitePack runs path; a run's actions are `<runsPath>/<ref>/<action>`
     * @param bool $encrypted the storage has encrypted fields (their values stay out of the pack)
     * @param bool $plaintext offer «Включить зашифрованные поля в открытом виде» (decrypt and export, Storage takes it)
     */
    public function render(array $runs, string $exportPath, string $runsPath, string $token, bool $encrypted = false, bool $plaintext = false): string
    {
        return (string) (new AdminElementDocumentRenderer())->tree($this->describe($runs, $exportPath, $runsPath, $token, $encrypted, $plaintext));
    }

    /**
     * The panel before any markup (developer remark 3', stage 3), drawn by the shared element Document renderer.
     *
     * @param list<array<string, mixed>> $runs
     */
    public function describe(array $runs, string $exportPath, string $runsPath, string $token, bool $encrypted = false, bool $plaintext = false): AdminElement
    {
        $parts = [
            new AdminElement('h2', ['id' => 'storage-sitepack-title'], $this->copy('title')),
            new AdminElement('p', [], $this->copy('text')),
        ];
        if ($encrypted) {
            $parts[] = new AdminElement('p', ['class' => 'larena-muted', 'data-larena-storage-sitepack-encrypted' => null], $this->copy('encrypted_left_out'));
        }
        $parts[] = new AdminElement('form', ['method' => 'post', 'action' => $exportPath, 'data-larena-storage-sitepack-export' => null], [
            new AdminElement('input', ['type' => 'hidden', 'name' => '_token', 'value' => $token]),
            ...($encrypted && $plaintext ? [$this->plaintextOption()] : []),
            AdminControls::submitNode($this->copy('start'), 'primary'),
        ]);
        if ($runs !== []) {
            $parts[] = new AdminElement('h3', [], $this->copy('runs'));
            $parts[] = new AdminElement('ul', ['class' => 'larena-storage-jobs__list', 'data-larena-storage-sitepack-runs' => null],
                array_map(fn (array $run): AdminElement => $this->item($run, $runsPath, $token), $runs));
        }

        return new AdminElement('section', ['class' => 'larena-panel larena-storage-sitepack', 'aria-labelledby' => 'storage-sitepack-title',
            'data-larena-storage-sitepack' => null], $parts);
    }

    /** «Включить зашифрованные поля в открытом виде», unticked, with the warning of what the archive then holds. */
    private function plaintextOption(): AdminElement
    {
        // A Framework checkbox posts its plain name, as the export route reads it.
        // The warning stands in the page's own box, so the page's stylesheet shows it by the ticked box
        // without reaching into a Framework tag.
        return new AdminElement('div', ['class' => 'larena-storage-sitepack__plaintext', 'data-larena-storage-sitepack-plaintext' => null], [
            AdminControls::checkboxNode('storage-sitepack-plaintext', 'include_encrypted_plaintext', $this->copy('plaintext_label')),
            new AdminElement('div', ['class' => 'larena-storage-sitepack__plaintext-warning', 'data-larena-storage-sitepack-plaintext-warning' => null], [
                AdminSfElement::alert($this->copy('plaintext_warning'), 'warning'),
            ]),
        ]);
    }

    /** A refusal or a run's reason in plain words; an unknown code gets the general sentence. */
    public function reason(string $code): string
    {
        $key = self::COPY.'reasons.'.$code;
        $text = $this->translator->get($key);

        return is_string($text) && $text !== $key ? $text : $this->copy('reasons.other');
    }

    /** @param array<string, mixed> $run */
    private function item(array $run, string $runsPath, string $token): AdminElement
    {
        $ref = is_string($run['ref'] ?? null) ? $run['ref'] : '';
        $state = is_string($run['state'] ?? null) ? $run['state'] : 'queued';
        $phase = is_string($run['phase'] ?? null) ? $run['phase'] : 'prepare';
        $done = (int) ($run['done'] ?? 0);
        $total = (int) ($run['total'] ?? 0);
        $base = $runsPath.'/'.rawurlencode($ref);
        $actions = [];
        if (($run['can_download'] ?? false) === true) {
            $actions[] = new AdminElement('a', ['class' => 'larena-storage-jobs__download', 'href' => $base.'/download', 'download' => null,
                'data-larena-storage-sitepack-download' => null], $this->copy('download'));
        }
        if (($run['can_retry'] ?? false) === true) {
            $actions[] = self::form($base.'/retry', $token, $this->copy('retry'), 'secondary');
        }
        if (($run['can_cancel'] ?? false) === true) {
            $actions[] = self::form($base.'/cancel', $token, $this->copy('cancel'), 'danger', $this->copy('cancel_question'));
        }
        $reason = is_string($run['reason'] ?? null) && $run['reason'] !== '' ? $this->reason($run['reason']) : null;
        $progress = $done === 0 && $total === 0
            ? $this->copy('progress_waiting')
            : $this->text('progress', ['done' => self::number($done), 'total' => self::number($total)]);

        return new AdminElement('li', ['class' => 'larena-storage-jobs__item', 'data-larena-storage-sitepack-run' => $ref,
            'data-larena-job-tone' => self::TONES[$state] ?? 'info'], [
            new AdminElement('p', ['class' => 'larena-storage-jobs__head'], [
                new AdminElement('strong', [], $this->copy('kind_export')),
                new AdminText(' · '.self::when(is_string($run['created_at'] ?? null) ? $run['created_at'] : '').' · '),
                new AdminElement('span', ['data-larena-job-state' => null], $this->copy('state.'.$state)),
                ...($state === 'done' ? [] : [new AdminText(' · '), new AdminElement('span', ['data-larena-job-phase' => null], $this->copy('phase.'.$phase))]),
            ]),
            new AdminElement('p', ['data-larena-job-progress' => null], $progress),
            new AdminElement('p', ['data-larena-job-counts' => null], $this->text('counts', [
                'records' => self::number((int) ($run['records'] ?? 0)),
                'files' => self::number((int) ($run['files'] ?? 0)),
                'size' => $this->size((int) ($run['bytes'] ?? 0)),
            ])),
            ...($reason === null ? [] : [new AdminElement('p', ['data-larena-job-reason' => null], $reason)]),
            ...($actions === [] ? [] : [new AdminElement('div', ['class' => 'larena-storage-jobs__actions'], $actions)]),
        ]);
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

    private static function form(string $action, string $token, string $text, string $scheme, string $question = ''): AdminElement
    {
        return new AdminElement('form', ['method' => 'post', 'action' => $action, 'class' => 'larena-storage-jobs__action']
            + ($question === '' ? [] : ['data-larena-confirm-submit' => $question]), [
            new AdminElement('input', ['type' => 'hidden', 'name' => '_token', 'value' => $token]),
            AdminControls::submitNode($text, $scheme),
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
