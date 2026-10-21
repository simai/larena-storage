<?php

declare(strict_types=1);

namespace Larena\Storage\Encryption;

use Larena\Core\Contracts\OperationContext;
use Larena\Core\Contracts\OperationDescriptor;
use Larena\Core\Contracts\OperationHandler;
use Larena\Core\Enums\OperationExecutionMode;
use Larena\Core\Enums\OperationRiskClass;
use Larena\Storage\Exceptions\StorageRejected;

/**
 * The storage encryption registry operation: `storage.encryption.explain` reads what a structure's encryption is
 * (StorageEncryptionExplain). Decrypting is no registry operation: plaintext leaves Storage only through
 * StorageFieldReveal, in the application.
 */
final readonly class StorageEncryptionOperationHandlers implements OperationHandler
{
    public function __construct(private StorageEncryptionExplain $explain)
    {
    }

    /** @return array<string, OperationDescriptor> */
    public static function descriptors(): array
    {
        return [
            StorageEncryptionExplain::OPERATION => new OperationDescriptor(
                name: StorageEncryptionExplain::OPERATION,
                executionMode: OperationExecutionMode::Sync,
                accessScope: StorageEncryptionExplain::OPERATION,
                riskClass: OperationRiskClass::Read,
                reversible: true,
            ),
        ];
    }

    /** @return array<string, mixed> */
    public function handle(OperationDescriptor $descriptor, OperationContext $context): array
    {
        if ($descriptor->name !== StorageEncryptionExplain::OPERATION) {
            throw new StorageRejected('unknown_operation');
        }

        return $this->explain->explain($this->string($context, 'scope_ref'), $this->string($context, 'structure_id'), $context->actorId);
    }

    private function string(OperationContext $context, string $key): string
    {
        $value = $context->metadata[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new StorageRejected('invalid_input');
        }

        return $value;
    }
}
