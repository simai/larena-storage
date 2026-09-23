<?php

declare(strict_types=1);

namespace Larena\Storage\Enums;

/**
 * What a relation means.
 *
 * A `reference` points at another record. A `tree_parent` edge additionally
 * places the record in a hierarchy, which is why only that kind carries a path,
 * a depth and the single-parent guarantee.
 */
enum RelationKind: string
{
    case Reference = 'reference';
    case TreeParent = 'tree_parent';

    public function isTree(): bool
    {
        return $this === self::TreeParent;
    }
}
