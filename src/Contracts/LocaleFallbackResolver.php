<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

/**
 * Where a read gets its locale chain from. Lang owns the fallback policy; the
 * application binds this port to it, so localized values fall back in the same
 * order as interface text. Storage itself never decides the order.
 */
interface LocaleFallbackResolver
{
    public function chainFor(string $requestedLocale): LocaleFallbackChain;
}
