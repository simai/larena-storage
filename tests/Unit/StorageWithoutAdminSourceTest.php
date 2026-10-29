<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

// larena/admin is optional for Storage (owner decision 2026-10-29: the admin's Storage section is Storage's own UI, and a
// site without the admin still runs Storage). Only the section's own folders name larena/admin: src/Admin (reached
// only from StorageServiceProvider behind StorageSectionBindings::adminInstalled()), routes/admin and
// resources/views/admin (loaded there too). Everything else names no Larena\Admin class, in any form.
$root = dirname(__DIR__, 2);
$adminOnly = ['src/Admin/', 'routes/admin/', 'resources/views/admin/'];

/** @return list<array{int, string}> each name of a Larena\Admin class: its line and the name */
$references = static function (string $source): array {
    $found = [];
    foreach (PhpToken::tokenize($source) as $token) {
        if ($token->is([T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED]) && str_starts_with(ltrim($token->text, '\\'), 'Larena\\Admin\\')) {
            $found[] = [$token->line, $token->text];
        }
        // A class name in a string (class_exists('Larena\\Admin\\…')) would load it as well.
        if ($token->is(T_CONSTANT_ENCAPSED_STRING) && preg_match('/Larena\\\\{1,2}Admin\\\\{1,2}/', $token->text) === 1) {
            $found[] = [$token->line, $token->text];
        }
    }

    return $found;
};
// The scanner itself.
assert($references('<?php use Larena\\Admin\\Search\\AdminSearchRegistry;') !== []);
assert($references('<?php $a = \\Larena\\Admin\\Runtime\\AdminText::class;') !== []);
assert($references("<?php class_exists('Larena\\\\Admin\\\\X');") !== []);
assert($references("<?php // Larena\\Admin\\X\n\$a = 1;") === []);
assert($references('<?php use Larena\\Storage\\Admin\\StorageSectionBindings;') === []);

$violations = [];
$sectionFiles = 0;
foreach (['src', 'routes', 'config', 'database', 'scripts', 'tools'] as $directory) {
    if (!is_dir($root . '/' . $directory)) {
        continue;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }
        $relative = substr($file->getPathname(), strlen($root) + 1);
        $isSection = false;
        foreach ($adminOnly as $prefix) {
            $isSection = $isSection || str_starts_with($relative, $prefix);
        }
        if ($isSection) {
            $sectionFiles++;
            continue;
        }
        foreach ($references((string) file_get_contents($file->getPathname())) as [$line, $name]) {
            $violations[] = $relative . ':' . $line . ' ' . $name;
        }
    }
}
if ($violations !== []) {
    throw new RuntimeException("Storage code outside its admin section names larena/admin:\n" . implode("\n", $violations));
}
assert($sectionFiles > 50, 'the section lives in src/Admin and routes/admin');

// The provider reaches the section only behind the presence check, in register() and in boot().
$provider = (string) file_get_contents($root . '/src/Providers/StorageServiceProvider.php');
assert(substr_count($provider, 'if ($this->composesAdmin()) {') === 2 && str_contains($provider, 'return StorageSectionBindings::adminInstalled() && '));
assert(str_contains($provider, "\$this->loadRoutesFrom(__DIR__ . '/../../routes/admin/storage-section.php');"));
$bindings = (string) file_get_contents($root . '/src/Admin/StorageSectionBindings.php');
assert(str_contains($bindings, "return class_exists(AdminNavigationRegistry::class);"));

// Without the admin nothing of the section loads: the presence check answers false when the admin's classes are absent.
assert(\Larena\Storage\Admin\StorageSectionBindings::adminInstalled() === class_exists('Larena' . '\\Admin\\Navigation\\AdminNavigationRegistry'));

// The admin is suggested and a development requirement for the section's tests, never required; the admin does not
// require Storage either, so there is no cycle.
$composer = json_decode((string) file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
assert(!isset($composer['require']['larena/admin']));
assert(isset($composer['suggest']['larena/admin'], $composer['require-dev']['larena/admin']));
$adminComposer = $root . '/vendor/larena/admin/composer.json';
if (is_file($adminComposer)) {
    $admin = json_decode((string) file_get_contents($adminComposer), true, 512, JSON_THROW_ON_ERROR);
    assert(!isset($admin['require']['larena/storage']));
}

echo "StorageWithoutAdminSourceTest passed.\n";
