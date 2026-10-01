<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

/**
 * What the relation writer needs to know about the records it links, so that a
 * relation is checked before it is written rather than discovered broken later.
 */
interface RelationTargets
{
    /** The schema a record belongs to, or null when there is no such record. */
    public function schemaOf(string $recordId): ?string;

    /** The scope a record belongs to, or null when its structure has no scope. */
    public function scopeOf(string $recordId): ?string;

    /** Whether a structure is bound to a role, in any scope. */
    public function playsRole(string $schemaId, string $roleCode): bool;

    /**
     * The relations the current schema version of a record declares, by key, or
     * null when that version declares none.
     *
     * @return array<string, array{relation_key: string, kind: string, delete_policy: string, target_schema_id?: string, target_role_code?: string}>|null
     */
    public function declaredRelations(string $recordId): ?array;
}
