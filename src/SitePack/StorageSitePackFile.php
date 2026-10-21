<?php

declare(strict_types=1);

namespace Larena\Storage\SitePack;

use Closure;
use Larena\Storage\Exceptions\StorageRejected;

/**
 * One file's current content for a SitePack: its portable descriptor (the fields of Filesystem's
 * PortableLogicalFileDescriptor::toArray(), without physical paths or disk names) and a way to read its bytes.
 */
final readonly class StorageSitePackFile
{
    /** The descriptor keys, in the order the asset index writes them. */
    public const array DESCRIPTOR_KEYS = [
        'logical_ref', 'public_id', 'display_name', 'original_name', 'mime_type',
        'extension', 'size_bytes', 'sha256', 'visibility', 'alt_text',
    ];

    /** @var array<string, int|string|null> */
    public array $descriptor;

    /**
     * @param array<string, mixed> $descriptor
     * @param Closure(): mixed $streamOpener returns a readable stream
     */
    public function __construct(array $descriptor, private Closure $streamOpener)
    {
        $keys = array_keys($descriptor);
        $expected = self::DESCRIPTOR_KEYS;
        sort($keys);
        sort($expected);
        $uuid = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/D';
        if ($keys !== $expected
            || !is_string($descriptor['logical_ref']) || preg_match($uuid, $descriptor['logical_ref']) !== 1
            || !is_string($descriptor['public_id']) || preg_match($uuid, $descriptor['public_id']) !== 1
            || !is_string($descriptor['sha256']) || preg_match('/\A[a-f0-9]{64}\z/D', $descriptor['sha256']) !== 1
            || !is_int($descriptor['size_bytes']) || $descriptor['size_bytes'] < 0
            || !is_string($descriptor['display_name']) || !is_string($descriptor['original_name'])
            || !is_string($descriptor['mime_type']) || !is_string($descriptor['extension'])
            || !in_array($descriptor['visibility'], ['private', 'public'], true)
            || ($descriptor['alt_text'] !== null && !is_string($descriptor['alt_text']))) {
            throw new StorageRejected('storage_sitepack_file_descriptor_invalid');
        }
        $ordered = [];
        foreach (self::DESCRIPTOR_KEYS as $key) {
            $ordered[$key] = $descriptor[$key];
        }
        /** @var array<string, int|string|null> $ordered */
        $this->descriptor = $ordered;
    }

    public function logicalRef(): string
    {
        return (string) $this->descriptor['logical_ref'];
    }

    public function sha256(): string
    {
        return (string) $this->descriptor['sha256'];
    }

    public function sizeBytes(): int
    {
        return (int) $this->descriptor['size_bytes'];
    }

    /** @return resource */
    public function openStream(): mixed
    {
        $stream = ($this->streamOpener)();
        if (!is_resource($stream)) {
            throw new StorageRejected('storage_sitepack_file_unreadable');
        }

        return $stream;
    }
}
