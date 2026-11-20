<?php

declare(strict_types=1);

namespace Larena\Storage\DocumentPort;

/**
 * What a reader may do with a bound element, asked on every load: null when they may not read it at all (the port
 * answers access-denied); else {save, publish} and, when they may write only some languages, `languages`.
 */
interface DocumentPortRights
{
    /** @return array{save: bool, publish: bool, languages?: list<string>}|null */
    public function rights(DocumentPortBinding $binding, string $actor): ?array;
}
