<?php

declare(strict_types=1);

namespace Larena\Storage\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Larena\Storage\Demo\StorageDemoData;
use Larena\Storage\Exceptions\StorageRejected;
use Throwable;

/**
 * Installs the storage demo (StorageDemoData) into a scope as the person named by --actor, who needs the rights to
 * create storages and records there. Running it again only adds what is missing. The first run offers the same demo
 * as «Установить демо-данные».
 *
 *   larena:storage:demo --scope=site:main --actor=user:admin_identity:1
 *   … --records=60   fewer catalog records (1..480, 450 by default)
 *   … --json         the machine-readable receipt only
 */
final class StorageDemoCommand extends Command
{
    protected $signature = 'larena:storage:demo
        {--actor= : The administrator the demo is written as (required)}
        {--scope= : The scope of that administrator to write it into (required)}
        {--records=450 : Number of catalog records (1..480)}
        {--json : Emit the machine-readable receipt only}';

    protected $description = 'Create or complete the storage demo: a catalog with every field type and its sections.';

    public function handle(StorageDemoData $demo, ExceptionHandler $errors): int
    {
        $actor = trim((string) $this->option('actor'));
        $scope = trim((string) $this->option('scope'));
        $records = filter_var($this->option('records'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => StorageDemoData::MAX_RECORDS]]);
        // Both are named explicitly: a default administrator once filled another administrator's scope.
        if (preg_match('/\Auser:admin_identity:[1-9][0-9]*\z/D', $actor) !== 1 || $scope === '' || $records === false) {
            return $this->refuse('storage_demo_input_invalid');
        }
        try {
            $receipt = $demo->install($actor, $scope, $records);
        } catch (StorageRejected $rejected) {
            return $this->refuse($rejected->reasonCode);
        } catch (Throwable $failure) {
            $errors->report($failure);

            return $this->refuse('storage_demo_failed');
        }
        $this->line(json_encode(['schema' => 'larena.storage_demo', 'status' => 'ready', ...$receipt],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | ($this->option('json') ? 0 : JSON_PRETTY_PRINT)));

        return self::SUCCESS;
    }

    private function refuse(string $reason): int
    {
        $this->line(json_encode(['schema' => 'larena.storage_demo', 'status' => 'refused', 'reason_code' => $reason], JSON_THROW_ON_ERROR));

        return self::FAILURE;
    }
}
