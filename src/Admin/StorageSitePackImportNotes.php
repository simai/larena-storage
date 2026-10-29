<?php

declare(strict_types=1);

namespace Larena\Storage\Admin;

use InvalidArgumentException;

/**
 * What the import page keeps of a pack a person uploaded, beside Storage's run: the file's name, the site it came
 * from and the storages of that site its records point at (so the page can offer a local storage for each). It is
 * written once, when the archive is received, into a private directory per person, and read by that person only.
 * A note is named by the run's ref, never by anything a request sends, and it is swept with the archives.
 */
final readonly class StorageSitePackImportNotes
{
    private const string RUN = '/\Astorage-sitepack-([0-9a-f]{32})\z/D';
    /** A note outlives the archive Storage keeps for a few days. */
    private const int TTL = 14 * 86_400;
    public const int MAX_EXTERNAL = 100;

    public function __construct(private string $root)
    {
        if (trim($root) === '') {
            throw new InvalidArgumentException('storage_sitepack_import_notes_root_missing');
        }
    }

    /**
     * @param array{name: string, source_site: ?string, source_label: ?string, external: list<string>} $note
     */
    public function keep(string $actor, string $runRef, array $note): bool
    {
        $path = $this->path($actor, $runRef);
        if ($path === null) {
            return false;
        }
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            return false;
        }
        $this->sweep($directory);
        $json = json_encode([
            'name' => mb_substr($note['name'], 0, 255),
            'source_site' => $note['source_site'],
            'source_label' => $note['source_label'] === null ? null : mb_substr($note['source_label'], 0, 255),
            'external' => array_slice(array_values(array_unique($note['external'])), 0, self::MAX_EXTERNAL),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($json) && file_put_contents($path, $json, LOCK_EX) === strlen($json) && @chmod($path, 0600);
    }

    /** @return array{name: string, source_site: ?string, source_label: ?string, external: list<string>}|null */
    public function read(string $actor, string $runRef): ?array
    {
        $path = $this->path($actor, $runRef);
        if ($path === null || !is_file($path) || is_link($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data) || !is_string($data['name'] ?? null) || !is_array($data['external'] ?? null)) {
            return null;
        }
        $external = [];
        foreach ($data['external'] as $id) {
            if (is_string($id) && $id !== '' && strlen($id) <= 120) {
                $external[] = $id;
            }
        }

        return [
            'name' => $data['name'],
            'source_site' => is_string($data['source_site'] ?? null) ? $data['source_site'] : null,
            'source_label' => is_string($data['source_label'] ?? null) ? $data['source_label'] : null,
            'external' => $external,
        ];
    }

    private function path(string $actor, string $runRef): ?string
    {
        if (preg_match(self::RUN, $runRef, $match) !== 1 || trim($actor) === '') {
            return null;
        }

        return rtrim($this->root, '/').'/'.substr(hash('sha256', $actor), 0, 32).'/'.$match[1].'.json';
    }

    private function sweep(string $directory): void
    {
        foreach (glob($directory.'/*.json') ?: [] as $file) {
            if (is_file($file) && filemtime($file) < time() - self::TTL) {
                @unlink($file);
            }
        }
    }
}
