<?php

declare(strict_types=1);

namespace Larena\Storage\Runtime;

use Larena\Storage\Contracts\StorageValidationReport;

final readonly class StorageValidationResult implements StorageValidationReport
{
    /**
     * @param list<string> $errorCodes
     * @param array<string, scalar|null> $safeDiagnostics
     */
    private function __construct(
        private bool $valid,
        private bool $blocksMutation,
        private array $errorCodes,
        private array $safeDiagnostics = []
    ) {
    }

    /**
     * @param array<string, scalar|null> $safeDiagnostics
     */
    public static function valid(array $safeDiagnostics = []): self
    {
        return new self(true, false, [], $safeDiagnostics);
    }

    /**
     * @param list<string> $errorCodes
     * @param array<string, scalar|null> $safeDiagnostics
     */
    public static function invalid(array $errorCodes, array $safeDiagnostics = []): self
    {
        return new self(false, true, $errorCodes, $safeDiagnostics);
    }

    public function isValid(): bool
    {
        return $this->valid;
    }

    public function blocksMutation(): bool
    {
        return $this->blocksMutation;
    }

    /**
     * @return list<string>
     */
    public function errorCodes(): array
    {
        return $this->errorCodes;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function safeDiagnostics(): array
    {
        return $this->safeDiagnostics;
    }
}
