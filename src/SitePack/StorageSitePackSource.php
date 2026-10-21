<?php

declare(strict_types=1);

namespace Larena\Storage\SitePack;

use Generator;
use Larena\Core\SitePack\SitePackArchiveReader;
use Larena\Core\SitePack\SitePackLimits;
use Larena\Core\SitePack\SitePackRejected;
use Larena\Storage\Exceptions\StorageRejected;

/**
 * A storage SitePack opened for import with core's reader (envelope and catalog checked on open, every artifact's
 * size, line count and SHA-256 while it is streamed). It checks the storage format on top: the schema stamp
 * `larena.storage.sitepack` version 1, one structure line, record parts of that structure only, asset lines that are
 * portable file descriptors whose blob is in the pack, and the shape of every record line.
 *
 * What it yields before an artifact's stream ends is provisional (core's rule): the plan reads everything before
 * apply() writes anything, and the import job writes only where a failure is discarded.
 */
final class StorageSitePackSource
{
    /** @var array<string, mixed> */
    public readonly array $structure;

    /** @var list<string> record part paths, in order */
    public readonly array $parts;

    private function __construct(public readonly SitePackArchiveReader $reader)
    {
        $envelope = $reader->envelope;
        if (!$envelope->annotationsAre(StorageSitePackFormat::SCHEMA_NAME, StorageSitePackFormat::SCHEMA_VERSION)
            || ($envelope->annotations['adapter'] ?? null) !== StorageSitePackFormat::ADAPTER
            || !$envelope->has(StorageSitePackFormat::STRUCTURES)
            || !$envelope->has(StorageSitePackFormat::REFERENCES)
            || !$envelope->has(StorageSitePackFormat::ASSET_INDEX)) {
            throw new StorageRejected('storage_sitepack_format_unsupported');
        }
        $structures = [];
        foreach ($reader->lines(StorageSitePackFormat::STRUCTURES) as $line) {
            $structures[] = $line;
        }
        if (count($structures) !== 1) {
            throw new StorageRejected('storage_sitepack_structure_count_unsupported');
        }
        $structure = $structures[0];
        if (!is_string($structure['id'] ?? null) || preg_match('/\Aworkbench\.[a-z][a-z0-9_.:-]{0,109}\z/D', $structure['id']) !== 1
            || !is_string($structure['label'] ?? null) || !is_array($structure['fields'] ?? null) || !array_is_list($structure['fields'])
            || (($structure['history_keep'] ?? null) !== null && !is_int($structure['history_keep']))
            || !is_array($structure['role_bindings'] ?? [])) {
            throw new StorageRejected('storage_sitepack_structure_invalid');
        }
        $this->structure = $structure;
        $key = StorageSitePackFormat::structureKey($structure['id']);
        $prefix = StorageSitePackFormat::RECORDS_DIRECTORY . '/';
        $parts = [];
        foreach ($envelope->entries as $entry) {
            if (!str_starts_with($entry->path, $prefix)) {
                continue;
            }
            if (preg_match('/\A' . preg_quote($prefix . $key . '/', '/') . 'part-\d{6}\.jsonl\z/D', $entry->path) !== 1 || !$entry->isLines()) {
                throw new StorageRejected('storage_sitepack_records_invalid');
            }
            $parts[] = $entry->path;
        }
        sort($parts, SORT_STRING);
        foreach ($parts as $index => $path) {
            if ($path !== StorageSitePackFormat::recordsPart($key, $index + 1)) {
                throw new StorageRejected('storage_sitepack_records_invalid');
            }
        }
        $this->parts = $parts;
    }

    public static function open(string $archivePath, SitePackLimits $limits): self
    {
        try {
            return new self(SitePackArchiveReader::open($archivePath, $limits));
        } catch (SitePackRejected $exception) {
            throw new StorageRejected($exception->reasonCode, $exception);
        }
    }

    public function packageId(): string
    {
        return $this->reader->envelope->packageId;
    }

    public function sourceSite(): ?string
    {
        $source = $this->reader->envelope->annotations['source'] ?? null;

        return is_array($source) && is_string($source['site_ref'] ?? null) ? $source['site_ref'] : null;
    }

    /** Whether the pack carries encrypted values in plaintext (an export with include_encrypted_plaintext). */
    public function containsDecryptedValues(): bool
    {
        return ($this->reader->envelope->annotations[StorageSitePackFormat::DECRYPTED_ANNOTATION] ?? false) === true;
    }

    /** Records in the pack, as the catalog declares them. */
    public function recordCount(): int
    {
        $count = 0;
        foreach ($this->parts as $path) {
            $count += (int) $this->reader->entry($path)->lines;
        }

        return $count;
    }

    /**
     * The record lines from part $part (1-based), skipping its first $skip lines, each checked.
     *
     * @return Generator<int, array{part: int, line: int, record: array<string, mixed>}>
     */
    public function records(int $part = 1, int $skip = 0): Generator
    {
        $index = 0;
        foreach ($this->parts as $number => $path) {
            if ($number + 1 < $part) {
                continue;
            }
            foreach ($this->lines($path) as $line => $record) {
                if ($number + 1 === $part && $line <= $skip) {
                    continue;
                }

                yield $index++ => ['part' => $number + 1, 'line' => $line, 'record' => self::record($record)];
            }
        }
    }

    /** @return Generator<int, array{id: string, structure_id: ?string}> */
    public function references(): Generator
    {
        foreach ($this->lines(StorageSitePackFormat::REFERENCES) as $line => $reference) {
            if (!is_string($reference['id'] ?? null) || preg_match('/\Arecord-[a-f0-9]{32}\z/D', $reference['id']) !== 1
                || (($reference['structure_id'] ?? null) !== null && !is_string($reference['structure_id']))) {
                throw new StorageRejected('storage_sitepack_reference_invalid');
            }

            yield $line => ['id' => $reference['id'], 'structure_id' => $reference['structure_id'] ?? null];
        }
    }

    /**
     * The asset index: one portable file descriptor a line, with the path of its blob in the pack.
     *
     * @return Generator<int, array{descriptor: array<string, mixed>, blob: string}>
     */
    public function assets(int $skip = 0): Generator
    {
        foreach ($this->lines(StorageSitePackFormat::ASSET_INDEX) as $line => $asset) {
            if ($line <= $skip) {
                continue;
            }
            $descriptor = array_intersect_key($asset, array_flip(StorageSitePackFile::DESCRIPTOR_KEYS));
            $file = new StorageSitePackFile($descriptor, static fn (): mixed => null);
            $blob = $asset['blob'] ?? null;
            if (($asset['key'] ?? null) !== StorageSitePackFormat::assetKey($file->logicalRef())
                || $blob !== StorageSitePackFormat::blob($file->sha256())
                || !$this->reader->envelope->has($blob)
                || count($asset) !== count(StorageSitePackFile::DESCRIPTOR_KEYS) + 2) {
                throw new StorageRejected('storage_sitepack_asset_invalid');
            }

            yield $line => ['descriptor' => $file->descriptor, 'blob' => $blob];
        }
    }

    /** Streams every artifact that is not JSON lines (the blobs) to its end: their digests are checked. @return int bytes */
    public function verifyBlobs(?\Closure $tick = null): int
    {
        $bytes = 0;
        foreach ($this->reader->envelope->entries as $entry) {
            if ($entry->isLines()) {
                continue;
            }
            try {
                foreach ($this->reader->stream($entry->path) as $chunk) {
                    $bytes += strlen($chunk);
                }
            } catch (SitePackRejected $exception) {
                throw new StorageRejected($exception->reasonCode, $exception);
            }
            if ($tick !== null) {
                $tick();
            }
        }

        return $bytes;
    }

    /** Copies one blob to a new private file, its digest checked; the copy is removed on any failure. */
    public function copyBlob(string $blob, string $target): void
    {
        try {
            $this->reader->copyTo($blob, $target);
        } catch (SitePackRejected $exception) {
            throw new StorageRejected($exception->reasonCode, $exception);
        }
        @chmod($target, 0600);
    }

    public function close(): void
    {
        $this->reader->close();
    }

    /**
     * The items of $items in lists of at most $size.
     *
     * @template T
     * @param iterable<T> $items
     * @return Generator<int, non-empty-list<T>>
     */
    public static function chunks(iterable $items, int $size): Generator
    {
        $chunk = [];
        foreach ($items as $item) {
            $chunk[] = $item;
            if (count($chunk) >= $size) {
                yield $chunk;
                $chunk = [];
            }
        }
        if ($chunk !== []) {
            yield $chunk;
        }
    }

    /** @return Generator<int, array<string, mixed>> */
    private function lines(string $path): Generator
    {
        try {
            foreach ($this->reader->lines($path) as $line => $object) {
                yield $line => $object;
            }
        } catch (SitePackRejected $exception) {
            throw new StorageRejected($exception->reasonCode, $exception);
        }
    }

    /**
     * One record line, checked: `{id, state, versions: [{revision, current, published_in, schema_version, values,
     * localized, content_hash}], publication: [...], relations: [...]}`, versions in revision order, one current.
     *
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    public static function record(array $record): array
    {
        $versions = $record['versions'] ?? null;
        if (!is_string($record['id'] ?? null) || preg_match('/\Arecord-[a-f0-9]{32}\z/D', $record['id']) !== 1
            || !in_array($record['state'] ?? null, ['active', 'archived'], true)
            || !is_array($versions) || $versions === [] || !array_is_list($versions)
            || !is_array($record['publication'] ?? null) || !array_is_list($record['publication'])
            || !is_array($record['relations'] ?? null) || !array_is_list($record['relations'])) {
            throw new StorageRejected('storage_sitepack_record_invalid');
        }
        $previous = 0;
        $current = 0;
        foreach ($versions as $version) {
            if (!is_array($version) || !is_int($version['revision'] ?? null) || $version['revision'] <= $previous
                || !is_array($version['values'] ?? null) || !is_array($version['localized'] ?? null)
                || ($version['values'] !== [] && array_is_list($version['values']))) {
                throw new StorageRejected('storage_sitepack_record_invalid');
            }
            foreach ($version['localized'] as $locale => $values) {
                if (!is_string($locale) || preg_match('/\A[a-z]{2,3}(?:[-_][A-Za-z0-9]{2,8})?\z/D', $locale) !== 1
                    || !is_array($values) || ($values !== [] && array_is_list($values))) {
                    throw new StorageRejected('storage_sitepack_record_invalid');
                }
            }
            $previous = $version['revision'];
            $current += ($version['current'] ?? false) === true ? 1 : 0;
        }
        if ($current !== 1 || ($versions[count($versions) - 1]['current'] ?? false) !== true) {
            throw new StorageRejected('storage_sitepack_record_invalid');
        }
        foreach ($record['publication'] as $publication) {
            if (!is_array($publication) || !is_string($publication['locale'] ?? null) || !is_string($publication['state'] ?? null)
                || (($publication['revision'] ?? null) !== null && !is_int($publication['revision']))
                || (($publication['scheduled_revision'] ?? null) !== null && !is_int($publication['scheduled_revision']))
                || (($publication['scheduled_at'] ?? null) !== null && !is_string($publication['scheduled_at']))) {
                throw new StorageRejected('storage_sitepack_record_invalid');
            }
        }
        foreach ($record['relations'] as $relation) {
            if (!is_array($relation) || !is_string($relation['relation_key'] ?? null) || preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $relation['relation_key']) !== 1
                || !is_string($relation['kind'] ?? null) || preg_match('/\A[a-z][a-z0-9_]{0,15}\z/D', $relation['kind']) !== 1
                || !is_string($relation['to'] ?? null) || preg_match('/\Arecord-[a-f0-9]{32}\z/D', $relation['to']) !== 1
                || !is_int($relation['order_index'] ?? null) || !is_int($relation['depth'] ?? null)
                || $relation['depth'] < 0 || $relation['depth'] > 64) {
                throw new StorageRejected('storage_sitepack_record_invalid');
            }
        }

        return $record;
    }

    /**
     * The new revision of each source revision of a record: its versions in source order become 1..n.
     *
     * @param array<string, mixed> $record
     * @return array<int, int>
     */
    public static function revisions(array $record): array
    {
        $map = [];
        foreach ($record['versions'] as $index => $version) {
            $map[(int) $version['revision']] = $index + 1;
        }

        return $map;
    }
}
