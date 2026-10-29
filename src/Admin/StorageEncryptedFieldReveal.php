<?php

declare(strict_types=1);

namespace Larena\Storage\Admin;

use Closure;
use InvalidArgumentException;
use Larena\Access\Contracts\ActorOperationAuthorizer;
use Larena\Access\Exceptions\AccessMutationRejected;
use Larena\Storage\Admin\Contracts\StorageSectionScope;
use Larena\Storage\Contracts\StorageWorkbench;
use Larena\Storage\Encryption\StorageFieldReveal;
use Larena\Storage\Exceptions\StoragePersistenceFailed;
use Larena\Storage\Exceptions\StorageRejected;
use RuntimeException;
use Throwable;

/**
 * «Показать» of an encrypted field in the record panel: the plaintext of one field of one record, for the person who
 * holds storage.workbench.record.decrypt, through Storage's StorageFieldReveal (it checks the right, reads the record
 * within the person's scope and audits every reveal with the field's key, never its value).
 *
 * The plaintext goes back to the panel that asked and nowhere else: nothing here keeps, caches or logs it, and a
 * refusal names its reason only. The reveal is StorageFieldReveal::revealRecord(), given as a closure by the service
 * provider (null while Storage offers no reveal).
 */
final readonly class StorageEncryptedFieldReveal
{
    public const STRUCTURE_PATTERN = '/\Aworkbench\.[a-z][a-z0-9_.:-]{0,109}\z/D';
    public const RECORD_PATTERN = '/\Arecord-[0-9a-f]{32}\z/D';
    public const FIELD_PATTERN = '/\A[a-z][a-z0-9_]{0,63}\z/D';

    /**
     * @param (Closure(string, string, string, list<string>, string): array<string, mixed>)|null $reveal
     *        StorageFieldReveal::revealRecord(scope, structure, record, field keys, actor)
     */
    public function __construct(
        private StorageSectionScope $scopes,
        private StorageWorkbench $workbench,
        private ?Closure $reveal = null,
        private ?ActorOperationAuthorizer $authorizer = null,
    ) {
    }

    /**
     * Whether the record panel offers «Показать» to this person: Storage offers a reveal and the person holds
     * storage.workbench.record.decrypt (Storage asks for it again on each reveal).
     */
    public function mayReveal(string $actor): bool
    {
        if ($this->reveal === null || $this->authorizer === null) {
            return false;
        }
        try {
            $this->authorizer->assertAllowed($actor, StorageFieldReveal::OPERATION);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array{status: 'ok', text: string}|array{status: 'denied'|'rejected'|'unavailable', reason_code: string}
     */
    public function reveal(string $actor, string $structureId, string $recordId, string $fieldKey): array
    {
        if (preg_match(self::STRUCTURE_PATTERN, $structureId) !== 1 || preg_match(self::RECORD_PATTERN, $recordId) !== 1
            || preg_match(self::FIELD_PATTERN, $fieldKey) !== 1) {
            return ['status' => 'rejected', 'reason_code' => StorageFieldReveal::REASON_INVALID];
        }
        if ($this->reveal === null) {
            return ['status' => 'unavailable', 'reason_code' => 'storage_field_encryption_unavailable'];
        }
        try {
            $scope = $this->scopes->scopeForActor($actor);
            $structure = $this->workbench->readStructure($scope, $structureId, $actor);
            if (!in_array($fieldKey, StorageEncryptedFields::keys($structure->fields), true)) {
                return ['status' => 'rejected', 'reason_code' => StorageFieldReveal::REASON_INVALID];
            }
            $values = ($this->reveal)($scope, $structureId, $recordId, [$fieldKey], $actor);

            return ['status' => 'ok', 'text' => self::text($values[$fieldKey] ?? null)];
        } catch (AccessMutationRejected) {
            return ['status' => 'denied', 'reason_code' => 'storage_workbench_access_denied'];
        } catch (StoragePersistenceFailed) {
            // The reveal's audit event could not be written, so nothing was revealed.
            return ['status' => 'unavailable', 'reason_code' => 'storage_workbench_runtime_unavailable'];
        } catch (StorageRejected $exception) {
            return ['status' => 'rejected', 'reason_code' => in_array($exception->reasonCode, StorageSectionGateway::ENCRYPTION_REASONS, true)
                ? $exception->reasonCode : 'storage_workbench_action_rejected'];
        } catch (InvalidArgumentException) {
            return ['status' => 'rejected', 'reason_code' => StorageFieldReveal::REASON_INVALID];
        } catch (RuntimeException $exception) {
            // A scope the person is not in reads as a denial; anything else as unavailable.
            return in_array($exception->getMessage(), [StorageSectionScope::SCOPE_DENIED, StorageSectionScope::SCOPE_UNSUPPORTED], true)
                ? ['status' => 'denied', 'reason_code' => 'storage_workbench_scope_denied']
                : ['status' => 'unavailable', 'reason_code' => 'storage_workbench_runtime_unavailable'];
        } catch (Throwable) {
            return ['status' => 'unavailable', 'reason_code' => 'storage_workbench_runtime_unavailable'];
        }
    }

    /** The value as the person typed it: text, a number, a date; an empty field reads as nothing. */
    private static function text(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            is_bool($value) => $value ? '1' : '0',
            default => '',
        };
    }
}
