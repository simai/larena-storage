<?php

declare(strict_types=1);

namespace Larena\Storage\Console\Commands;

use Illuminate\Console\Command;
use Larena\Storage\Contracts\StorageWorkbenchEncryption;
use Larena\Storage\Exceptions\StorageConflict;
use Larena\Storage\Exceptions\StorageRejected;

/**
 * Rotates the data key of one structure's storage (DEK rotation), as the person named by --actor (who needs
 * `storage.encryption.manage` and the structure's update right in the scope): a new key becomes active at once, and
 * the `storage.field.encryption.rewrite` run seals again every value the old key sealed (every version and
 * translation), then deletes the old key. A small storage is rewritten here; a large one in the queue.
 *
 *   larena:storage:encryption:rotate workbench.people --scope=scope:site.main --actor=actor:admin
 */
final class StorageEncryptionRotateCommand extends Command
{
    protected $signature = 'larena:storage:encryption:rotate
        {structure : The structure id (workbench.…)}
        {--scope= : The scope the structure belongs to}
        {--actor= : The person the rotation runs as}';

    protected $description = 'Rotate the data key of a Larena Storage structure: a new key, and every encrypted value sealed again under it.';

    public function handle(StorageWorkbenchEncryption $encryption): int
    {
        $scope = $this->option('scope');
        $actor = $this->option('actor');
        if (!is_string($scope) || $scope === '' || !is_string($actor) || $actor === '') {
            $this->error('Name the scope (--scope) and the person the rotation runs as (--actor).');

            return self::INVALID;
        }
        try {
            $structure = $encryption->rotateEncryptionKey($scope, (string) $this->argument('structure'), $actor);
        } catch (StorageRejected|StorageConflict $exception) {
            $this->error($exception->reasonCode);

            return self::FAILURE;
        }
        $this->line((string) json_encode([
            'structure_id' => $structure->structureId,
            'run' => $structure->encryptionRun?->toArray(),
        ], JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
