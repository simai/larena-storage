<?php

declare(strict_types=1);

namespace Larena\Storage\Admin\Search;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Larena\Storage\Admin\Contracts\StorageRecordSearch;
use Larena\Admin\Search\AdminSearchQuery;
use Larena\Admin\Search\AdminSearchSource;

/**
 * Records whose text matches, in the storages the person may list. A record opens on its storage's
 * data page in view mode; "All results" opens that list searched by the query when the matches share
 * one storage.
 */
final readonly class StorageRecordsSearchSource implements AdminSearchSource
{
    public function __construct(
        private StorageRecordSearch $records,
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
        $structures = [];
        // The storage search takes one string; the remaining words of the query filter here.
        foreach ($this->records->searchRecords($actor, null, $query->leadingStem(), $limit * 4) as $record) {
            if (count($items) >= $limit || !$query->matches($record['title'].' '.$record['structure_label'])) {
                continue;
            }
            $structures[$record['structure_id']] = true;
            $items[] = [
                'text' => $record['title'],
                'subtext' => $record['structure_label'],
                'href' => $this->url->route('larena.admin.storage.records', ['structure' => $record['structure_id']], false)
                    .'?'.http_build_query(['record_id' => $record['record_id'], 'record_mode' => 'view'], '', '&', PHP_QUERY_RFC3986),
                'icon' => 'description',
            ];
        }
        if ($items === []) {
            return null;
        }
        $group = [
            'key' => 'storage.records',
            'label' => (string) $this->translator->get('larena-admin::admin.search.groups.records'),
            'icon' => 'description',
            'items' => $items,
        ];
        if (count($structures) === 1) {
            $group['more'] = [
                'text' => (string) $this->translator->get('larena-admin::admin.search.all_results'),
                'href' => $this->url->route('larena.admin.storage.records', ['structure' => array_key_first($structures)], false)
                    .'?'.http_build_query(['search' => $query->text], '', '&', PHP_QUERY_RFC3986),
            ];
        }

        return $group;
    }
}
