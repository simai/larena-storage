<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

use Larena\Storage\Enums\PublicationStateValue;
use Larena\Storage\Enums\PublicationTransitionValue;

/**
 * One entry in the append-only publication log.
 *
 * The log is why there are two tables rather than one: a current-state row cannot
 * answer a question about the past, and both `history` and "what head did unpublish
 * replace" are questions about the past.
 */
final readonly class PublicationTransition
{
    public function __construct(
        public string $publicationId,
        public PublicationTransitionValue $transition,
        public PublicationStateValue $fromState,
        public PublicationStateValue $toState,
        public ?int $revision,
        public string $actorId,
        public string $createdAt,
        public ?string $scheduledAt = null,
        public ?string $correlationId = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'publication_id' => $this->publicationId,
            'transition' => $this->transition->value,
            'from_state' => $this->fromState->value,
            'to_state' => $this->toState->value,
            'revision' => $this->revision,
            'actor_id' => $this->actorId,
            'scheduled_at' => $this->scheduledAt,
            'created_at' => $this->createdAt,
        ];
    }
}
