<?php

declare(strict_types=1);

namespace Larena\Storage\Enums;

/**
 * What happens to the other side when a related record is deleted.
 *
 * The choice is part of the versioned schema rather than a runtime argument: the
 * same delete must not behave differently depending on who asks.
 */
enum RelationDeletePolicy: string
{
    /** Refuse the delete while anything still points here. */
    case Restrict = 'restrict';

    /** Delete the descendants too. */
    case Cascade = 'cascade';

    /** Keep the descendants and detach the edge, so they stay reachable. */
    case Detach = 'detach';
}
