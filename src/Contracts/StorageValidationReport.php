<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

interface StorageValidationReport
{
    public function isValid(): bool;

    public function blocksMutation(): bool;

    /**
     * @return list<string>
     */
    public function errorCodes(): array;

    /**
     * @return array<string, scalar|null>
     */
    public function safeDiagnostics(): array;
}
