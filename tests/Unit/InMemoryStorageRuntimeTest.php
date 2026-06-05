<?php

declare(strict_types=1);

use Larena\Storage\Enums\FieldVisibility;
use Larena\Storage\Enums\MutationType;
use Larena\Storage\Enums\StorageDecisionStatus;
use Larena\Storage\Runtime\ArrayStorageMutation;
use Larena\Storage\Runtime\ArrayStorageQuery;
use Larena\Storage\Runtime\ArrayStorageSchema;
use Larena\Storage\Runtime\InMemoryStorageRuntime;

require_once __DIR__ . '/../../vendor/autoload.php';

$runtime = new InMemoryStorageRuntime();
$schema = new ArrayStorageSchema(
    'articles',
    '1.0.0',
    'access.storage.articles',
    'in_memory',
    [
        ['name' => 'title', 'type' => 'string', 'required' => true, 'visibility' => FieldVisibility::Public->value],
        ['name' => 'secret_note', 'type' => 'string', 'required' => false, 'visibility' => FieldVisibility::Hidden->value],
    ]
);

$registration = $runtime->registerSchema($schema);
if (!$registration->isValid() || $registration->blocksMutation()) {
    fwrite(STDERR, "Schema registration must pass for valid schema.\n");
    exit(1);
}

$missingSchemaDecision = $runtime->decideQuery(new ArrayStorageQuery('missing', 'scope:articles'));
if ($missingSchemaDecision !== StorageDecisionStatus::MissingSchema || $missingSchemaDecision->permitsDataAccess()) {
    fwrite(STDERR, "Missing schema query must fail closed.\n");
    exit(1);
}

$missingScopeDecision = $runtime->decideQuery(new ArrayStorageQuery('articles', ''));
if ($missingScopeDecision !== StorageDecisionStatus::MissingAccessScope || $missingScopeDecision->permitsDataAccess()) {
    fwrite(STDERR, "Missing access scope query must fail closed.\n");
    exit(1);
}

$invalidMutation = new ArrayStorageMutation('articles', null, MutationType::Create, 'scope:articles', []);
$invalidValidation = $runtime->validateMutation($invalidMutation);
if ($invalidValidation->isValid() || !$invalidValidation->blocksMutation()) {
    fwrite(STDERR, "Empty create payload must block mutation.\n");
    exit(1);
}

$createDecision = $runtime->mutate(new ArrayStorageMutation(
    'articles',
    'article-1',
    MutationType::Create,
    'scope:articles',
    ['title' => 'Hello Larena', 'secret_note' => 'never leak']
));
if ($createDecision !== StorageDecisionStatus::Allowed) {
    fwrite(STDERR, "Valid create mutation must be allowed.\n");
    exit(1);
}

$records = $runtime->records(new ArrayStorageQuery('articles', 'scope:articles', ['title' => 'Hello Larena']));
if (count($records) !== 1) {
    fwrite(STDERR, "Query must return the created record.\n");
    exit(1);
}

$projection = $records[0]->projection();
if (($projection['secret_note'] ?? null) !== '[redacted]') {
    fwrite(STDERR, "Hidden field must be redacted in projection.\n");
    exit(1);
}

$deleteDecision = $runtime->mutate(new ArrayStorageMutation('articles', 'article-1', MutationType::Delete, 'scope:articles'));
if ($deleteDecision !== StorageDecisionStatus::Allowed) {
    fwrite(STDERR, "Delete mutation must be allowed for existing record.\n");
    exit(1);
}

if ($runtime->records(new ArrayStorageQuery('articles', 'scope:articles')) !== []) {
    fwrite(STDERR, "Deleted record must not be returned.\n");
    exit(1);
}

echo "InMemoryStorageRuntimeTest passed.\n";
