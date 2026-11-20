<?php

declare(strict_types=1);

namespace Larena\Storage\DocumentPort;

/**
 * Where DocumentPort keeps one storage element: its drafts (each an immutable revision of the element, every language
 * under it), its publications and the receipts of the writes a client_key named. A revision and a publication id are
 * opaque strings to the port, never reused.
 *
 * A store throws instead of answering: StorageConflict when the draft moved since $expected, StorageRejected with its
 * own code for a refusal, anything else when it cannot be reached. DocumentPort turns each into the port's answer.
 */
interface DocumentPortStore
{
    /** @return array{revision: string, at: string, values: array<string, mixed>} the current draft */
    public function current(): array;

    /**
     * The drafts, newest first, the current one first.
     *
     * @return list<array{revision: string, at: string, languages: list<string>, restored_from?: string}>
     */
    public function drafts(): array;

    /** @return array<string, mixed>|null the values of a draft revision; null when there is none (or it was pruned) */
    public function valuesAt(string $revision): ?array;

    /**
     * Writes a new draft whose values replace the stored ones, when $expected is still the current revision.
     *
     * @param array<string, mixed> $values every language's value
     * @param array{intent: string, client_key: string, params_hash: string, restored_from?: string|null}|null $receipt
     * @return array{revision: string, at: string, values: array<string, mixed>}
     */
    public function write(string $expected, array $values, ?array $receipt): array;

    /**
     * The publications, newest first; only the live one is `published`.
     *
     * @return list<array{id: string, revision: string, state: string, at: string, languages: list<string>, from?: string, client_key?: string}>
     */
    public function publications(): array;

    /** The id of the live publication, or null when nothing is live. */
    public function live(): ?string;

    /**
     * Publishes a draft revision in these languages as a new publication (the others are taken down); $from names
     * the publication a rollback repeats.
     *
     * @param list<string> $languages
     * @param array{intent: string, client_key: string, params_hash: string} $receipt
     * @return array{id: string, revision: string, state: string, at: string, languages: list<string>, from?: string, client_key?: string}
     */
    public function publish(string $revision, array $languages, ?string $from, array $receipt): array;

    /** @return array{intent: string, params_hash: string, revision: string|null, publication: string|null}|null */
    public function receipt(string $clientKey): ?array;
}
