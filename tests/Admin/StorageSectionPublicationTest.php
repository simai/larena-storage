<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/structure-role-schema.php';
require_once __DIR__ . '/bootstrap.php';

use Larena\Storage\Admin\StorageSectionPublication;
use Larena\Storage\Contracts\PublicationLifecycle;
use Larena\Storage\Contracts\PublicationState;
use Larena\Storage\Enums\PublicationStateValue;
use Larena\Storage\Runtime\DatabaseStructureRoleRegistry;
use Larena\Storage\Runtime\StarterStructureRoles;

// «Опубликовать» of the «Хранилища» section (sites plan, step 2.7): which storage has it, the record's badges per
// language and the actions each state allows. The port and the record card through real requests: Root's
// tests/Feature/StorageSectionPublicationAndSiteDomainsTest.php.

$connection = larena_storage_structure_role_connection();
$registry = new DatabaseStructureRoleRegistry($connection);
(new StarterStructureRoles($registry))->install('actor:installer', 'corr-install');
$fixtures = StarterStructureRoles::fixtures();
$rules = $fixtures[StarterStructureRoles::SITE_REDIRECT_REF];
$registry->bindStructure(StarterStructureRoles::SITE_REDIRECT_REF, 'schema.rules', 'site:main', $rules['fields'], 'actor:admin', $rules['relation_keys'], $rules['lifecycle']);

$heads = new class implements PublicationLifecycle {
    /** @var array<string, PublicationState> */
    public array $states = [];

    public function publish(string $schemaId, string $recordId, string $scopeRef, string $locale, int $revision, string $actorId, ?string $correlationId = null): PublicationState { throw new LogicException('read only'); }
    public function unpublish(string $schemaId, string $recordId, string $scopeRef, string $locale, string $actorId, ?string $correlationId = null): PublicationState { throw new LogicException('read only'); }
    public function schedule(string $schemaId, string $recordId, string $scopeRef, string $locale, int $revision, string $scheduledAt, string $actorId, ?string $correlationId = null): PublicationState { throw new LogicException('read only'); }
    public function archive(string $schemaId, string $recordId, string $scopeRef, string $locale, string $actorId, ?string $correlationId = null): PublicationState { throw new LogicException('read only'); }
    public function sweep(string $actorId, ?string $now = null, int $limit = 200, ?string $correlationId = null): array { return []; }
    public function dueSchedules(?string $now = null, int $limit = 200): array { return []; }
    public function head(string $schemaId, string $recordId, string $scopeRef, string $locale): ?PublicationState { return $this->states[$scopeRef.'|'.$locale] ?? null; }
    public function history(string $schemaId, string $recordId, string $scopeRef, string $locale, int $limit = 100): array { return []; }
    public function explain(string $schemaId, string $recordId, string $scopeRef, string $locale): array { return []; }
};
$state = static fn (PublicationStateValue $value, ?int $published): PublicationState => new PublicationState(
    'pub', 'schema.rules', 'record-1', 'site:main', 'ru', $value, $published);
$translator = new class implements \Illuminate\Contracts\Translation\Translator {
    /** @var array<string, string> */
    private array $words = ['publication' => 'Публикация', 'states.draft' => 'Черновик', 'states.published' => 'Опубликовано', 'states.scheduled' => 'Запланировано',
        'states.archived' => 'В архиве', 'actions.publish' => 'Опубликовать', 'actions.unpublish' => 'Снять с публикации'];

    public function get($key, array $replace = [], $locale = null): string
    {
        return $this->words[str_replace('larena-admin::structure_editors.', '', (string) $key)] ?? (string) $key;
    }

    public function choice($key, $number, array $replace = [], $locale = null): string { return (string) $key; }
    public function getLocale(): string { return 'ru'; }
    public function setLocale($locale): void {}
};
$config = new class implements \Illuminate\Contracts\Config\Repository {
    public function has($key): bool { return true; }
    public function get($key, $default = null): mixed { return $key === 'larena-admin.structure_editors.locales' ? ['ru', 'en', 'not a locale'] : $default; }
    public function all(): array { return []; }
    public function set($key, $value = null): void {}
    public function prepend($key, $value): void {}
    public function push($key, $value): void {}
};
$publication = new StorageSectionPublication($connection, $registry, $heads, $config, $translator);

// A publishable binding names its site; a schema without one has no publication.
assert($publication->siteOf('schema.rules') === 'site:main');
assert($publication->siteOf('schema.other') === null);
// A role that is not publishable does not count.
$registry->register(new \Larena\Storage\Contracts\StructureRole(
    roleCode: 'plain_list', roleVersion: 1, title: 'Plain', requiredFields: [['key' => 'title', 'type' => 'string']], optionalFields: [], requiredRelations: [],
    lifecycle: \Larena\Storage\Enums\RoleLifecycle::Plain, ownerPackage: 'larena/storage',
), 'actor:admin');
$registry->bindStructure('plain_list@v1', 'schema.plain', 'site:main', [['key' => 'title', 'type' => 'string']], 'actor:admin', [], 'plain');
assert($publication->siteOf('schema.plain') === null);

// The languages of the site pages tree, each with a badge.
assert($publication->locales() === ['ru', 'en']);
assert($publication->badges('schema.rules', 'record-1', 'site:main') === [['value' => 'draft', 'text' => 'ru · Черновик'], ['value' => 'draft', 'text' => 'en · Черновик']]);
$both = ['publish' => true, 'unpublish' => true];
// A draft is published; nothing to take back.
assert($publication->actions('schema.rules', 'record-1', 'site:main', 3, $both) === ['publish:ru', 'publish:en']);
// Without the right the action is not offered.
assert($publication->actions('schema.rules', 'record-1', 'site:main', 3, ['publish' => false, 'unpublish' => true]) === []);
// A published record at its revision is taken back; with a newer revision it is offered again.
$heads->states['site:main|ru'] = $state(PublicationStateValue::Published, 3);
assert($publication->actions('schema.rules', 'record-1', 'site:main', 3, $both) === ['unpublish:ru', 'publish:en']);
assert($publication->actions('schema.rules', 'record-1', 'site:main', 4, $both) === ['publish:ru', 'unpublish:ru', 'publish:en']);
assert($publication->badges('schema.rules', 'record-1', 'site:main')[0] === ['value' => 'published', 'text' => 'ru · Опубликовано']);
assert($publication->line('schema.rules', 'record-1', 'site:main') === 'ru · Опубликовано, en · Черновик');
// An archived publication is neither published nor taken back from here.
$heads->states['site:main|ru'] = $state(PublicationStateValue::Archived, null);
assert($publication->actions('schema.rules', 'record-1', 'site:main', 3, ['publish' => true, 'unpublish' => true]) === ['publish:en']);

// A row's and a bulk action's id read alike; a language the site does not keep and anything else are not actions.
assert($publication->parse('publish:ru') === ['publish', 'ru'] && $publication->parse('unpublish_en') === ['unpublish', 'en']);
foreach (['publish:de', 'archive:ru', 'publish', 'bulk_delete', null, 7] as $other) {
    assert($publication->parse($other) === null);
}
// A bulk action's id fits the data view's pattern; the rows' actions are grouped per verb.
assert(array_keys($publication->bulkActions($both)) === ['publish_ru', 'unpublish_ru', 'publish_en', 'unpublish_en']);
assert(array_keys($publication->bulkActions(['publish' => true])) === ['publish_ru', 'publish_en']);
assert($publication->rowActions()[0] === ['id' => 'publish:ru', 'label' => 'ru', 'group' => 'publish', 'group_label' => 'Опубликовать']);
assert($publication->column()['key'] === StorageSectionPublication::COLUMN && $publication->column()['variant'] === 'badges');

// The words of the section's own confirmations.
foreach (['ru', 'en'] as $locale) {
    $copy = storage_admin_words($locale)['larena_storage']['section'];
    foreach (['confirm_bulk_publish', 'confirm_bulk_unpublish', 'notice_record_publish', 'notice_record_unpublish'] as $key) {
        assert(is_string($copy[$key] ?? null) && $copy[$key] !== '', $locale.':'.$key);
    }
}

echo "StorageSectionPublicationTest passed.\n";
