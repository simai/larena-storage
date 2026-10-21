<?php

declare(strict_types=1);

namespace Larena\Storage\View\Site;

use Illuminate\Database\Connection;
use JsonException;
use Larena\Storage\Contracts\LocalizedValueObserver;
use Larena\Storage\Contracts\PublicationIndex;
use Larena\Storage\Contracts\PublicationState;
use Larena\Storage\Enums\PublicationStateValue;
use Larena\Storage\Exceptions\StorageRejected;
use Larena\Storage\Runtime\DatabasePublicationLifecycle;
use Larena\Storage\Runtime\DatabaseReadContracts;
use Larena\Storage\View\StorageViewDdl;
use Larena\Storage\View\StorageViewFieldOptions;
use Larena\Storage\View\StorageViewLayout;
use Larena\Storage\View\StorageViewNaming;
use Larena\Storage\View\StorageViewShapeVerifier;
use Larena\Storage\View\StorageViewTableShape;
use Larena\Storage\View\StorageViewWriter;
use Throwable;

/**
 * Keeps the site витрина in step with publication, in the transition's own
 * transaction (the same hooks as the published-key index).
 *
 * - A transition rewrites the record's row in its slot (the storage, scope and
 *   locale): the row goes, and a published head gets the entry the read contracts
 *   return for it, built from rows read with a shared lock.
 * - A translation (of any revision: it moves the projection version too) rewrites the
 *   record's row in every slot whose locale chain holds the written locale, with the
 *   record's publication rows locked, so a publication and a translation of one
 *   record never pass each other.
 * - The first publication in a scope and locale makes its slot (a row, never DDL);
 *   with at most INLINE_BUILD_LIMIT published records the slot is filled in the same
 *   transaction, otherwise it stays pending until the rebuild fills it.
 * - The table of a storage is made before the publication, outside any transaction
 *   (`prepare`), and only for a storage with a public field.
 *
 * It never writes what it cannot vouch for: a slot whose locale chain changed, a
 * registration whose public fields changed under it, a row that cannot be projected,
 * each silences what it concerns (pending) instead. Until slice 3 nothing reads the
 * site витрина; the read contracts still answer from the versions.
 */
final class PublishedSiteViewIndex implements PublicationIndex, LocalizedValueObserver
{
    /** A new slot with at most this many published records is filled inside the publication that makes it. */
    public const INLINE_BUILD_LIMIT = 500;

    private readonly PublishedSiteViewRegistry $registry;
    private readonly PublishedSiteViewProjector $projector;
    private readonly StorageViewWriter $writer;
    private readonly ?StorageViewDdl $ddl;

    public function __construct(
        private readonly Connection $connection,
        private readonly DatabaseReadContracts $readContracts,
        ?PublishedSiteViewRegistry $registry = null,
        ?StorageViewDdl $ddl = null,
        bool $detectDdl = true,
        // A new slot with at most this many published records is filled inline.
        private readonly int $inlineBuildLimit = self::INLINE_BUILD_LIMIT,
    ) {
        $this->registry = $registry ?? new PublishedSiteViewRegistry($connection);
        $this->projector = new PublishedSiteViewProjector();
        $this->writer = new StorageViewWriter($connection);
        $this->ddl = $ddl ?? ($detectDdl ? StorageViewDdl::for($connection) : null);
    }

    public function connection(): Connection
    {
        return $this->connection;
    }

    public function registry(): PublishedSiteViewRegistry
    {
        return $this->registry;
    }

    public function readContracts(): DatabaseReadContracts
    {
        return $this->readContracts;
    }

    public function projector(): PublishedSiteViewProjector
    {
        return $this->projector;
    }

    public function writer(): StorageViewWriter
    {
        return $this->writer;
    }

    public function ddl(): ?StorageViewDdl
    {
        return $this->ddl;
    }

    /** False on a driver without витрина DDL, or before the site registry is migrated. */
    public function enabled(): bool
    {
        return $this->ddl !== null && $this->registry->available();
    }

    /**
     * Before a publication's transaction, outside any: the storage's site table and
     * its registration, when it has neither yet and has a public field. Best effort;
     * without them the publication simply writes no site row (and no slot claims to
     * hold it).
     *
     * @phpstan-impure
     */
    public function prepare(string $schemaId): void
    {
        try {
            if ($this->connection->transactionLevel() > 0 || !$this->enabled() || $this->ddl === null
                || $this->registry->find($schemaId, true) !== null) {
                return;
            }
            $schema = $this->currentSchema($schemaId);
            if ($schema === null || PublishedSiteViewProjector::publicFields($schema['fields']) === []) {
                return;
            }
            $layout = $this->projector->layout($schema['fields'], $schema['version'], $schema['options']);
            $table = StorageViewNaming::siteTable($schemaId);
            // Nothing names the table yet, so nothing writes it; another process may be making it too,
            // so it is completed, never dropped here (a table left with another layout waits for the rebuild).
            $this->ddl->ensureTable($table, $layout, StorageViewTableShape::Site);
            if (!(new StorageViewShapeVerifier($this->ddl))->matches($table, $layout, true, StorageViewTableShape::Site)) {
                return;
            }
            $this->registry->register(PublishedSiteViewRegistration::create(
                $schemaId,
                $layout,
                PublishedSiteViewProjector::publicFieldsHash($schema['fields'], $schema['options']),
                PublishedSiteViewRegistration::STATE_READY,
            ));
        } catch (Throwable) {
        }
    }

    /** @phpstan-impure */
    public function publicationWritten(PublicationState $state): void
    {
        $registration = $this->writable($state->schemaId);
        if ($registration === null) {
            return;
        }
        $published = $state->isPublished() && $state->publishedRevision !== null;
        if (!$published) {
            // Nothing is published here: the record leaves its slot, if the slot exists.
            $slot = $this->registry->slot($state->schemaId, $state->scopeRef, $state->locale, 'shared');
            if ($slot !== null && $this->current($slot)) {
                $this->write($registration, $slot, $state->recordId, null);
            }

            return;
        }
        [$slot, $created] = $this->registry->slotFor($state->schemaId, $state->scopeRef, $state->locale);
        if ($created) {
            $this->buildInline($registration, $slot);

            return;
        }
        if ($this->current($slot)) {
            $this->write($registration, $slot, $state->recordId, $state->publishedRevision);
        }
    }

    /** @phpstan-impure */
    public function localizedValuesWritten(string $schemaId, string $recordId, int $revision, string $locale): void
    {
        $registration = $this->writable($schemaId);
        if ($registration === null) {
            return;
        }
        // A translation of any revision moves the projection version of the record's entries in
        // its locale; one of the published revision changes their values along every chain that holds it.
        foreach ($this->placements($schemaId, [$recordId]) as $placement) {
            if (!in_array($locale, $this->readContracts->localeChain($placement['locale'])->locales, true)) {
                continue;
            }
            $this->refresh($registration, $placement);
        }
    }

    /**
     * Rewrites the rows of these records in every slot where they are published, with
     * their publication rows locked: what a change of their localized values that was
     * not a write (a history prune) needs.
     *
     * @param list<string> $recordIds
     * @phpstan-impure
     */
    public function refreshRecords(string $schemaId, array $recordIds): void
    {
        $registration = $this->writable($schemaId);
        if ($registration === null || $recordIds === []) {
            return;
        }
        foreach ($this->placements($schemaId, $recordIds) as $placement) {
            $this->refresh($registration, $placement);
        }
    }

    /**
     * The current schema version of a storage, its fields and their витрина options
     * (the workbench structure's, or null for the defaults).
     *
     * @return array{version: int, fields: list<array<string, mixed>>, options: ?StorageViewFieldOptions}|null
     * @phpstan-impure
     */
    public function currentSchema(string $schemaId, bool $lock = false): ?array
    {
        $query = $this->connection->table(DatabaseReadContracts::SCHEMA_VERSIONS_TABLE)
            ->where('schema_id', $schemaId)
            ->orderByDesc('version');
        if ($lock && $this->connection->transactionLevel() > 0) {
            $query->sharedLock();
        }
        $row = $query->first(['version', 'definition']);
        if ($row === null) {
            return null;
        }
        $row = (array) $row;
        try {
            $definition = json_decode((string) $row['definition'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        $fields = [];
        foreach (is_array($definition) && is_array($definition['fields'] ?? null) ? $definition['fields'] : [] as $field) {
            if (is_array($field)) {
                $fields[] = $field;
            }
        }

        return ['version' => (int) $row['version'], 'fields' => $fields, 'options' => StorageViewFieldOptions::load($this->connection, $schemaId)];
    }

    /** The hash of a storage's current public fields, or null when it has none (or no schema). */
    public function currentHash(string $schemaId, bool $lock = false): ?string
    {
        $schema = $this->currentSchema($schemaId, $lock);
        if ($schema === null || PublishedSiteViewProjector::publicFields($schema['fields']) === []) {
            return null;
        }

        return PublishedSiteViewProjector::publicFieldsHash($schema['fields'], $schema['options']);
    }

    /**
     * The rows of a page of published heads in one slot, projected. A head whose
     * entry cannot be projected makes the page null: the caller silences the slot.
     *
     * @param list<array{record_id: string, revision: int}> $heads
     * @return list<array{row: array<string, mixed>, multi: list<array<string, mixed>>, search: array<string, mixed>|null}>|null
     * @phpstan-impure
     */
    public function projections(PublishedSiteViewRegistration $registration, PublishedSiteViewSlot $slot, array $heads): ?array
    {
        $now = gmdate('Y-m-d H:i:s');
        $projections = [];
        try {
            foreach ($this->readContracts->entriesForHeads($slot->schemaId, $slot->scopeRef, $slot->locale, $heads, true) as $entry) {
                $projections[] = $this->projector->project($registration->layout, $slot->id, $entry, $now);
            }
        } catch (StorageRejected) {
            return null;
        }

        return $projections;
    }

    /** Whether the layout has multi-value fields, whose side rows a write replaces. */
    public static function hasMulti(StorageViewLayout $layout): bool
    {
        foreach ($layout->written() as $column) {
            if ($column->column === null) {
                return true;
            }
        }

        return false;
    }

    /**
     * The registration a write keeps current, read with a shared lock after the
     * storage's schema (the order a schema change takes them in): null when there is
     * none or it is not ready. Public fields that changed under it (a schema change
     * no hook saw) silence it here.
     *
     * @phpstan-impure
     */
    private function writable(string $schemaId): ?PublishedSiteViewRegistration
    {
        if (!$this->registry->available()) {
            return null;
        }
        $hash = $this->currentHash($schemaId, true);
        $registration = $this->registry->find($schemaId, true);
        if ($registration === null || !$registration->ready()) {
            return null;
        }
        if ($hash !== $registration->publicFieldsHash) {
            $locked = $this->registry->findForUpdate($schemaId);
            if ($locked !== null) {
                $this->registry->put($locked->withState(PublishedSiteViewRegistration::STATE_PENDING));
            }
            $this->registry->silenceSchema($schemaId);

            return null;
        }

        return $registration;
    }

    /**
     * A slot a write keeps: ready or being built, along the current locale chain. A
     * ready slot built along another chain is silenced instead.
     *
     * @phpstan-impure
     */
    private function current(PublishedSiteViewSlot $slot): bool
    {
        if (!$slot->written()) {
            return false;
        }
        if ($slot->state === PublishedSiteViewSlot::STATE_READY && $slot->localeChain !== $this->readContracts->localeChainKey($slot->locale)) {
            $this->registry->silenceSlot($slot);

            return false;
        }

        return true;
    }

    /**
     * The record's row in the slot: removed, then written for its published revision.
     *
     * @phpstan-impure
     */
    private function write(PublishedSiteViewRegistration $registration, PublishedSiteViewSlot $slot, string $recordId, ?int $revision): void
    {
        $projections = [];
        if ($revision !== null) {
            $projections = $this->projections($registration, $slot, [['record_id' => $recordId, 'revision' => $revision]]);
            if ($projections === null) {
                $this->registry->silenceSlot($slot);

                return;
            }
        }
        $search = $registration->layout->searchTable();
        $this->writer->deleteSlotRecords($registration->tableName, $slot->id, [$recordId], $search);
        // The rows are gone already: the side rows need no second delete.
        $this->writer->upsert($registration->tableName, $projections, self::hasMulti($registration->layout), true, StorageViewTableShape::Site);
    }

    /**
     * Fills a slot the current publication made, when its scope and locale hold at
     * most the inline limit of published records; otherwise it stays pending.
     *
     * @phpstan-impure
     */
    private function buildInline(PublishedSiteViewRegistration $registration, PublishedSiteViewSlot $slot): void
    {
        $heads = $this->readContracts->publishedHeadPage($slot->schemaId, $slot->scopeRef, $slot->locale, $this->inlineBuildLimit + 1, null, true);
        if (count($heads) > $this->inlineBuildLimit) {
            return;
        }
        $projections = $this->projections($registration, $slot, $heads);
        if ($projections === null) {
            return;
        }
        $this->writer->upsert($registration->tableName, $projections, self::hasMulti($registration->layout), true, StorageViewTableShape::Site);
        $this->registry->putSlot(new PublishedSiteViewSlot(
            $slot->id,
            $slot->schemaId,
            $slot->scopeRef,
            $slot->locale,
            PublishedSiteViewSlot::STATE_READY,
            $this->readContracts->localeChainKey($slot->locale),
            null,
            null,
            gmdate('Y-m-d H:i:s'),
        ));
    }

    /**
     * Where these records are published, with every publication row of theirs locked
     * for the caller's transaction.
     *
     * @param list<string> $recordIds
     * @return list<array{record_id: string, scope_ref: string, locale: string, revision: int}>
     * @phpstan-impure
     */
    private function placements(string $schemaId, array $recordIds): array
    {
        $placements = [];
        foreach (array_chunk($recordIds, 500) as $chunk) {
            foreach ($this->connection->table(DatabasePublicationLifecycle::STATES_TABLE)
                ->where('schema_id', $schemaId)
                ->whereIn('record_id', $chunk)
                ->orderBy('record_id')->orderBy('scope_ref')->orderBy('locale')
                ->lockForUpdate()
                ->get(['record_id', 'scope_ref', 'locale', 'state', 'published_revision']) as $row) {
                $row = (array) $row;
                if ($row['state'] !== PublicationStateValue::Published->value || $row['published_revision'] === null) {
                    continue;
                }
                $placements[] = [
                    'record_id' => (string) $row['record_id'],
                    'scope_ref' => (string) $row['scope_ref'],
                    'locale' => (string) $row['locale'],
                    'revision' => (int) $row['published_revision'],
                ];
            }
        }

        return $placements;
    }

    /**
     * @param array{record_id: string, scope_ref: string, locale: string, revision: int} $placement
     * @phpstan-impure
     */
    private function refresh(PublishedSiteViewRegistration $registration, array $placement): void
    {
        $slot = $this->registry->slot($registration->schemaId, $placement['scope_ref'], $placement['locale'], 'shared');
        if ($slot !== null && $this->current($slot)) {
            $this->write($registration, $slot, $placement['record_id'], $placement['revision']);
        }
    }
}
