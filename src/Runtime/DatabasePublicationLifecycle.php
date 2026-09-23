<?php

declare(strict_types=1);

namespace Larena\Storage\Runtime;

use Illuminate\Database\Connection;
use Larena\Storage\Audit\PublicationAuditEventCatalog;
use Larena\Storage\Contracts\LocaleFallbackChain;
use Larena\Storage\Contracts\PublicationLifecycle;
use Larena\Storage\Contracts\PublicationState;
use Larena\Storage\Contracts\PublicationTransition;
use Larena\Storage\Enums\PublicationStateValue as State;
use Larena\Storage\Enums\PublicationTransitionValue as Transition;
use Larena\Storage\Exceptions\PublicationRejected;

/**
 * Publication per record, scope and locale.
 *
 * Three rules shape this class.
 *
 * A transition is legal only from the states the matrix lists, and an illegal one
 * writes nothing. The matrix is data rather than a chain of conditions so that the
 * legal moves can be read in one place.
 *
 * Every transition writes the state row and appends exactly one log row inside the
 * same transaction. A state change that is not in the log would be a change nobody
 * can account for.
 *
 * Publishing never touches a revision, its values or its localized values. It changes
 * only which revision is the head — which is what makes an immutable revision worth
 * having.
 */
final class DatabasePublicationLifecycle implements PublicationLifecycle
{
    public const STATES_TABLE = 'larena_storage_publication_states';

    public const LOG_TABLE = 'larena_storage_publication_log';

    public const SWEEP_LIMIT = 200;

    /**
     * Which states each transition may start from. Anything else is refused.
     *
     * @var array<string, list<State>>
     */
    private const ALLOWED_FROM = [
        Transition::Publish->value => [State::Draft, State::Scheduled, State::Published, State::Archived],
        Transition::Unpublish->value => [State::Published, State::Scheduled],
        Transition::Schedule->value => [State::Draft, State::Scheduled, State::Published],
        Transition::Archive->value => [State::Draft, State::Scheduled, State::Published, State::Archived],
        Transition::SweepPublish->value => [State::Scheduled],
    ];

    /**
     * @param callable(string $schemaId, string $recordId, int $revision): bool|null $revisionExists
     *        an optional check that a revision really belongs to the record; absent in
     *        a package test, bound in the composed application
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly mixed $revisionExists = null,
    ) {
    }

    /** @phpstan-impure */
    public function publish(
        string $schemaId,
        string $recordId,
        string $scopeRef,
        string $locale,
        int $revision,
        string $actorId,
        ?string $correlationId = null,
    ): PublicationState {
        return $this->transition(
            Transition::Publish,
            $schemaId,
            $recordId,
            $scopeRef,
            $locale,
            $revision,
            null,
            $actorId,
            $correlationId,
        );
    }

    /** @phpstan-impure */
    public function unpublish(
        string $schemaId,
        string $recordId,
        string $scopeRef,
        string $locale,
        string $actorId,
        ?string $correlationId = null,
    ): PublicationState {
        return $this->transition(
            Transition::Unpublish,
            $schemaId,
            $recordId,
            $scopeRef,
            $locale,
            null,
            null,
            $actorId,
            $correlationId,
        );
    }

    /** @phpstan-impure */
    public function schedule(
        string $schemaId,
        string $recordId,
        string $scopeRef,
        string $locale,
        int $revision,
        string $scheduledAt,
        string $actorId,
        ?string $correlationId = null,
    ): PublicationState {
        return $this->transition(
            Transition::Schedule,
            $schemaId,
            $recordId,
            $scopeRef,
            $locale,
            $revision,
            $scheduledAt,
            $actorId,
            $correlationId,
        );
    }

    /** @phpstan-impure */
    public function archive(
        string $schemaId,
        string $recordId,
        string $scopeRef,
        string $locale,
        string $actorId,
        ?string $correlationId = null,
    ): PublicationState {
        return $this->transition(
            Transition::Archive,
            $schemaId,
            $recordId,
            $scopeRef,
            $locale,
            null,
            null,
            $actorId,
            $correlationId,
        );
    }

    /**
     * @return array<string, mixed>
     * @phpstan-impure
     */
    public function sweep(string $actorId, ?string $now = null, int $limit = self::SWEEP_LIMIT, ?string $correlationId = null): array
    {
        $this->assertSchema();

        if ($limit < 1) {
            throw new PublicationRejected('invalid_limit', 'A sweep limit must be a positive integer.');
        }

        $moment = $now ?? $this->now();
        $published = [];

        foreach ($this->dueSchedules($moment, $limit) as $state) {
            // The scheduled revision is the one the schedule recorded, which the
            // schedule transition keeps in the log rather than in the state row —
            // the state row deliberately does not call a scheduled revision a head.
            $scheduledRevision = $this->scheduledRevision($state->publicationId);
            if ($scheduledRevision === null) {
                continue;
            }

            $this->transition(
                Transition::SweepPublish,
                $state->schemaId,
                $state->recordId,
                $state->scopeRef,
                $state->locale,
                $scheduledRevision,
                null,
                $actorId,
                $correlationId,
            );

            $published[] = [
                'publication_id' => $state->publicationId,
                'revision' => $scheduledRevision,
            ];
        }

        return [
            'swept_at' => $moment,
            'published' => $published,
            'published_count' => count($published),
            'limit' => min($limit, self::SWEEP_LIMIT),
        ];
    }

    /**
     * @return list<PublicationState>
     * @phpstan-impure
     */
    public function dueSchedules(?string $now = null, int $limit = self::SWEEP_LIMIT): array
    {
        $this->assertSchema();

        if ($limit < 1) {
            throw new PublicationRejected('invalid_limit', 'A due-schedule limit must be a positive integer.');
        }

        $rows = $this->connection->table(self::STATES_TABLE)
            ->where('state', State::Scheduled->value)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', $now ?? $this->now())
            ->orderBy('scheduled_at')
            ->limit(min($limit, self::SWEEP_LIMIT))
            ->get();

        $states = [];
        foreach ($rows as $row) {
            $states[] = $this->hydrate((array) $row);
        }

        return $states;
    }

    /** @phpstan-impure */
    public function head(string $schemaId, string $recordId, string $scopeRef, string $locale): ?PublicationState
    {
        $this->assertSchema();

        $row = $this->connection->table(self::STATES_TABLE)
            ->where('publication_id', PublicationState::identity($schemaId, $recordId, $scopeRef, $locale))
            ->first();

        return $row === null ? null : $this->hydrate((array) $row);
    }

    /**
     * @return list<PublicationTransition>
     * @phpstan-impure
     */
    public function history(string $schemaId, string $recordId, string $scopeRef, string $locale, int $limit = 100): array
    {
        $this->assertSchema();

        if ($limit < 1) {
            throw new PublicationRejected('invalid_limit', 'A history limit must be a positive integer.');
        }

        $rows = $this->connection->table(self::LOG_TABLE)
            ->where('publication_id', PublicationState::identity($schemaId, $recordId, $scopeRef, $locale))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $transitions = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $transitions[] = new PublicationTransition(
                publicationId: (string) $row['publication_id'],
                transition: Transition::from((string) $row['transition']),
                fromState: State::from((string) $row['from_state']),
                toState: State::from((string) $row['to_state']),
                revision: $row['revision'] === null ? null : (int) $row['revision'],
                actorId: (string) $row['actor_id'],
                createdAt: (string) $row['created_at'],
                scheduledAt: $row['scheduled_at'] === null ? null : (string) $row['scheduled_at'],
                correlationId: $row['correlation_id'] === null ? null : (string) $row['correlation_id'],
            );
        }

        return $transitions;
    }

    /**
     * @return array<string, mixed>
     * @phpstan-impure
     */
    public function explain(string $schemaId, string $recordId, string $scopeRef, string $locale): array
    {
        $state = $this->head($schemaId, $recordId, $scopeRef, $locale);
        $transitions = $state === null ? [] : $this->history($schemaId, $recordId, $scopeRef, $locale);

        return [
            'publication_id' => PublicationState::identity($schemaId, $recordId, $scopeRef, $locale),
            'exists' => $state !== null,
            'state' => $state?->state->value,
            'published_revision' => $state?->publishedRevision,
            'previous_published_revision' => $state?->previousPublishedRevision,
            'scheduled_at' => $state?->scheduledAt,
            'transition_count' => count($transitions),
            'allowed_transitions' => $state === null
                ? [Transition::Publish->value, Transition::Schedule->value, Transition::Archive->value]
                : $this->allowedFrom($state->state),
            'audit_events' => PublicationAuditEventCatalog::all(),
        ];
    }

    /**
     * @return list<string>
     */
    private function allowedFrom(State $state): array
    {
        $allowed = [];
        foreach (self::ALLOWED_FROM as $transition => $states) {
            if (in_array($state, $states, true)) {
                $allowed[] = $transition;
            }
        }

        return $allowed;
    }

    /** @phpstan-impure */
    private function transition(
        Transition $transition,
        string $schemaId,
        string $recordId,
        string $scopeRef,
        string $locale,
        ?int $revision,
        ?string $scheduledAt,
        string $actorId,
        ?string $correlationId,
    ): PublicationState {
        $this->assertSchema();
        LocaleFallbackChain::assertLocale($locale);

        $publicationId = PublicationState::identity($schemaId, $recordId, $scopeRef, $locale);
        if (strlen($publicationId) > PublicationState::ID_MAX_LENGTH) {
            throw new PublicationRejected('publication_identity_too_long', 'Publication identity exceeds 190 characters.');
        }

        if ($revision !== null) {
            if ($revision < 1) {
                throw new PublicationRejected('invalid_revision', 'A revision must be a positive integer.');
            }

            // A head pointing at a revision that does not exist would be worse than no
            // head at all: a reader would ask for it and get nothing.
            if (is_callable($this->revisionExists)
                && ($this->revisionExists)($schemaId, $recordId, $revision) !== true) {
                throw new PublicationRejected(
                    'unknown_revision',
                    'Revision ' . $revision . ' does not exist for this record.',
                );
            }
        }

        $existing = $this->head($schemaId, $recordId, $scopeRef, $locale);
        // A record nobody has published yet is a draft in every sense that matters.
        $from = $existing === null ? State::Draft : $existing->state;

        if (!in_array($from, self::ALLOWED_FROM[$transition->value], true)) {
            throw new PublicationRejected(
                'invalid_transition',
                $transition->value . ' is not allowed from ' . $from->value . '.',
            );
        }

        $result = null;

        $this->connection->transaction(function () use (
            $transition,
            $schemaId,
            $recordId,
            $scopeRef,
            $locale,
            $revision,
            $scheduledAt,
            $actorId,
            $correlationId,
            $publicationId,
            $existing,
            $from,
            &$result
        ): void {
            $now = $this->now();
            $to = $this->targetState($transition);

            $next = $this->applyTransition($transition, $existing, $revision, $scheduledAt, $now, $publicationId, $schemaId, $recordId, $scopeRef, $locale);

            $payload = [
                'schema_id' => $schemaId,
                'record_id' => $recordId,
                'scope_ref' => $scopeRef,
                'locale' => $locale,
                'state' => $next->state->value,
                'published_revision' => $next->publishedRevision,
                'previous_published_revision' => $next->previousPublishedRevision,
                'scheduled_at' => $next->scheduledAt,
                'published_at' => $next->publishedAt,
                'archived_at' => $next->archivedAt,
                'updated_by' => $actorId,
                'correlation_id' => $correlationId,
                'updated_at' => $now,
            ];

            if ($existing === null) {
                $this->connection->table(self::STATES_TABLE)->insert(
                    ['publication_id' => $publicationId, 'created_at' => $now] + $payload,
                );
            } else {
                $this->connection->table(self::STATES_TABLE)
                    ->where('publication_id', $publicationId)
                    ->update($payload);
            }

            // One log row per transition, in the same transaction as the state write:
            // a state change nobody can account for is not acceptable in a system whose
            // whole point is sanitized attribution.
            $this->connection->table(self::LOG_TABLE)->insert([
                'publication_id' => $publicationId,
                'schema_id' => $schemaId,
                'record_id' => $recordId,
                'scope_ref' => $scopeRef,
                'locale' => $locale,
                'transition' => $transition->value,
                'from_state' => $from->value,
                'to_state' => $to->value,
                'revision' => $revision,
                'scheduled_at' => $scheduledAt,
                'actor_id' => $actorId,
                'correlation_id' => $correlationId,
                'created_at' => $now,
            ]);

            $result = $next;
        });

        return $result ?? throw new PublicationRejected('transition_failed', 'The publication transition produced no state.');
    }

    private function targetState(Transition $transition): State
    {
        return match ($transition) {
            Transition::Publish, Transition::SweepPublish => State::Published,
            Transition::Unpublish => State::Draft,
            Transition::Schedule => State::Scheduled,
            Transition::Archive => State::Archived,
        };
    }

    private function applyTransition(
        Transition $transition,
        ?PublicationState $existing,
        ?int $revision,
        ?string $scheduledAt,
        string $now,
        string $publicationId,
        string $schemaId,
        string $recordId,
        string $scopeRef,
        string $locale,
    ): PublicationState {
        $currentHead = $existing?->publishedRevision;

        return match ($transition) {
            Transition::Publish, Transition::SweepPublish => new PublicationState(
                publicationId: $publicationId,
                schemaId: $schemaId,
                recordId: $recordId,
                scopeRef: $scopeRef,
                locale: $locale,
                state: State::Published,
                publishedRevision: $revision,
                // The head it replaced, kept so that a later unpublish can say what it
                // took down without reading the log.
                previousPublishedRevision: $currentHead,
                scheduledAt: null,
                publishedAt: $now,
                archivedAt: null,
            ),
            Transition::Unpublish => new PublicationState(
                publicationId: $publicationId,
                schemaId: $schemaId,
                recordId: $recordId,
                scopeRef: $scopeRef,
                locale: $locale,
                state: State::Draft,
                publishedRevision: null,
                previousPublishedRevision: $currentHead ?? $existing?->previousPublishedRevision,
                scheduledAt: null,
                publishedAt: $existing?->publishedAt,
                archivedAt: null,
            ),
            // Scheduling records an intention and leaves the head alone: a scheduled
            // record is not a published one.
            Transition::Schedule => new PublicationState(
                publicationId: $publicationId,
                schemaId: $schemaId,
                recordId: $recordId,
                scopeRef: $scopeRef,
                locale: $locale,
                state: State::Scheduled,
                publishedRevision: $currentHead,
                previousPublishedRevision: $existing?->previousPublishedRevision,
                scheduledAt: $scheduledAt,
                publishedAt: $existing?->publishedAt,
                archivedAt: null,
            ),
            Transition::Archive => new PublicationState(
                publicationId: $publicationId,
                schemaId: $schemaId,
                recordId: $recordId,
                scopeRef: $scopeRef,
                locale: $locale,
                state: State::Archived,
                publishedRevision: null,
                previousPublishedRevision: $currentHead ?? $existing?->previousPublishedRevision,
                scheduledAt: null,
                publishedAt: $existing?->publishedAt,
                archivedAt: $now,
            ),
        };
    }

    /** @phpstan-impure */
    private function scheduledRevision(string $publicationId): ?int
    {
        $row = $this->connection->table(self::LOG_TABLE)
            ->where('publication_id', $publicationId)
            ->where('transition', Transition::Schedule->value)
            ->orderByDesc('id')
            ->first();

        if ($row === null) {
            return null;
        }

        $revision = ((array) $row)['revision'] ?? null;

        return $revision === null ? null : (int) $revision;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): PublicationState
    {
        return new PublicationState(
            publicationId: (string) $row['publication_id'],
            schemaId: (string) $row['schema_id'],
            recordId: (string) $row['record_id'],
            scopeRef: (string) $row['scope_ref'],
            locale: (string) $row['locale'],
            state: State::from((string) $row['state']),
            publishedRevision: $row['published_revision'] === null ? null : (int) $row['published_revision'],
            previousPublishedRevision: $row['previous_published_revision'] === null
                ? null
                : (int) $row['previous_published_revision'],
            scheduledAt: $row['scheduled_at'] === null ? null : (string) $row['scheduled_at'],
            publishedAt: $row['published_at'] === null ? null : (string) $row['published_at'],
            archivedAt: $row['archived_at'] === null ? null : (string) $row['archived_at'],
        );
    }

    /** @phpstan-impure */
    private function assertSchema(): void
    {
        $schema = $this->connection->getSchemaBuilder();
        if (!$schema->hasTable(self::STATES_TABLE) || !$schema->hasTable(self::LOG_TABLE)) {
            throw new PublicationRejected('schema_missing', 'The publication tables are not migrated.');
        }
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
