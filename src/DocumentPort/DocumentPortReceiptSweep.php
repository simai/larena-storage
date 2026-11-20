<?php

declare(strict_types=1);

namespace Larena\Storage\DocumentPort;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

/**
 * Forgets the client_keys of the document port past the retention window (RETENTION_DAYS by default).
 *
 * A client_key is kept so that a repeat after a lost answer is the same operation (simai.document-port 1.0.0). Past
 * the window a repeat of that key is a new operation: a save is compared with the revision it names as any save is
 * (stale-revision when the draft moved), a restore makes a new draft, a publish or a rollback a new publication.
 *
 * Only the idempotency answer goes. A receipt that also holds a fact of the history keeps its row without the key:
 * a publication (its id, languages and the publication a rollback repeats: history.list and the live publication read
 * them) and the revision a restore came from. A receipt of a plain save holds nothing else and is removed.
 */
final class DocumentPortReceiptSweep
{
    /**
     * 30 days: the editor repeats a lost answer when it is back (after a reconnect, a sign-in after session-expired, a
     * laptop opened after a holiday), so a key must outlive a long weekend and a vacation with room to spare. A receipt
     * is a short row, so a month costs little.
     */
    public const RETENTION_DAYS = 30;

    private const CHUNK = 500;

    public function __construct(private readonly ConnectionInterface $database)
    {
    }

    /**
     * @return array{removed: int, forgotten: int, before: string} removed: plain save receipts deleted; forgotten:
     *         receipts whose key was dropped, their history kept; before: the cut-off (UTC)
     */
    public function sweep(int $days = self::RETENTION_DAYS, ?DateTimeImmutable $now = null): array
    {
        if ($days < 7 || $days > 3650) {
            throw new InvalidArgumentException('The retention must be from 7 to 3650 days: a client retry must find its key.');
        }
        $before = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'))->modify('-' . $days . ' days')->format('Y-m-d H:i:s');
        $removed = 0;
        $forgotten = 0;
        // In chunks of ids, so a large backlog never holds one long lock.
        do {
            $ids = $this->database->table(DatabaseDocumentPortStore::RECEIPTS_TABLE)
                ->whereNotNull('client_key')->where('created_at', '<', $before)
                ->orderBy('id')->limit(self::CHUNK)->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
            if ($ids === []) {
                break;
            }
            $this->database->transaction(function () use ($ids, &$removed, &$forgotten): void {
                $removed += $this->database->table(DatabaseDocumentPortStore::RECEIPTS_TABLE)->whereIn('id', $ids)
                    ->whereNull('publication_id')->whereNull('restored_from')->delete();
                $forgotten += $this->database->table(DatabaseDocumentPortStore::RECEIPTS_TABLE)->whereIn('id', $ids)
                    ->update(['client_key' => null, 'params_hash' => null]);
            });
        } while (count($ids) === self::CHUNK);

        return ['removed' => $removed, 'forgotten' => $forgotten, 'before' => $before];
    }
}
