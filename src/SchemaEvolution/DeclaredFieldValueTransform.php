<?php

declare(strict_types=1);

namespace Larena\Storage\SchemaEvolution;

use Larena\Storage\Exceptions\StorageRejected;

/**
 * The value rewrite of a declared schema transform. It is deterministic and
 * guesses nothing: a removed field loses its value, a field whose type changed
 * is converted by the fixed rules below, and anything that cannot be converted
 * exactly refuses the whole migration. The Property type of the target field
 * validates every converted value afterwards.
 */
final readonly class DeclaredFieldValueTransform
{
    private const TRUE_WORDS = ['true', '1', 'yes', 'y', 'on', 'да'];
    private const FALSE_WORDS = ['false', '0', 'no', 'n', 'off', 'нет', ''];

    /**
     * @param list<array<string, mixed>> $sourceFields
     * @param list<array<string, mixed>> $targetFields
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public function apply(array $sourceFields, array $targetFields, array $values): array
    {
        $sourceTypes = [];
        foreach ($sourceFields as $field) {
            $sourceTypes[(string) $field['key']] = (string) ($field['type'] ?? '');
        }
        $result = [];
        foreach ($targetFields as $field) {
            $key = (string) $field['key'];
            if (!array_key_exists($key, $values) || $values[$key] === null) {
                continue;
            }
            $from = $sourceTypes[$key] ?? null;
            $to = (string) ($field['type'] ?? '');
            $result[$key] = $from === null || $from === $to ? $values[$key] : $this->convert($values[$key], $to);
        }

        return $result;
    }

    private function convert(mixed $value, string $to): mixed
    {
        return match ($to) {
            'string', 'text' => $this->toText($value),
            'integer' => $this->toInteger($value),
            'number' => $this->toNumber($value),
            'boolean' => $this->toBoolean($value),
            default => is_string($value) ? $value : $this->refuse(),
        };
    }

    private function toText(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => (string) $value,
            default => $this->refuse(),
        };
    }

    private function toInteger(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (is_float($value) && floor($value) === $value && abs($value) < 9.0E15) {
            return (int) $value;
        }
        if (is_string($value) && preg_match('/\A\s*-?\d{1,18}\s*\z/D', $value) === 1) {
            return (int) trim($value);
        }
        if (is_string($value) && preg_match('/\A\s*(-?\d{1,18})\.0+\s*\z/D', $value, $match) === 1) {
            return (int) $match[1];
        }

        return $this->refuse();
    }

    private function toNumber(mixed $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value) && is_finite($value)) {
            return rtrim(rtrim(sprintf('%.14F', $value), '0'), '.');
        }
        if (is_string($value) && preg_match('/\A\s*-?\d+(?:[.,]\d+)?\s*\z/D', $value) === 1) {
            return str_replace(',', '.', trim($value));
        }

        return $this->refuse();
    }

    private function toBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 0 || $value === 1) {
            return $value === 1;
        }
        if (is_string($value)) {
            $word = mb_strtolower(trim($value));
            if (in_array($word, self::TRUE_WORDS, true)) {
                return true;
            }
            if (in_array($word, self::FALSE_WORDS, true)) {
                return false;
            }
        }

        return $this->refuse();
    }

    private function refuse(): never
    {
        throw new StorageRejected('storage_schema_migration_value_unconvertible');
    }
}
