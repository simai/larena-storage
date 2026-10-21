<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

use Larena\Storage\Enums\PublicationStateValue;

/**
 * Where one record stands in one scope and one locale.
 *
 * `previousPublishedRevision` exists so that unpublishing can report the head it
 * withdrew without reading the log: the question "what did I just take down" is asked
 * at exactly the moment the answer is most useful.
 *
 * A pending schedule is `scheduledRevision` with `scheduledAt`. On a `scheduled` record
 * nothing is live yet. On a `published` one the head keeps serving until the sweep
 * promotes the scheduled revision: the old page stays on the site until the new one
 * goes out. A schedule written before the state row kept its revision has
 * `scheduledAt` without `scheduledRevision`; its revision is in the log.
 */
final readonly class PublicationState
{
    public const ID_MAX_LENGTH = 190;

    public function __construct(
        public string $publicationId,
        public string $schemaId,
        public string $recordId,
        public string $scopeRef,
        public string $locale,
        public PublicationStateValue $state,
        public ?int $publishedRevision = null,
        public ?int $previousPublishedRevision = null,
        public ?string $scheduledAt = null,
        public ?string $publishedAt = null,
        public ?string $archivedAt = null,
        public ?int $scheduledRevision = null,
    ) {
    }

    public static function identity(string $schemaId, string $recordId, string $scopeRef, string $locale): string
    {
        return $schemaId . '|' . $recordId . '|' . $scopeRef . '|' . $locale;
    }

    public function isPublished(): bool
    {
        return $this->state->isPublished() && $this->publishedRevision !== null;
    }

    /** Whether a newer revision waits for the sweep while this one stays live. */
    public function hasPendingRevision(): bool
    {
        return $this->isPublished() && $this->scheduledAt !== null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'publication_id' => $this->publicationId,
            'schema_id' => $this->schemaId,
            'record_id' => $this->recordId,
            'scope_ref' => $this->scopeRef,
            'locale' => $this->locale,
            'state' => $this->state->value,
            'published_revision' => $this->publishedRevision,
            'previous_published_revision' => $this->previousPublishedRevision,
            'scheduled_at' => $this->scheduledAt,
            'published_at' => $this->publishedAt,
            'archived_at' => $this->archivedAt,
            'is_published' => $this->isPublished(),
            'scheduled_revision' => $this->scheduledRevision,
            'has_pending_revision' => $this->hasPendingRevision(),
        ];
    }
}
