<?php

declare(strict_types=1);

namespace Larena\Storage\Console\Commands;

use Illuminate\Console\Command;
use Larena\Storage\Runtime\PublishedKeyIndex;
use Throwable;

/**
 * Builds the published-key table from the published records, set by set (one
 * structure, scope and locale), in chunks. A stopped build resumes after its cursor;
 * a ready set built along the current locale chain is left alone, so a second run
 * changes nothing. A set built along another chain — the locale fallback changed —
 * or one that met a conflict is built again from scratch.
 *
 *   larena:storage:published-keys:rebuild                         every set that needs it
 *   larena:storage:published-keys:rebuild --schema=site.pages     one structure
 *   larena:storage:published-keys:rebuild --fresh                 every set from scratch
 */
final class PublishedKeyRebuildCommand extends Command
{
    protected $signature = 'larena:storage:published-keys:rebuild
        {--schema= : Only this structure (schema id)}
        {--scope= : Only this scope}
        {--locale= : Only this locale}
        {--chunk=500 : Published records per transaction}
        {--fresh : Rebuild ready sets too}';

    protected $description = 'Build the Larena Storage published-key table (unique keys such as slugs per structure, scope and locale) from the published records; resumable and idempotent.';

    public function handle(PublishedKeyIndex $index): int
    {
        $chunk = filter_var($this->option('chunk'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => PublishedKeyIndex::MAX_CHUNK]]);
        if ($chunk === false) {
            $this->error('The chunk must be an integer from 1 to ' . PublishedKeyIndex::MAX_CHUNK . '.');

            return self::INVALID;
        }
        if (!$index->available()) {
            $this->error('The published-key tables are not migrated.');

            return self::FAILURE;
        }

        $failed = 0;
        $built = 0;
        $targets = $index->targets($this->filter('schema'), $this->filter('scope'), $this->filter('locale'));
        foreach ($targets as $target) {
            $label = $target['schema_id'] . ' ' . $target['scope_ref'] . '/' . $target['locale'];
            try {
                $result = $index->build(
                    $target['schema_id'],
                    $target['scope_ref'],
                    $target['locale'],
                    $chunk,
                    (bool) $this->option('fresh'),
                    fn (string $line) => $this->line($line, null, 'v'),
                );
            } catch (Throwable $exception) {
                $failed++;
                $this->error($label . ': ' . $exception->getMessage());

                continue;
            }
            if ($result['key_field'] === null || $result['skipped']) {
                continue;
            }
            $built++;
            $this->line(sprintf(
                '%s: %s by %s, %d records%s%s',
                $label,
                $result['state'],
                $result['key_field'],
                $result['records'],
                $result['conflicts'] > 0 ? ', ' . $result['conflicts'] . ' conflicting keys (lookups scan until they are resolved)' : '',
                $result['resumed'] ? ' (resumed)' : '',
            ));
        }
        if ($built === 0 && $failed === 0) {
            $this->line('Nothing to build.');
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function filter(string $option): ?string
    {
        $value = $this->option($option);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
