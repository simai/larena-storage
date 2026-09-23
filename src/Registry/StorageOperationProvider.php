<?php

declare(strict_types=1);

namespace Larena\Storage\Registry;

use Larena\Core\Contracts\OperationDeclaration;
use Larena\Core\Contracts\OperationDescriptor;
use Larena\Core\Contracts\OperationProvider;
use Larena\Core\Exceptions\OperationDeclarationInvalid;
use Larena\Core\Registry\OperationDeclarationLoader;
use Larena\Storage\Runtime\StructureRoleOperationHandlers;

/**
 * Storage's contribution to the core operation registry.
 *
 * Declarations come from this package's `operations.yaml`, descriptors from its
 * handler classes, and the registry refuses to register a pair that disagrees.
 * Registering here is what turns nine of the access operation codes the Batch 3
 * coverage report listed as gaps into covered operations.
 */
final class StorageOperationProvider implements OperationProvider
{
    public const PACKAGE = 'larena/storage';

    public function __construct(
        private readonly ?string $declarationPath = null,
        private readonly OperationDeclarationLoader $loader = new OperationDeclarationLoader(),
    ) {
    }

    /**
     * @return list<array{declaration: OperationDeclaration, descriptor: OperationDescriptor, handler_ref: string}>
     */
    public function operations(): array
    {
        $descriptors = self::descriptors();
        $handlerRefs = self::handlerRefs();
        $operations = [];

        foreach ($this->loader->loadFile($this->path(), self::PACKAGE) as $declaration) {
            $descriptor = $descriptors[$declaration->name] ?? null;
            if ($descriptor === null) {
                throw new OperationDeclarationInvalid(
                    'declared_without_descriptor',
                    $this->path(),
                    $declaration->name . ' is declared but no storage handler binds it',
                );
            }

            $operations[] = [
                'declaration' => $declaration,
                'descriptor' => $descriptor,
                'handler_ref' => $handlerRefs[$declaration->name] ?? 'storage.operation.unbound',
            ];
        }

        return $operations;
    }

    /**
     * @return array<string, OperationDescriptor>
     */
    public static function descriptors(): array
    {
        return StructureRoleOperationHandlers::descriptors();
    }

    /**
     * @return array<string, string>
     */
    public static function handlerRefs(): array
    {
        $refs = [];
        foreach (array_keys(StructureRoleOperationHandlers::descriptors()) as $name) {
            $refs[$name] = 'storage.handler.structure_role';
        }

        return $refs;
    }

    public function path(): string
    {
        return $this->declarationPath ?? dirname(__DIR__, 2) . '/operations.yaml';
    }
}
