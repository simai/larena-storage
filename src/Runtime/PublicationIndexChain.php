<?php

declare(strict_types=1);

namespace Larena\Storage\Runtime;

use InvalidArgumentException;
use Larena\Storage\Contracts\PublicationIndex;
use Larena\Storage\Contracts\PublicationState;

/**
 * Several derived indexes kept in a publication's transaction, in order: the
 * published-key table, then the site витрина. A refusal by any of them rolls the
 * transition back. The lifecycle asks the chain which indexes it holds, and silences
 * the ones it does not (their tables cannot follow a transition they never hear of).
 */
final readonly class PublicationIndexChain implements PublicationIndex
{
    /** @var list<PublicationIndex> */
    private array $indexes;

    /** @param list<PublicationIndex> $indexes */
    public function __construct(array $indexes)
    {
        foreach ($indexes as $index) {
            if ($index instanceof self) {
                throw new InvalidArgumentException('storage_publication_index_chain_nested');
            }
        }
        $this->indexes = $indexes;
    }

    /** @phpstan-impure */
    public function publicationWritten(PublicationState $state): void
    {
        foreach ($this->indexes as $index) {
            $index->publicationWritten($state);
        }
    }

    /** @param class-string $class */
    public function has(string $class): bool
    {
        return $this->find($class) !== null;
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T|null
     */
    public function find(string $class): ?object
    {
        foreach ($this->indexes as $index) {
            if ($index instanceof $class) {
                return $index;
            }
        }

        return null;
    }

    /**
     * The index of this class that a lifecycle's index is or holds.
     *
     * @template T of object
     * @param class-string<T> $class
     * @return T|null
     */
    public static function member(?PublicationIndex $index, string $class): ?object
    {
        if ($index instanceof $class) {
            return $index;
        }

        return $index instanceof self ? $index->find($class) : null;
    }
}
