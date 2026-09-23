<?php

declare(strict_types=1);

namespace Larena\Storage\Runtime;

use Larena\Storage\Exceptions\RelationRejected;

/**
 * A materialized path through a record tree.
 *
 * The path is what makes a subtree read a prefix match and an ancestor read a
 * string split, so neither needs a recursive query. That is the whole reason this
 * class exists: MySQL 5.7 has no recursive CTE, and ordinary shared hosting still
 * runs it.
 */
final readonly class RelationPath
{
    public const SEPARATOR = '/';

    public const MAX_DEPTH = 32;

    public const MAX_LENGTH = 2048;

    /** @param list<string> $segments */
    private function __construct(public array $segments)
    {
    }

    public static function root(string $recordId): self
    {
        return new self([self::assertSegment($recordId)]);
    }

    public static function parse(string $path): self
    {
        // explode() always returns at least one element, so an empty path shows up
        // as a single empty segment and assertSegment() is what rejects it.
        $segments = explode(self::SEPARATOR, $path);

        foreach ($segments as $segment) {
            self::assertSegment($segment);
        }

        if (count($segments) > self::MAX_DEPTH) {
            throw new RelationRejected('depth_exceeded', 'A relation path may not exceed ' . self::MAX_DEPTH . ' levels.');
        }

        return new self($segments);
    }

    public function child(string $recordId): self
    {
        if (count($this->segments) + 1 > self::MAX_DEPTH) {
            throw new RelationRejected('depth_exceeded', 'A relation path may not exceed ' . self::MAX_DEPTH . ' levels.');
        }

        $child = new self([...$this->segments, self::assertSegment($recordId)]);
        if (strlen($child->toString()) > self::MAX_LENGTH) {
            throw new RelationRejected('depth_exceeded', 'A relation path may not exceed ' . self::MAX_LENGTH . ' characters.');
        }

        return $child;
    }

    public function depth(): int
    {
        return count($this->segments);
    }

    public function toString(): string
    {
        return implode(self::SEPARATOR, $this->segments);
    }

    public function leaf(): string
    {
        return $this->segments[count($this->segments) - 1];
    }

    /**
     * The record ids above this one, nearest ancestor last.
     *
     * @return list<string>
     */
    public function ancestorIds(): array
    {
        return array_slice($this->segments, 0, -1);
    }

    public function contains(string $recordId): bool
    {
        return in_array($recordId, $this->segments, true);
    }

    /**
     * The prefix a descendant path starts with. Kept separate from toString() so
     * that a query for descendants cannot accidentally match a sibling whose id
     * begins with the same characters.
     */
    public function descendantPrefix(): string
    {
        return $this->toString() . self::SEPARATOR;
    }

    public function isDescendantOf(self $other): bool
    {
        return str_starts_with($this->toString(), $other->descendantPrefix());
    }

    /**
     * The same depth with every ancestor hidden.
     *
     * A materialized path spells out every ancestor id, so returning it to a
     * caller who may not read those ancestors would defeat the very filter that
     * hid them. The mask keeps what is structural — how deep this record sits —
     * and drops what is an identifier.
     */
    public function masked(): string
    {
        $masked = array_fill(0, max(0, count($this->segments) - 1), '*');
        $masked[] = $this->leaf();

        return implode(self::SEPARATOR, $masked);
    }

    private static function assertSegment(string $segment): string
    {
        if ($segment === '' || str_contains($segment, self::SEPARATOR)) {
            throw new RelationRejected('invalid_path', 'A relation path segment must be a non-empty record id.');
        }

        return $segment;
    }
}
