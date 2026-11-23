<?php

declare(strict_types=1);

namespace Larena\Storage\Admin;

use Illuminate\Http\UploadedFile;
use Larena\Access\Contracts\ActorOperationAuthorizer;
use Larena\Core\Contracts\ScopeRefResolver;
use Larena\Core\Contracts\ScopeResourceBindings;
use Larena\Core\Contracts\SiteLanguages;
use Larena\Filesystem\Services\SafeFileService;
use Larena\Storage\Admin\Contracts\StorageWorkbenchGateway;
use Larena\Storage\Contracts\StorageWorkbench;
use Larena\Storage\DocumentPort\DatabaseDocumentPorts;
use Larena\Storage\DocumentPort\DocumentPort;
use Larena\Storage\DocumentPort\DocumentPortBinding;
use Larena\Storage\DocumentPort\DocumentPortRights;
use Throwable;

/**
 * The page editor of a record's `composition@1` field in the Storage section (document port, block 2): which element
 * a request stands on, the port over it for this reader, and the editor's pictures and files beside the port.
 *
 * The element is bound here, never named by the browser: the scope is the reader's own (the section's gateway), the
 * storage and record come from the address and the field must be a composition field of that storage. The site is the
 * one the storage is published to (then the port announces `publish`), and the languages are that site's, the main one
 * first; a storage without a site has the section's languages.
 *
 * Pictures and files go through the safe file service (its kinds and sizes), as public files of the record's site:
 * a page shows them to its visitors. The document keeps only `media:<id>`; its address is asked for by that name.
 * Every answer is one of the port's four, with the port's reasons.
 */
final readonly class StorageDocumentEditor
{
    public const MEDIA_PREFIX = 'media:';

    public const FILE = 'filesystem.logical_file';

    /** The safe file service's refusals, by name: they extend Laravel's validation error, which a package install may lack. */
    private const UNSAFE_UPLOAD = 'Larena\\Filesystem\\Exceptions\\UnsafeUploadRefused';

    private const VALIDATION = 'Illuminate\\Validation\\ValidationException';

    /** @param list<string> $languages the section's languages for a storage published to no site, the main one first */
    public function __construct(
        private StorageWorkbenchGateway $gateway,
        private StorageWorkbench $workbench,
        private DatabaseDocumentPorts $ports,
        private DocumentPortRights $rights,
        private ?StorageSectionPublicationActions $publication = null,
        private ?SiteLanguages $siteLanguages = null,
        private array $languages = ['ru', 'en'],
        private ?SafeFileService $files = null,
        private ?ActorOperationAuthorizer $authorizer = null,
        private ?ScopeRefResolver $scopes = null,
        private ?ScopeResourceBindings $resources = null,
    ) {
    }

    /**
     * The element a request stands on, or the port's reason it cannot be bound: `session-expired` without a reader,
     * `access-denied` for a reader the section does not let in, `not-found` for a storage, record or field that is
     * not there (or a field that is not a page document).
     *
     * @return array{binding: DocumentPortBinding, field_label: string, record_title: string, structure_label: string}|string
     */
    public function bind(?string $actor, string $structure, string $recordId, string $field): array|string
    {
        if ($actor === null || trim($actor) === '') {
            return 'session-expired';
        }
        try {
            $state = $this->gateway->snapshot($actor, null, ['surface' => 'storage_section', 'records' => 'skip', 'structure_id' => $structure]);
            if (($state['status'] ?? null) === 'denied') {
                return 'access-denied';
            }
            $scope = $state['scope_ref'] ?? null;
            if (($state['status'] ?? null) !== 'ok' || !is_string($scope) || ($state['selected_structure']['structure_id'] ?? null) !== $structure) {
                return 'not-found';
            }
            $definition = $this->workbench->readStructure($scope, $structure, $actor);
            $declared = null;
            foreach ($definition->fields as $candidate) {
                if (($candidate['key'] ?? null) === $field && ($candidate['type'] ?? null) === 'composition') {
                    $declared = $candidate;
                }
            }
            if ($declared === null) {
                return 'not-found';
            }
            $record = $this->workbench->readRecord($scope, $structure, $recordId, $actor);
            $site = $this->publication?->binding($actor, $scope, $structure)['site'] ?? null;
            $title = $this->recordTitle($record->values, $recordId);

            return [
                'binding' => new DocumentPortBinding($scope, $structure, $recordId, $field, $site,
                    ['level' => 'page', 'ref' => 'record:' . $structure . '/' . $recordId . '/' . $field, 'title' => mb_substr($title, 0, 300)],
                    $this->languagesOf($site)),
                'field_label' => is_string($declared['label'] ?? null) && trim($declared['label']) !== '' ? $declared['label'] : $field,
                'record_title' => $title,
                'structure_label' => is_string($state['selected_structure']['label'] ?? null) ? $state['selected_structure']['label'] : $structure,
            ];
        } catch (Throwable) {
            return 'not-found';
        }
    }

    /** The port of the element for this reader; a request that cannot be bound gets a port that answers its reason. */
    public function port(?string $actor, string $structure, string $recordId, string $field): DocumentPort
    {
        $bound = $this->bind($actor, $structure, $recordId, $field);

        return is_string($bound) ? DocumentPort::refusing($bound) : $this->ports->open($bound['binding'], (string) $actor);
    }

    /**
     * What the reader may do on the page before the editor loads: read only, save, publish (the port's own rights,
     * read again on every load). Null: the reader may not read the record.
     *
     * @return array{save: bool, publish: bool}|null
     */
    public function rights(string $actor, DocumentPortBinding $binding): ?array
    {
        try {
            $rights = $this->rights->rights($binding, $actor);
        } catch (Throwable) {
            return null;
        }

        return $rights === null ? null : ['save' => $rights['save'] === true, 'publish' => $rights['publish'] === true];
    }

    /**
     * A picture or file the reader adds to the page: kept by the safe file service as a public file of the record's
     * site, answered as `media:<id>` and its address. The reader must be able to save the page and to attach files;
     * a kind the service refuses is `invalid` with the service's diagnostic.
     *
     * @return array<string, mixed> the answer
     */
    public function upload(?string $actor, string $structure, string $recordId, string $field, mixed $file): array
    {
        $bound = $this->bind($actor, $structure, $recordId, $field);
        if (is_string($bound)) {
            return self::refused($bound);
        }
        if ($this->files === null || $this->authorizer === null || $this->scopes === null || $this->resources === null) {
            return ['answer' => 'unavailable', 'reason' => 'storage-unavailable', 'retry_after' => DocumentPort::RETRY_AFTER];
        }
        $rights = $this->rights((string) $actor, $bound['binding']);
        if ($rights === null || !$rights['save'] || !$this->allowed((string) $actor, 'filesystem.file.attach')) {
            return self::refused('access-denied');
        }
        if (!$file instanceof UploadedFile) {
            return self::refused('invalid', 'file-missing');
        }
        $site = $this->scopes->resolve($bound['binding']->siteScopeRef ?? $bound['binding']->scopeRef);
        if ($site === null) {
            return ['answer' => 'unavailable', 'reason' => 'storage-unavailable', 'retry_after' => DocumentPort::RETRY_AFTER];
        }
        $label = mb_substr(trim(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)), 0, 120) ?: 'file';
        try {
            $stored = $this->files->store($file, $label, 'public', null, self::actorId((string) $actor));
            $this->resources->bind(self::FILE, (string) $stored->logical_ref, $site->ref);
        } catch (Throwable $failure) {
            // The service's own refusals: a kind it never keeps (a page, a script, a program), or a file it does not take
            // (a kind outside larena-filesystem.allowed_types, content of another kind than its name, too large).
            return match (true) {
                self::refusal($failure, self::UNSAFE_UPLOAD) => self::refused('invalid', 'file-kind-refused'),
                self::refusal($failure, self::VALIDATION) => self::refused('invalid', 'file-refused'),
                default => ['answer' => 'unavailable', 'reason' => 'storage-unavailable', 'retry_after' => DocumentPort::RETRY_AFTER],
            };
        }

        // Without the public file address (larena-filesystem.public_routes off) the file is kept and named; its address
        // is asked for again by url().
        return ['answer' => 'applied', 'data' => ['ref' => self::MEDIA_PREFIX . $stored->logical_ref, 'mime' => (string) $stored->mime_type, 'name' => $label]
            + (($address = $this->address($stored)) === null ? [] : ['url' => $address])];
    }

    /**
     * The address a page's `media:<id>` is drawn at, for a reader of the page: a public file of the record's site;
     * any other name is `not-found`.
     *
     * @return array<string, mixed> the answer
     */
    public function url(?string $actor, string $structure, string $recordId, string $field, mixed $ref): array
    {
        $bound = $this->bind($actor, $structure, $recordId, $field);
        if (is_string($bound)) {
            return self::refused($bound);
        }
        if ($this->files === null || $this->scopes === null || $this->resources === null) {
            return ['answer' => 'unavailable', 'reason' => 'storage-unavailable', 'retry_after' => DocumentPort::RETRY_AFTER];
        }
        if ($this->rights((string) $actor, $bound['binding']) === null) {
            return self::refused('access-denied');
        }
        $id = is_string($ref) && str_starts_with($ref, self::MEDIA_PREFIX) ? substr($ref, strlen(self::MEDIA_PREFIX)) : '';
        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/D', $id) !== 1) {
            return self::refused('not-found');
        }
        try {
            $site = $this->scopes->resolve($bound['binding']->siteScopeRef ?? $bound['binding']->scopeRef);
            $file = $this->files->require($id);
            if ($site === null || $file->visibility !== 'public' || !$this->resources->belongsTo(self::FILE, $id, $site->ref)) {
                return self::refused('not-found');
            }

            $address = $this->address($file);

            return $address === null
                ? ['answer' => 'unavailable', 'reason' => 'storage-unavailable', 'retry_after' => DocumentPort::RETRY_AFTER]
                : ['answer' => 'applied', 'data' => ['ref' => self::MEDIA_PREFIX . $id, 'url' => $address]];
        } catch (Throwable $failure) {
            // A file the service does not know is its refusal; anything else is the store.
            return self::refusal($failure, self::VALIDATION) ? self::refused('not-found')
                : ['answer' => 'unavailable', 'reason' => 'storage-unavailable', 'retry_after' => DocumentPort::RETRY_AFTER];
        }
    }

    /** The public address of a kept file, or null where the site serves no public files. */
    private function address(\Larena\Filesystem\Models\LogicalFileRecord $file): ?string
    {
        try {
            return $this->files?->publicUrl($file);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<string> the site's languages, its main one first; the section's without a site */
    private function languagesOf(?string $site): array
    {
        $languages = $this->languages;
        $main = $languages[0] ?? 'ru';
        if ($site !== null && $this->siteLanguages !== null) {
            $languages = $this->siteLanguages->languages($site);
            $main = $this->siteLanguages->main($site);
        }
        $languages = array_values(array_unique(array_filter([$main, ...$languages],
            static fn (string $language): bool => preg_match('/\A[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})*\z/D', $language) === 1)));

        return $languages === [] ? ['ru'] : $languages;
    }

    /** @param array<string, mixed> $values */
    private function recordTitle(array $values, string $recordId): string
    {
        foreach (['title', 'name', 'label'] as $key) {
            if (is_string($values[$key] ?? null) && trim($values[$key]) !== '') {
                return trim($values[$key]);
            }
        }

        return $recordId;
    }

    private function allowed(string $actor, string $operation): bool
    {
        try {
            $this->authorizer?->assertAllowed($actor, $operation);
        } catch (Throwable) {
            return false;
        }

        return $this->authorizer !== null;
    }

    /** Whether a failure is of the named class, read by name: the class may not be installed where this is checked. */
    private static function refusal(Throwable $failure, string $class): bool
    {
        return in_array($class, [$failure::class, ...array_values(class_parents($failure) ?: [])], true);
    }

    private static function actorId(string $actor): ?int
    {
        $id = substr($actor, (int) strrpos($actor, ':') + 1);

        return ctype_digit($id) ? (int) $id : null;
    }

    /** @return array<string, mixed> */
    private static function refused(string $reason, ?string $diagnostic = null): array
    {
        return ['answer' => 'refused', 'reason' => $reason]
            + ($diagnostic === null ? [] : ['data' => ['diagnostics' => [['code' => $diagnostic, 'severity' => 'error', 'path' => '/file']]]]);
    }
}
