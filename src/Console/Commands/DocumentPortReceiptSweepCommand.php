<?php

declare(strict_types=1);

namespace Larena\Storage\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Larena\Storage\DocumentPort\DocumentPortReceiptSweep;

/**
 * Forgets the document port's client_keys older than --days (30 by default, at least 7); the package schedules it
 * daily. A repeat of a forgotten key is a new operation.
 */
final class DocumentPortReceiptSweepCommand extends Command
{
    protected $signature = 'larena:storage:document-port:sweep {--days=30 : Forget client_keys written more than this many days ago (7 to 3650)}';

    protected $description = 'Forget old client_keys of the Larena Storage document port.';

    public function handle(DatabaseManager $database): int
    {
        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 7, 'max_range' => 3650]]);
        if ($days === false) {
            $this->error('The days must be an integer from 7 to 3650.');

            return self::INVALID;
        }
        $result = (new DocumentPortReceiptSweep($database->connection()))->sweep($days);
        $this->line((string) json_encode($result + ['days' => $days]));

        return self::SUCCESS;
    }
}
