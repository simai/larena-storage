<?php

declare(strict_types=1);

namespace Larena\Storage\Runtime;

use Closure;

final readonly class StorageTransactionBoundary
{
    /**
     * @param null|Closure(Closure): mixed $runner
     */
    public function __construct(private ?Closure $runner = null)
    {
    }

    /**
     * @template TResult
     * @param Closure(): TResult $operation
     * @return TResult
     */
    public function run(Closure $operation): mixed
    {
        if ($this->runner !== null) {
            /** @var TResult */
            return ($this->runner)($operation);
        }

        /** @var TResult */
        return $operation();
    }
}
