<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

/**
 * The single published record a key resolves to.
 *
 * A miss is represented by a null result rather than by an empty object, because a
 * caller must not be able to tell a record that does not exist from one it may not
 * read — and an object with empty fields would be a different answer from no object at
 * all.
 */
final readonly class ResolvedKey
{
    /**
     * @param array<string, mixed> $values the public fields of the published head
     */
    public function __construct(
        public string $schemaId,
        public string $recordId,
        public string $scopeRef,
        public string $locale,
        public int $revision,
        public string $keyField,
        public string $keyValue,
        public array $values = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_id' => $this->schemaId,
            'record_id' => $this->recordId,
            'scope_ref' => $this->scopeRef,
            'locale' => $this->locale,
            'revision' => $this->revision,
            'key_field' => $this->keyField,
            'key_value' => $this->keyValue,
            'values' => $this->values,
        ];
    }
}
