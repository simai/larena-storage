<?php

declare(strict_types=1);

namespace Larena\Storage\Admin;

use InvalidArgumentException;

/**
 * The private files of the Storage section's background jobs, outside any public disk: a CSV a person uploaded
 * for an import (kept by the person's upload token until it is applied or queued), and per job its source file,
 * its plan and its export. A job's files are named by its reference, never by anything a request sends.
 */
final readonly class StorageJobFiles
{
    public const SOURCE = 'source.csv';
    public const PLAN = 'plan.jsonl';
    public const EXPORT_PART = 'export.csv.part';
    public const EXPORT = 'export.csv';
    private const NAMES = [self::SOURCE, self::PLAN, self::EXPORT_PART, self::EXPORT];
    private const REF = '/\A[a-f0-9]{32}\z/D';
    /** An upload nobody applied is removed after this many seconds. */
    private const UPLOAD_TTL = 3600;

    public function __construct(private string $root)
    {
        if (trim($root) === '') {
            throw new InvalidArgumentException('storage_job_files_root_missing');
        }
    }

    public static function newRef(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function isRef(mixed $value): bool
    {
        return is_string($value) && preg_match(self::REF, $value) === 1;
    }

    /** Keeps a person's upload under their token; false when the private directory cannot be written. */
    public function keepUpload(string $actor, string $token, string $contents): bool
    {
        $directory = $this->directory('uploads');
        if ($directory === null) {
            return false;
        }
        $this->sweepUploads($directory);
        $path = $this->uploadPath($actor, $token);

        return file_put_contents($path, $contents, LOCK_EX) === strlen($contents) && chmod($path, 0600);
    }

    public function upload(string $actor, string $token): ?string
    {
        $path = $this->uploadPath($actor, $token);

        return is_file($path) && filemtime($path) >= time() - self::UPLOAD_TTL ? $path : null;
    }

    public function forgetUpload(string $actor, string $token): void
    {
        @unlink($this->uploadPath($actor, $token));
    }

    /** Moves a person's upload into a job as its source file. */
    public function adoptUpload(string $actor, string $token, string $ref): bool
    {
        $upload = $this->upload($actor, $token);
        $directory = $this->jobDirectory($ref);

        return $upload !== null && $directory !== null && rename($upload, $directory.'/'.self::SOURCE);
    }

    public function path(string $ref, string $name): string
    {
        if (!self::isRef($ref) || !in_array($name, self::NAMES, true)) {
            throw new InvalidArgumentException('storage_job_file_invalid');
        }

        return rtrim($this->root, '/').'/jobs/'.$ref.'/'.$name;
    }

    /** The job's directory, made when missing; null when it cannot be made. */
    public function jobDirectory(string $ref): ?string
    {
        if (!self::isRef($ref)) {
            throw new InvalidArgumentException('storage_job_file_invalid');
        }

        return $this->directory('jobs/'.$ref);
    }

    /**
     * Up to $limit lines of a job's file from a byte offset, and the offset after them.
     *
     * @return array{lines: list<string>, offset: int}
     */
    public function lines(string $ref, string $name, int $offset, int $limit): array
    {
        $handle = @fopen($this->path($ref, $name), 'rb');
        if ($handle === false) {
            throw new InvalidArgumentException('storage_job_file_missing');
        }
        try {
            if ($offset > 0 && fseek($handle, $offset) !== 0) {
                throw new InvalidArgumentException('storage_job_file_offset_invalid');
            }
            $lines = [];
            while (count($lines) < $limit && ($line = fgets($handle)) !== false) {
                if (trim($line) !== '') {
                    $lines[] = rtrim($line, "\n");
                }
            }
            $position = ftell($handle);

            return ['lines' => $lines, 'offset' => $position === false ? $offset : $position];
        } finally {
            fclose($handle);
        }
    }

    /**
     * Opens a job's file to append after exactly $bytes: what a crashed run wrote beyond them is cut off.
     *
     * @return resource
     */
    public function appendAt(string $ref, string $name, int $bytes)
    {
        if ($this->jobDirectory($ref) === null) {
            throw new InvalidArgumentException('storage_job_file_unwritable');
        }
        $path = $this->path($ref, $name);
        $handle = fopen($path, 'c+b');
        if ($handle === false || !ftruncate($handle, max(0, $bytes)) || fseek($handle, 0, SEEK_END) !== 0) {
            throw new InvalidArgumentException('storage_job_file_unwritable');
        }
        chmod($path, 0600);

        return $handle;
    }

    public function size(string $ref, string $name): int
    {
        clearstatcache(true, $this->path($ref, $name));
        $size = @filesize($this->path($ref, $name));

        return $size === false ? 0 : $size;
    }

    public function exists(string $ref, string $name): bool
    {
        return is_file($this->path($ref, $name));
    }

    public function move(string $ref, string $from, string $to): bool
    {
        return rename($this->path($ref, $from), $this->path($ref, $to));
    }

    public function delete(string $ref, string $name): void
    {
        @unlink($this->path($ref, $name));
    }

    /** Removes a job's files and its directory. */
    public function remove(string $ref): void
    {
        foreach (self::NAMES as $name) {
            $this->delete($ref, $name);
        }
        @rmdir(dirname($this->path($ref, self::SOURCE)));
    }

    private function uploadPath(string $actor, string $token): string
    {
        if (preg_match(self::REF, $token) !== 1 || trim($actor) === '') {
            throw new InvalidArgumentException('storage_job_upload_invalid');
        }

        return rtrim($this->root, '/').'/uploads/'.hash('sha256', $actor."\0".$token).'.csv';
    }

    private function directory(string $relative): ?string
    {
        $path = rtrim($this->root, '/').'/'.$relative;
        if (!is_dir($path) && !@mkdir($path, 0700, true) && !is_dir($path)) {
            return null;
        }

        return is_writable($path) ? $path : null;
    }

    private function sweepUploads(string $directory): void
    {
        foreach (glob($directory.'/*.csv') ?: [] as $file) {
            if (@filemtime($file) < time() - self::UPLOAD_TTL) {
                @unlink($file);
            }
        }
    }
}
