<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/*
 * The Storage section's Blade views (moved from larena/admin with the section, owner decision 2026-10-29, with the admin's
 * counts) are thin hosts, as every product view of the admin: product Blade views are thin hosts (audit 2026-10-14, Specs docs/architecture/2026-10-14-admin-rendering-and-routes-plan.md):
 * they print prepared values and never compute. The counts below are what each view still did on
 * 2026-10-14; a count may only go down, and a view not listed must have none. Internal preview views
 * are outside this rule.
 */
$patterns = [
    'php' => '/@php\b/',
    'json' => '/json_encode\s*\(/',
    'script' => '/<script\b/i',
    'calls' => '/\\\\Larena\\\\|[A-Z][A-Za-z]+::[a-z][A-Za-z]*\s*\(/',
];
$baseline = [
    'admin/records.blade.php' => ['php' => 2],
    'admin/workbench.blade.php' => ['json' => 2],
];

/*
 * Raw output ({!! !!}) prints markup a view did not make through Blade (developer remark 3', 2026-10-27): the
 * target is a presenter that gives a typed view and the shared element Document renderer that draws it, with
 * the view printing one prepared result. The counts are what each product view still printed raw on
 * 2026-10-27 (271 in 38 views); they may only go down as the pages move to the renderer, stage by stage.
 * Stage 2 (heading buttons and marks as AdminPageHeading, drawn by AdminElementDocumentRenderer::heading and
 * printed with {{ }}): 245. Stage 3 (the storage pages and the shared record panel as typed trees — AdminRecordPanelView,
 * the alerts, the structure forms, SitePack, encryption and the field change holder — drawn by
 * AdminElementDocumentRenderer::tree()): 223. Stage 4 (the Larena CMS work area — the collection's settings, fields, records with
 * their forms, integrations and constructors — described by CmsWorkbenchPresenter and drawn by tree(), split into
 * several Documents when a page is larger than one may be): cms.blade.php 108 -> 0, in all 115. Stage 5 (the forms: the user's
 * create and edit page by AdminUserFormPresenter, the storage field editor StorageFieldEditor, the CSV import StorageImportPage,
 * the jobs page and panel StorageJobsPanel, the SitePack import StorageSitePackImportPage and the settings' drafts notices and
 * record panel, all drawn by tree()): users/form 18, storage/_field 12 and storage/_jobs 1 (both gone), storage/import 9,
 * storage/jobs 4, storage/sitepack-import 1, settings/drafts 3 and settings/changes 2 -> 1, in all 66. Stage 6 (the rest: the admin
 * shell's parts and feedback, the sign-in page, the first run and the web install as FirstRunScreen and WebInstallScreen of
 * AdminNativeControls parts, the structure editors' notices and confirmation, the settings' history and SitePack pages as
 * AdminSettingChangesPage parts, the record panels, the data view composite and component, and the UI lab's artifacts, each drawn
 * by the shared renderer or its Framework renderer and printed with {{ }} as an HtmlString): 0. No product view prints raw output;
 * a new {!! !!} in one fails here.
 */
$root = realpath(__DIR__ . '/../../resources/views');
// Every view of Storage is a page of its admin section.
$views = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$seen = [];
$raw = 0;
foreach ($views as $view) {
    $path = substr((string) $view->getPathname(), strlen((string) $root) + 1);
    if (!str_ends_with($path, '.blade.php') || str_starts_with($path, 'internal/')) {
        continue;
    }
    $source = (string) file_get_contents((string) $view->getPathname());
    $printed = substr_count($source, '{!!');
    assert($printed === 0, sprintf('%s: %d raw outputs {!! !!}; let the renderer draw it and print the HtmlString with {{ }}.', $path, $printed));
    $raw += $printed;
    foreach ($patterns as $kind => $pattern) {
        $count = preg_match_all($pattern, $source);
        $allowed = $baseline[$path][$kind] ?? 0;
        assert($count <= $allowed, sprintf('%s: %d %s (at most %d); move the work into the presenter or renderer.', $path, $count, $kind, $allowed));
        $seen[$path][$kind] = $count;
    }
}
// A listed view that is gone or cleaner lowers the baseline in the same change, so the ratchet keeps up.
foreach ($baseline as $path => $counts) {
    foreach ($counts as $kind => $allowed) {
        assert(($seen[$path][$kind] ?? 0) === $allowed, sprintf('%s: %s is now %d, lower its baseline from %d.', $path, $kind, $seen[$path][$kind] ?? 0, $allowed));
    }
}
echo "StorageBladeThinHostRatchetTest passed: raw outputs in the section's views {$raw}.\n";
