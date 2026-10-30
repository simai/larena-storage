<?php

declare(strict_types=1);

namespace Larena\Storage\Runtime;

use InvalidArgumentException;
use Larena\Storage\Contracts\LocalizedValueGuard;
use Larena\Storage\Contracts\LocalizedValueObserver;

/**
 * Several derived indexes that hear of every localized value write, in its
 * transaction and in order: the published-key table, then the site витрина. The
 * localized values ask the chain which indexes it holds, and silence the ones it
 * does not.
 */
final readonly class LocalizedValueObserverChain implements LocalizedValueObserver
{
    /** @var list<LocalizedValueObserver> */
    private array $observers;

    /** @param list<LocalizedValueObserver> $observers */
    public function __construct(array $observers)
    {
        foreach ($observers as $observer) {
            if ($observer instanceof self) {
                throw new InvalidArgumentException('storage_localized_value_observer_chain_nested');
            }
        }
        $this->observers = $observers;
    }

    /** @phpstan-impure */
    public function localizedValuesWritten(string $schemaId, string $recordId, int $revision, string $locale): void
    {
        foreach ($this->observers as $observer) {
            $observer->localizedValuesWritten($schemaId, $recordId, $revision, $locale);
        }
    }

    /**
     * Asks the observers that guard the writes.
     *
     * @param array<string, mixed> $values
     * @phpstan-impure
     */
    public function assertMayWrite(string $schemaId, string $recordId, int $revision, string $locale, #[\SensitiveParameter] array $values): void
    {
        foreach ($this->observers as $observer) {
            if ($observer instanceof LocalizedValueGuard) {
                $observer->assertMayWriteLocalized($schemaId, $recordId, $revision, $locale, $values);
            }
        }
    }

    /** @param class-string $class */
    public function has(string $class): bool
    {
        foreach ($this->observers as $observer) {
            if ($observer instanceof $class) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether an observer is or holds one of this class.
     *
     * @param class-string $class
     */
    public static function maintains(?LocalizedValueObserver $observer, string $class): bool
    {
        return $observer instanceof $class || ($observer instanceof self && $observer->has($class));
    }
}
