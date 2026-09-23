<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

interface PublicationLifecycle
{
    public function publish(
        string $schemaId,
        string $recordId,
        string $scopeRef,
        string $locale,
        int $revision,
        string $actorId,
        ?string $correlationId = null,
    ): PublicationState;

    /**
     * Withdraw the published head. The returned state carries the revision that was
     * withdrawn in `previousPublishedRevision`.
     */
    public function unpublish(
        string $schemaId,
        string $recordId,
        string $scopeRef,
        string $locale,
        string $actorId,
        ?string $correlationId = null,
    ): PublicationState;

    /**
     * Record an intention. Scheduling does not publish: the head stays what it was
     * until the sweep runs.
     */
    public function schedule(
        string $schemaId,
        string $recordId,
        string $scopeRef,
        string $locale,
        int $revision,
        string $scheduledAt,
        string $actorId,
        ?string $correlationId = null,
    ): PublicationState;

    public function archive(
        string $schemaId,
        string $recordId,
        string $scopeRef,
        string $locale,
        string $actorId,
        ?string $correlationId = null,
    ): PublicationState;

    /**
     * Publish every schedule that is due. Idempotent, and never called from a read
     * path: a read that published would make every page view a write.
     *
     * @return array<string, mixed> what the sweep did
     */
    public function sweep(string $actorId, ?string $now = null, int $limit = 200, ?string $correlationId = null): array;

    /**
     * The schedules a sweep would publish right now. A read, so that a proposal can
     * show what would happen without a write.
     *
     * @return list<PublicationState>
     */
    public function dueSchedules(?string $now = null, int $limit = 200): array;

    public function head(string $schemaId, string $recordId, string $scopeRef, string $locale): ?PublicationState;

    /**
     * @return list<PublicationTransition>
     */
    public function history(string $schemaId, string $recordId, string $scopeRef, string $locale, int $limit = 100): array;

    /**
     * @return array<string, mixed>
     */
    public function explain(string $schemaId, string $recordId, string $scopeRef, string $locale): array;
}
