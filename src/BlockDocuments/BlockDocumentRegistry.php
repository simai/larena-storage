<?php

declare(strict_types=1);

namespace Larena\Storage\BlockDocuments;

use Larena\Storage\BlockDocuments\BlockDocumentRejected;

final class BlockDocumentRegistry
{
    public const ID = 'larena.content.blocks';
    public const VERSION = 1;
    public const UNKNOWN_BLOCK_POLICY = 'reject';

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function migrateAndSanitize(string $type, int $version, array $data): array
    {
        if (!in_array($version, [0, 1], true)) {
            throw $this->reject('content_block_version_unknown');
        }

        if ($type === 'paragraph') {
            if ($version === 0 && array_key_exists('body', $data) && !array_key_exists('text', $data)) {
                $data['text'] = $data['body'];
                unset($data['body']);
            }
            $this->exactKeys($data, ['text']);

            return ['text' => $this->plainText($data['text'] ?? null, 10_000, true)];
        }

        if ($type === 'image') {
            if ($version === 0 && array_key_exists('file_ref', $data) && !array_key_exists('logical_file_ref', $data)) {
                $data['logical_file_ref'] = $data['file_ref'];
                unset($data['file_ref']);
            }
            $this->exactKeys($data, ['alt', 'caption', 'logical_file_ref']);
            $ref = $data['logical_file_ref'] ?? null;
            if (!is_string($ref) || preg_match('/^[a-f0-9]{8}-[a-f0-9-]{27}$/i', $ref) !== 1) {
                throw $this->reject('content_block_file_ref_invalid');
            }

            return [
                'logical_file_ref' => strtolower($ref),
                'caption' => $this->plainText($data['caption'] ?? '', 2_000, false),
                'alt' => $this->plainText($data['alt'] ?? '', 500, false),
            ];
        }

        throw $this->reject('content_block_type_unknown');
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $expected
     */
    private function exactKeys(array $data, array $expected): void
    {
        $keys = array_keys($data);
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($keys !== $expected) {
            throw $this->reject('content_block_data_shape_invalid');
        }
    }

    private function plainText(mixed $value, int $limit, bool $required): string
    {
        if (!is_string($value)
            || preg_match('/<\s*(script|style|iframe)|<\?php|javascript\s*:|data\s*:\s*text\/html/i', $value) === 1) {
            throw $this->reject('content_block_payload_unsafe');
        }
        $value = trim(preg_replace('/\s+/u', ' ', strip_tags($value)) ?? '');
        if (($required && $value === '') || mb_strlen($value) > $limit) {
            throw $this->reject('content_block_payload_invalid');
        }

        return $value;
    }

    private function reject(string $reason): BlockDocumentRejected
    {
        return new BlockDocumentRejected($reason, 'The block document was rejected.');
    }
}
