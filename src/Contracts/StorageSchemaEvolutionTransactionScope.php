<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

/**
 * Opaque transaction-scope identity. Registry WeakMap membership, not object
 * construction, proves that a scope was opened for an exact live connection.
 */
final class StorageSchemaEvolutionTransactionScope
{
}
