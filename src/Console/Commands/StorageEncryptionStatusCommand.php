<?php

declare(strict_types=1);

namespace Larena\Storage\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Larena\Storage\Encryption\StorageDataKeys;
use Larena\Storage\Encryption\StorageFieldEncryptionRuns;
use Larena\Storage\Runtime\StorageMetrics;
use Throwable;

/**
 * What the encryption of the storages is, without any key material or value: the health of the data keys (`ok`,
 * `warning` while a key is still wrapped by a previous KEK — run `larena:storage:encryption:rewrap` — or cannot be
 * unwrapped here, `unavailable` without sodium or a KEK), and for each workbench structure with keys or runs its
 * keys (id, status, KEK id, unwrappable) and its latest encryption run. Records the gauges
 * `storage.encryption.previous_kek_keys` and `storage.encryption.unwrappable_keys` for larena/monitoring. Exits 1 on a
 * warning.
 *
 *   larena:storage:encryption:status                      every structure
 *   larena:storage:encryption:status workbench.people --scope=scope:site.main
 */
final class StorageEncryptionStatusCommand extends Command
{
    protected $signature = 'larena:storage:encryption:status
        {structure? : One structure id (workbench.…)}
        {--scope= : Only the structures of this scope}';

    protected $description = 'Show the encryption of Larena Storage structures: data keys, KEK health and encryption runs.';

    public function handle(StorageDataKeys $keys, DatabaseManager $database): int
    {
        $health = $keys->health();
        if ($this->laravel->bound(StorageMetrics::class)) {
            $this->laravel->make(StorageMetrics::class)->encryptionKeys($health['previous_kek_keys'], $health['unwrappable_missing']);
        }
        $connection = $database->connection();
        $runs = new StorageFieldEncryptionRuns($connection);
        $structures = [];
        try {
            $query = $connection->table('larena_storage_workbench_structures')->orderBy('scope_ref')->orderBy('structure_id');
            $scope = $this->option('scope');
            if (is_string($scope) && $scope !== '') {
                $query->where('scope_ref', $scope);
            }
            $structure = $this->argument('structure');
            if (is_string($structure) && $structure !== '') {
                $query->where('structure_id', $structure);
            }
            foreach ($query->get(['scope_ref', 'structure_id', 'storage_schema_id']) as $row) {
                $schemaId = (string) $row->storage_schema_id;
                $described = $keys->describe($schemaId);
                $latest = $runs->latest([$schemaId])[$schemaId] ?? null;
                if ($described === [] && $latest === null) {
                    continue;
                }
                $structures[] = [
                    'scope_ref' => (string) $row->scope_ref,
                    'structure_id' => (string) $row->structure_id,
                    'keys' => $described,
                    'run' => $latest?->contract()->toArray(),
                ];
            }
        } catch (Throwable $exception) {
            $this->error('The structures could not be read: ' . $exception->getMessage());

            return self::FAILURE;
        }
        $this->line((string) json_encode(['health' => $health, 'structures' => $structures], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        if ($health['state'] !== 'ok') {
            $this->warn('Data keys need attention: ' . implode(', ', $health['reasons']));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
