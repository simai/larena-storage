<?php

declare(strict_types=1);

namespace Larena\Storage\Encryption;

use Larena\Access\Contracts\ActorOperationAuthorizer;
use Larena\Storage\Contracts\StorageWorkbench;
use Larena\Storage\Exceptions\StorageRejected;

/**
 * What a structure's encryption is, for `storage.encryption.explain` (high): its encrypted fields, whether values
 * can be encrypted here (sodium and the key-encryption key), and its data keys by id, status, KEK id, algorithm and
 * dates, each with whether this installation can unwrap it and whether the current KEK wraps it (false: run
 * `larena:storage:encryption:rewrap`). Never a key, a value or a ciphertext.
 */
final readonly class StorageEncryptionExplain
{
    public const OPERATION = 'storage.encryption.explain';

    public function __construct(
        private StorageWorkbench $workbench,
        private StorageDataKeys $keys,
        private ActorOperationAuthorizer $authorizer,
    ) {
    }

    /**
     * @return array{structure_id: string, schema_id: string, encrypted_fields: list<string>, algorithm: string, available: bool, reason_code: ?string, keys: list<array{key_id: string, status: string, kek_id: string, algorithm: string, created_at: string, retired_at: ?string, unwrappable: bool, current_kek: bool}>}
     * @throws StorageRejected
     */
    public function explain(string $scopeRef, string $structureId, string $actor): array
    {
        $this->authorizer->assertAllowed($actor, self::OPERATION);
        $structure = $this->workbench->readStructure($scopeRef, $structureId, $actor);
        $availability = $this->keys->availability();

        return [
            'structure_id' => $structure->structureId,
            'schema_id' => $structure->schema->schemaId,
            'encrypted_fields' => StorageEncryptionPolicy::encryptedKeys($structure->fields),
            'algorithm' => StorageFieldEnvelope::ALGORITHM,
            'available' => $availability['available'],
            'reason_code' => $availability['reason_code'],
            'keys' => $this->keys->describe($structure->schema->schemaId),
        ];
    }
}
