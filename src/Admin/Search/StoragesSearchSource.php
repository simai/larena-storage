<?php

declare(strict_types=1);

namespace Larena\Storage\Admin\Search;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Larena\Storage\Admin\Contracts\StorageStructureSearch;
use Larena\Admin\Search\AdminSearchQuery;
use Larena\Admin\Search\AdminSearchSource;

/**
 * Storages whose name or ID matches, among those the person may list. A storage opens on its
 * data page in the Storage section. Their records are a group of their own (StorageRecordsSearchSource).
 */
final readonly class StoragesSearchSource implements AdminSearchSource
{
    public function __construct(
        private StorageStructureSearch $structures,
        private Router $router,
        private UrlGenerator $url,
        private Config $config,
        private Translator $translator,
    ) {}

    public function search(Request $request, AdminSearchQuery $query, int $limit): ?array
    {
        $actor = $request->attributes->get((string) $this->config->get('larena-auth.admin_entry.access_actor_attribute', 'larena_access_actor'));
        if (!is_string($actor) || $actor === '' || !$this->router->has('larena.admin.storage.records')) {
            return null;
        }
        $items = [];
        foreach ($this->structures->searchStructures($actor, null, $query->leadingStem(), $limit * 4) as $structure) {
            if (count($items) >= $limit || !$query->matches($structure['label'].' '.$structure['structure_id'])) {
                continue;
            }
            $items[] = [
                'text' => $structure['label'],
                'subtext' => $structure['structure_id'],
                'href' => $this->url->route('larena.admin.storage.records', ['structure' => $structure['structure_id']], false),
                'icon' => 'database',
            ];
        }

        return $items === [] ? null : [
            'key' => 'storage.structures',
            'label' => (string) $this->translator->get('larena-admin::admin.search.groups.storages'),
            'icon' => 'database',
            'items' => $items,
        ];
    }
}
