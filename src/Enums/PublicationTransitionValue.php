<?php

declare(strict_types=1);

namespace Larena\Storage\Enums;

/**
 * The transitions the log records.
 *
 * `SweepPublish` is distinct from `Publish` so that history can tell a scheduled
 * publication that fired from one an editor performed: the outcome is the same, the
 * accountability is not.
 */
enum PublicationTransitionValue: string
{
    case Publish = 'publish';
    case Unpublish = 'unpublish';
    case Schedule = 'schedule';
    case Archive = 'archive';
    case SweepPublish = 'sweep_publish';
}
