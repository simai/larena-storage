<?php

declare(strict_types=1);

namespace Larena\Storage\Console\Commands;

use Illuminate\Console\Command;
use Larena\Storage\Contracts\StorageSecurityEvent;
use Larena\Storage\Contracts\StorageSecurityEventSink;
use Larena\Storage\Encryption\StorageDataKeys;
use Larena\Storage\Exceptions\StorageRejected;
use Throwable;

/**
 * KEK rotation: after APP_KEY changed (the old one kept in APP_PREVIOUS_KEYS), every data key still wrapped by a
 * previous key-encryption key is wrapped again by the current one, in one transaction (all or none). The values are
 * not touched. Audited (`storage.field.encryption.rewrapped`: how many keys, the KEK id, never key material). Once
 * it ran, the previous APP_KEY is no longer needed for Storage.
 *
 *   larena:storage:encryption:rewrap [--actor=system:storage-encryption]
 */
final class StorageEncryptionRewrapCommand extends Command
{
    public const EVENT = 'storage.field.encryption.rewrapped';

    protected $signature = 'larena:storage:encryption:rewrap
        {--actor=system:storage-encryption : Who the audit event names}';

    protected $description = 'Wrap every Larena Storage data key again with the key-encryption key of the current APP_KEY.';

    public function handle(StorageDataKeys $keys, StorageSecurityEventSink $events): int
    {
        $actor = $this->option('actor');
        if (!is_string($actor) || trim($actor) === '' || strlen($actor) > 191) {
            $this->error('Name who the audit event names (--actor).');

            return self::INVALID;
        }
        try {
            $result = $keys->rewrap();
        } catch (StorageRejected $exception) {
            $this->error($exception->reasonCode);

            return self::FAILURE;
        }
        try {
            $events->emit(new StorageSecurityEvent(
                'encryption',
                self::EVENT,
                $actor,
                'storage-data-keys',
                'storage-rewrap-' . bin2hex(random_bytes(12)),
                ['rewrapped' => $result['rewrapped'], 'kek_id' => $result['kek_id']],
            ));
        } catch (Throwable) {
            $this->warn('The keys were rewrapped; the audit event could not be written.');
        }
        $this->line((string) json_encode($result, JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
