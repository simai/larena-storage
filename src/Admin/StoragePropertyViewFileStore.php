<?php

declare(strict_types=1);

namespace Larena\Storage\Admin;

use Illuminate\Database\DatabaseManager;
use Illuminate\Http\UploadedFile;
use Larena\Access\Contracts\ActorOperationAuthorizer;
use Larena\Core\Contracts\ScopeRefResolver;
use Larena\Core\Contracts\ScopeResourceBindings;
use Larena\Filesystem\Services\SafeFileService;
use RuntimeException;

/**
 * Files a person uploads in the property view: stored privately through the safe file service and
 * bound to the scope, so the scope's file options list them like any file of that scope.
 */
final readonly class StoragePropertyViewFileStore
{
    public const FILE = 'filesystem.logical_file';

    public function __construct(
        private SafeFileService $files,
        private DatabaseManager $database,
        private ActorOperationAuthorizer $authorizer,
        private ScopeRefResolver $scopes,
        private ScopeResourceBindings $resources,
    ) {}

    /** @return array{value:string,label:string,mime:string} */
    public function store(string $actor, string $scope, UploadedFile $upload): array
    {
        $this->authorizer->assertAllowed($actor, 'filesystem.file.attach');
        $label = mb_substr(trim($upload->getClientOriginalName()), 0, 120) ?: 'file';

        return $this->database->connection()->transaction(function () use ($actor, $scope, $upload, $label): array {
            $file = $this->files->store($upload, $label, 'private', null, (int) substr($actor, (int) strrpos($actor, ':') + 1));
            // The file belongs to the record's site (core scope resources); the record names it by its data reference.
            $site = $this->scopes->resolve($scope) ?? throw new RuntimeException('storage_property_file_scope_unknown');
            $this->resources->bind(self::FILE, (string) $file->logical_ref, $site->ref);

            return ['value' => (string) $file->logical_ref, 'label' => $label, 'mime' => (string) $file->getAttribute('mime_type')];
        });
    }
}
