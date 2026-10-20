<?php

declare(strict_types=1);

namespace Larena\Storage\Enums;

/**
 * How a record list counts what it matched.
 *
 * - `Auto`: exact while the count stays under a threshold and a time budget, an
 *   estimate («~N») above it. The page says which through `countExact`.
 * - `Exact`: always exact, however long it takes. Bulk actions use it, because
 *   they confirm a number.
 * - `Skip`: no count. `matchedCount` is then what the list has seen so far
 *   (exact only on the last page); for quick search and option pickers.
 */
enum StorageWorkbenchCountMode: string
{
    case Auto = 'auto';
    case Exact = 'exact';
    case Skip = 'skip';
}
