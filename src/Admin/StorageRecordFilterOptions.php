<?php

declare(strict_types=1);

namespace Larena\Storage\Admin;

use Larena\Access\Runtime\ScopeMembershipQueryScopeProvider;
use InvalidArgumentException;
use Larena\Access\Administration\AdministratorLabelReader;
use Larena\Access\Contracts\ActorOperationAuthorizer;
use Larena\Admin\Contracts\RecordFilterOptionsGateway;
use Larena\Admin\Record\RecordOptionsRejected;
use Larena\Storage\Exceptions\StorageRejected;
use Larena\Core\Contracts\ScopeRefResolver;
use Larena\Core\Contracts\ScopeResourceBindings;
use Larena\Filesystem\Contracts\PersistentLogicalFileInspector;
use Larena\Storage\Contracts\StorageWorkbench;

/**
 * Scope-bound choices for reference fields (user, file, record) of any records list, for the
 * owners installed in this composition: the Storage section and the Minimal CMS demo.
 */
final readonly class StorageRecordFilterOptions implements RecordFilterOptionsGateway
{
    public function __construct(
        private ScopeMembershipQueryScopeProvider $scopes,
        private StorageWorkbench $workbench,
        private ActorOperationAuthorizer $authorizer,
        private AdministratorLabelReader $administrators,
        private PersistentLogicalFileInspector $files,
        private StorageRecordReferences $records,
        private ScopeRefResolver $scopeRefs,
        private ScopeResourceBindings $resources,
    ) {}

    /**
     * Storage's refusals reach the admin's record pickers as the admin's own (RecordOptionsRejected), with their
     * reason code: «not ready yet» while the target storage's витрина is built, «rejected» otherwise.
     *
     * @param list<string> $selectedValues
     * @return array{items:list<array{value:string,label:string}>,next_cursor:?string,selected:list<array{value:string,label:string}>,searchable:bool}
     */
    public function page(
        string $actorRef,
        string $scopeRef,
        string $structureId,
        string $fieldKey,
        ?string $cursor,
        int $limit,
        array $selectedValues,
    ): array {
        try {
            return $this->options($actorRef, $scopeRef, $structureId, $fieldKey, $cursor, $limit, $selectedValues);
        } catch (StorageRejected $rejected) {
            throw new RecordOptionsRejected($rejected->reasonCode, $rejected);
        }
    }

    /**
     * @param list<string> $selectedValues
     * @return array{items:list<array{value:string,label:string}>,next_cursor:?string,selected:list<array{value:string,label:string}>,searchable:bool}
     */
    private function options(
        string $actorRef,
        string $scopeRef,
        string $structureId,
        string $fieldKey,
        ?string $cursor,
        int $limit,
        array $selectedValues,
    ): array {
        if ($limit < 1 || $limit > 100 || count($selectedValues) > 100) {
            throw new InvalidArgumentException('record_filter_options_limit_invalid');
        }
        $this->scopes->assertActorScope($actorRef, $scopeRef);
        $this->authorizer->assertAllowed($actorRef, 'admin.frontend.read');
        $structure = $this->workbench->readStructure($scopeRef, $structureId, $actorRef);
        $field = null;
        foreach ($structure->fields as $candidate) {
            if (($candidate['key'] ?? null) === $fieldKey) {
                $field = $candidate;
                break;
            }
        }
        $type = $field['type'] ?? null;
        // relation@1 belongs to Content and is unavailable without that owner.
        if (!is_array($field) || !in_array($type, ['user', 'file', 'record'], true)
            || ($field['type_version'] ?? null) !== 1) {
            throw new InvalidArgumentException('record_filter_options_field_invalid');
        }
        // record@1 lists the active records of the field's target structure in this scope.
        $target = $field['constraints']['target_structure_id'] ?? null;
        if ($type === 'record' && !is_string($target)) {
            throw new InvalidArgumentException('record_filter_options_field_invalid');
        }

        $page = match ($type) {
            'user' => $this->users($actorRef, $cursor, $limit),
            'file' => $this->files($actorRef, $scopeRef, $cursor, $limit),
            default => $this->records->page($actorRef, $scopeRef, (string) $target, $cursor, $limit),
        };
        $selected = [];
        foreach (array_unique($selectedValues) as $value) {
            $label = match ($type) {
                'user' => $this->userLabel($actorRef, $value),
                'file' => $this->fileLabel($scopeRef, $value),
                default => $this->recordLabel($actorRef, $scopeRef, (string) $target, $value),
            };
            if ($label !== null) {
                $selected[] = ['value' => $value, 'label' => $label];
            }
        }

        return ['items' => $page['items'], 'next_cursor' => $page['next_cursor'],
            'selected' => $selected, 'searchable' => false];
    }

    /** @return array{items:list<array{value:string,label:string}>,next_cursor:?string} */
    private function users(string $actorRef, ?string $cursor, int $limit): array
    {
        if ($cursor !== null && preg_match('/\A[1-9][0-9]{0,17}\z/D', $cursor) !== 1) {
            throw new InvalidArgumentException('record_filter_options_cursor_invalid');
        }
        return $this->administrators->page($actorRef, $cursor === null ? null : (int) $cursor, $limit);
    }

    /** @return array{items:list<array{value:string,label:string}>,next_cursor:?string} */
    private function files(string $actorRef, string $scopeRef, ?string $cursor, int $limit): array
    {
        if ($cursor !== null) {
            $this->assertUuid($cursor);
        }
        $this->authorizer->assertAllowed($actorRef, 'filesystem.file.read');
        // The site's files, from core scope resources, a page at a time.
        // The site's logical files, a page at a time (core scope resources).
        $site = $this->scopeRefs->resolve($scopeRef);
        $page = $site === null ? ['items' => [], 'next_cursor' => null] : $this->resources->listRefs($site->ref, 'filesystem.logical_file', $cursor, $limit);
        $refs = $page['items'];
        $hasMore = $page['next_cursor'] !== null;
        $items = [];
        foreach ($refs as $ref) {
            $label = $this->fileLabel($scopeRef, (string) $ref);
            if ($label !== null) {
                $items[] = ['value' => (string) $ref, 'label' => $label];
            }
        }
        return ['items' => $items, 'next_cursor' => $hasMore && $refs !== [] ? (string) end($refs) : null];
    }

    private function userLabel(string $actorRef, string $value): ?string
    {
        if (preg_match('/\Auser:admin_identity:[1-9][0-9]{0,17}\z/D', $value) !== 1) {
            throw new InvalidArgumentException('record_filter_options_selected_invalid');
        }
        return $this->administrators->label($value, $actorRef);
    }

    private function fileLabel(string $scopeRef, string $value): ?string
    {
        $this->assertUuid($value);
        if (!$this->scopes->fileBelongsToScope($value, $scopeRef)) {
            return null;
        }
        $inspection = $this->files->inspect($value);
        $label = $inspection->safeMetadata['display_name'] ?? null;
        return $inspection->isContentAttachable() && is_string($label) && trim($label) !== '' ? trim($label) : null;
    }

    private function recordLabel(string $actorRef, string $scopeRef, string $target, string $value): ?string
    {
        if (preg_match('/\Arecord-[0-9a-f]{32}\z/D', $value) !== 1) {
            throw new InvalidArgumentException('record_filter_options_selected_invalid');
        }

        return $this->records->label($actorRef, $scopeRef, $target, $value);
    }

    private function assertUuid(string $value): void
    {
        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/D', $value) !== 1) {
            throw new InvalidArgumentException('record_filter_options_selected_invalid');
        }
    }
}
