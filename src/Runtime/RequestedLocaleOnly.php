<?php

declare(strict_types=1);

namespace Larena\Storage\Runtime;

use Larena\Storage\Contracts\LocaleFallbackChain;
use Larena\Storage\Contracts\LocaleFallbackResolver;

/**
 * The chain when no Lang policy is bound: the requested locale alone, so a read
 * never shows another locale's value that nobody chose.
 */
final class RequestedLocaleOnly implements LocaleFallbackResolver
{
    public function chainFor(string $requestedLocale): LocaleFallbackChain
    {
        return LocaleFallbackChain::of($requestedLocale);
    }
}
