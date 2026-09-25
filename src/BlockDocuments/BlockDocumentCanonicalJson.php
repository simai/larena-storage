<?php

declare(strict_types=1);

namespace Larena\Storage\BlockDocuments;

use JsonException;
use Larena\Storage\BlockDocuments\BlockDocumentRejected;

final class BlockDocumentCanonicalJson
{
    public function encode(mixed $value): string
    {
        try {
            return json_encode(
                $this->canonicalize($value),
                JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_PRESERVE_ZERO_FRACTION,
            );
        } catch (JsonException $exception) {
            throw new BlockDocumentRejected(
                'canonical_json_invalid',
                'Block document data cannot be represented as canonical JSON.',
                $exception,
            );
        }
    }

    public function byteLength(mixed $value): int
    {
        return strlen($this->encode($value));
    }

    public function assertMaximumBytes(mixed $value, int $maximumBytes, string $reasonCode): void
    {
        if ($maximumBytes < 1) {
            throw new \InvalidArgumentException('A canonical JSON byte limit must be positive.');
        }

        if ($this->byteLength($value) > $maximumBytes) {
            throw new BlockDocumentRejected(
                $reasonCode,
                sprintf('Canonical block document JSON exceeds %d bytes.', $maximumBytes),
            );
        }
    }

    public function canonicalize(mixed $value): mixed
    {
        if (is_float($value) && !is_finite($value)) {
            throw new BlockDocumentRejected(
                'canonical_json_invalid',
                'Canonical block document JSON cannot contain a non-finite number.',
            );
        }

        if (!is_array($value)) {
            if (
                $value === null
                || is_bool($value)
                || is_int($value)
                || is_float($value)
                || is_string($value)
            ) {
                return $value;
            }

            throw new BlockDocumentRejected(
                'canonical_json_invalid',
                'Canonical block document JSON accepts only JSON-compatible values.',
            );
        }

        if (array_is_list($value)) {
            return array_map(
                fn (mixed $entry): mixed => $this->canonicalize($entry),
                $value,
            );
        }

        $canonical = [];

        foreach ($value as $key => $entry) {
            if (!is_string($key)) {
                throw new BlockDocumentRejected(
                    'canonical_json_invalid',
                    'Canonical block document JSON object keys must be strings.',
                );
            }

            $canonical[$key] = $this->canonicalize($entry);
        }

        ksort($canonical, SORT_STRING);

        return $canonical;
    }
}
