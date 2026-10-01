<?php

declare(strict_types=1);

namespace Larena\Storage\Runtime;

use Illuminate\Database\Connection;
use Larena\Storage\Contracts\PublicationState;

/**
 * The version of one published projection entry: the newest log row of its
 * publication plus the newest localized value of the record in its locale, of any
 * revision. Each id only grows within its own table, so the sum grows on every
 * publication transition (a newer revision included) and every localized value
 * written. Reads and publication observers report the same number.
 */
final class ProjectionVersion
{
    public static function of(Connection $connection, string $schemaId, string $recordId, string $scopeRef, string $locale): int
    {
        $transition = (int) $connection->table(DatabasePublicationLifecycle::LOG_TABLE)
            ->where('publication_id', PublicationState::identity($schemaId, $recordId, $scopeRef, $locale))
            ->max('id');
        $localized = 0;
        if ($connection->getSchemaBuilder()->hasTable('larena_storage_localized_values')) {
            $localized = (int) $connection->table('larena_storage_localized_values')
                ->where('schema_id', $schemaId)
                ->where('record_id', $recordId)
                ->where('locale', $locale)
                ->max('id');
        }

        return max(1, $transition + $localized);
    }
}
