<?php

declare(strict_types=1);

namespace Larena\Storage\Admin\Runtime;

use Illuminate\Contracts\Translation\Translator;
use Larena\Admin\Runtime\AdminControls;
use Larena\Admin\Runtime\AdminElement;
use Larena\Admin\Runtime\AdminElementDocumentRenderer;
use Larena\Admin\Runtime\AdminSfElement;

/**
 * «Шифрование» on a storage's settings page, for a person with storage.encryption.manage (larena-specs
 * docs/architecture/2026-10-22-storage-field-encryption.md, «Keys»): the state of the storage's key in plain words
 * ({@see \Larena\Storage\Admin\StorageEncryptionKeyStatus}) and «Сменить ключ шифрования», which asks
 * first and posts to the section's key-change route. While the storage takes no change (a field change or an
 * encryption run is open) the button gives way to a note. Everything the page shows is made here.
 */
final readonly class StorageEncryptionPanel
{
    private const array TONES = [
        'ok' => 'success', 'none' => 'info', 'warning' => 'warning', 'key_missing' => 'danger', 'unavailable' => 'danger',
    ];
    private const string COPY = 'larena-storage::admin.section.encryption_keys.';

    public function __construct(private Translator $translator)
    {
    }

    /**
     * @param array{state: string, rotatable: bool} $status
     * @param string $rotatePath where «Сменить ключ шифрования» posts
     * @param bool $locked the storage takes no change now
     */
    public function render(array $status, string $rotatePath, string $token, bool $locked): string
    {
        return (string) (new AdminElementDocumentRenderer())->tree($this->describe($status, $rotatePath, $token, $locked));
    }

    /**
     * The panel before any markup (developer remark 3', stage 3), drawn by the shared element Document renderer.
     *
     * @param array{state: string, rotatable: bool} $status
     */
    public function describe(array $status, string $rotatePath, string $token, bool $locked): AdminElement
    {
        $state = isset(self::TONES[$status['state']]) ? $status['state'] : 'unavailable';
        $parts = [
            new AdminElement('h2', ['id' => 'storage-encryption-title'], $this->copy('title')),
            new AdminElement('p', [], $this->copy('text')),
            new AdminElement('div', ['role' => 'status', 'data-larena-storage-encryption-key' => null], [AdminSfElement::alert($this->copy($state), self::TONES[$state])]),
        ];
        if ($status['rotatable']) {
            $parts[] = new AdminElement('p', [], $this->copy('rotate_text'));
            $parts[] = $locked
                ? new AdminElement('p', ['class' => 'larena-muted', 'data-larena-storage-encryption-rotate-locked' => null], $this->copy('rotate_locked'))
                : new AdminElement('form', ['method' => 'post', 'action' => $rotatePath, 'data-larena-storage-encryption-rotate' => null,
                    'data-larena-confirm-submit' => $this->copy('rotate_question')], [
                    new AdminElement('input', ['type' => 'hidden', 'name' => '_token', 'value' => $token]),
                    // The question the page asks before posting stands for the confirmation.
                    new AdminElement('input', ['type' => 'hidden', 'name' => 'confirmed', 'value' => '1']),
                    AdminControls::submitNode($this->copy('rotate'), 'danger'),
                ]);
        }

        return new AdminElement('section', ['class' => 'larena-panel larena-storage-encryption', 'aria-labelledby' => 'storage-encryption-title',
            'data-larena-storage-encryption' => $state], $parts);
    }

    private function copy(string $key): string
    {
        return (string) $this->translator->get(self::COPY.$key);
    }
}
