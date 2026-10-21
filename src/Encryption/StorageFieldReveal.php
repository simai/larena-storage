<?php

declare(strict_types=1);

namespace Larena\Storage\Encryption;

use InvalidArgumentException;
use Larena\Access\Contracts\ActorOperationAuthorizer;
use Larena\Storage\Contracts\StorageSecurityEvent;
use Larena\Storage\Contracts\StorageSecurityEventSink;
use Larena\Storage\Contracts\StorageWorkbench;
use Larena\Storage\Exceptions\StoragePersistenceFailed;
use Larena\Storage\Exceptions\StorageRejected;
use Throwable;

/**
 * The one way to read an encrypted value in plaintext. Every reader of Storage returns envelopes; a reveal decrypts
 * them for an actor that holds `storage.workbench.record.decrypt` (critical, always audited), and records a
 * `storage.field.revealed` audit event with the storage, the record and the revealed field keys, never a value nor a
 * ciphertext. An audit event that cannot be written reveals nothing.
 *
 * - revealRecord(): fields of a workbench record's current version, read through the workbench (its scope and read
 *   rights apply); each named field must be an encrypted field of the structure (`storage_field_reveal_invalid`).
 * - revealValues(): envelopes the caller has read through a scoped read (a version of the history, one locale's
 *   translations); the associated data binds each to its storage, record, locale and field, so an envelope of
 *   another record does not open.
 */
final readonly class StorageFieldReveal
{
    public const OPERATION = 'storage.workbench.record.decrypt';
    public const EVENT = 'storage.field.revealed';
    public const REASON_INVALID = 'storage_field_reveal_invalid';

    public function __construct(
        private StorageDataKeys $keys,
        private ActorOperationAuthorizer $authorizer,
        private StorageSecurityEventSink $events,
        private ?StorageWorkbench $workbench = null,
        private StorageFieldCipher $cipher = new StorageFieldCipher(),
    ) {
    }

    /**
     * The plaintext of the named encrypted fields of a workbench record's current version, by key; a field the
     * record has no value for is left out.
     *
     * @param list<string> $fieldKeys
     * @return array<string, mixed>
     * @throws StorageRejected
     */
    public function revealRecord(
        string $scopeRef,
        string $structureId,
        string $recordId,
        array $fieldKeys,
        string $actor,
        ?string $correlationId = null,
    ): array {
        $this->assertActor($actor);
        if ($this->workbench === null || $fieldKeys === [] || count(array_unique($fieldKeys)) !== count($fieldKeys)) {
            throw new StorageRejected(self::REASON_INVALID);
        }
        $this->authorizer->assertAllowed($actor, self::OPERATION);
        $structure = $this->workbench->readStructure($scopeRef, $structureId, $actor);
        $encrypted = StorageEncryptionPolicy::encryptedKeys($structure->fields);
        foreach ($fieldKeys as $key) {
            if (!in_array($key, $encrypted, true)) {
                throw new StorageRejected(self::REASON_INVALID);
            }
        }
        $record = $this->workbench->readRecord($scopeRef, $structureId, $recordId, $actor);
        $values = array_intersect_key($record->values, array_flip($fieldKeys));

        return $this->revealValues($actor, $structure->schema->schemaId, $record->recordId, $values, null, $correlationId);
    }

    /**
     * The plaintext of the envelopes among $values, by key; values that are no envelope are left out.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     * @throws StorageRejected
     */
    public function revealValues(
        string $actor,
        string $schemaId,
        string $recordId,
        array $values,
        ?string $locale = null,
        ?string $correlationId = null,
    ): array {
        $this->assertActor($actor);
        $this->authorizer->assertAllowed($actor, self::OPERATION);
        $revealed = [];
        foreach ($values as $key => $value) {
            if (!StorageFieldEnvelope::isEnvelope($value)) {
                continue;
            }
            $envelope = StorageFieldEnvelope::fromValue($value);
            $revealed[$key] = $this->cipher->decrypt($this->keys->key($schemaId, $envelope->keyId), $envelope, $schemaId, $recordId, $locale, $key);
        }
        if ($revealed === []) {
            return [];
        }
        $keys = array_keys($revealed);
        sort($keys, SORT_STRING);
        try {
            $this->events->emit(new StorageSecurityEvent(
                'encryption',
                self::EVENT,
                $actor,
                'storage-record:' . $recordId,
                $correlationId === null ? 'storage-reveal-' . bin2hex(random_bytes(12)) : 'storage-reveal-' . hash('sha256', $correlationId),
                [
                    'schema_id' => $schemaId,
                    'record_id' => $recordId,
                    'revealed_fields' => $keys,
                ] + ($locale === null ? [] : ['locale' => $locale]),
            ));
        } catch (Throwable $exception) {
            // Nothing is revealed without its audit event.
            throw StoragePersistenceFailed::from($exception);
        }

        return $revealed;
    }

    private function assertActor(string $actor): void
    {
        if (trim($actor) === '' || strlen($actor) > 191) {
            throw new InvalidArgumentException('storage_actor_invalid');
        }
    }
}
