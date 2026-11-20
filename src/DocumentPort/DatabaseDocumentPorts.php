<?php

declare(strict_types=1);

namespace Larena\Storage\DocumentPort;

use Illuminate\Database\ConnectionInterface;
use Larena\Storage\Contracts\PublicationLifecycle;
use Larena\Storage\Contracts\StorageWorkbench;
use Larena\Storage\Exceptions\StorageRejected;
use InvalidArgumentException;
use Throwable;

/**
 * Opens the document port of one bound record field for one reader, as each request does: no reader (the session
 * ended) is session-expired, a reader who may not read the record is access-denied, a record that is not there is
 * not-found, a store that cannot be reached is storage-unavailable; else the port over the record with the rights
 * of the reader (an archived record is not written). The element announces `history`, and `publish` when the binding
 * names a site; `levels` (area inheritance) comes later, so every page carries all of its areas.
 */
final readonly class DatabaseDocumentPorts
{
    public function __construct(
        private ConnectionInterface $database,
        private StorageWorkbench $workbench,
        private PublicationLifecycle $publications,
        private DocumentPortRights $rights,
    ) {
    }

    public function open(DocumentPortBinding $binding, ?string $actor): DocumentPort
    {
        if ($actor === null || trim($actor) === '') {
            return DocumentPort::refusing('session-expired');
        }
        try {
            $rights = $this->rights->rights($binding, $actor);
            if ($rights === null) {
                return DocumentPort::refusing('access-denied');
            }
            $record = $this->workbench->readRecord($binding->scopeRef, $binding->structureId, $binding->recordId, $actor);
        } catch (StorageRejected $rejected) {
            $reason = DocumentPort::reason($rejected->reasonCode);

            return DocumentPort::refusing(in_array($reason, ['access-denied', 'not-found', 'storage-unavailable'], true) ? $reason : 'not-found');
        } catch (InvalidArgumentException) {
            // A record id or structure id the workbench does not accept names no record.
            return DocumentPort::refusing('not-found');
        } catch (Throwable) {
            return DocumentPort::refusing('storage-unavailable');
        }
        if ($record->state !== 'active') {
            $rights = ['save' => false, 'publish' => false];
        }
        $element = new DocumentPortElement($binding->target, $binding->languages, $binding->capabilities(), $binding->limits, $rights);

        return DocumentPort::bound($element, new DatabaseDocumentPortStore($this->database, $this->workbench, $this->publications, $binding, $actor));
    }
}
