<?php

declare(strict_types=1);

namespace Larena\Storage\Runtime;

use Larena\Storage\Contracts\StorageMutation;
use Larena\Storage\Enums\StorageDecisionStatus;

final readonly class StorageAuditPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function fromMutation(StorageMutation $mutation, StorageDecisionStatus $decision): array
    {
        return [
            'schema_id' => $mutation->schemaId(),
            'record_id' => $mutation->recordId(),
            'mutation_type' => $mutation->type()->value,
            'decision' => $decision->value,
            'payload_keys' => array_map('strval', array_keys($mutation->payload())),
            'payload_field_count' => count($mutation->payload()),
            'payload_redacted' => true,
        ];
    }

    /**
     * @return list<string>
     */
    public static function forbiddenRawPayloadFields(): array
    {
        return [
            'payload',
            'raw_payload',
            'secret',
            'token',
            'credential',
            'password',
            'private_value',
        ];
    }
}
