<?php

declare(strict_types=1);

namespace Larena\Storage\Admin\Http\Controllers;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;

/**
 * The address of the removed Minimal CMS demo screen (/admin/cms, owner decision 2026-10-29) answers with a
 * permanent redirect into the storage section: to the storage its ?structure_id named, else to the storages.
 * It reads nothing; the section then checks who may open what.
 */
final readonly class FormerCmsAddressController
{
    private const STRUCTURE = '/\Aworkbench\.[a-z][a-z0-9_.:-]{0,109}\z/D';

    public function __construct(private Redirector $redirector, private UrlGenerator $urls, private Config $config)
    {
    }

    public function __invoke(Request $request): RedirectResponse
    {
        $structure = $request->query('structure_id');
        $prefix = (string) $this->config->get('larena-admin.storage_section.name_prefix', 'larena.admin.storage.');
        $target = is_string($structure) && preg_match(self::STRUCTURE, $structure) === 1
            ? $this->urls->route($prefix . 'records', ['structure' => $structure], false)
            : $this->urls->route($prefix . 'index', [], false);

        return $this->redirector->to($target, 301);
    }
}
