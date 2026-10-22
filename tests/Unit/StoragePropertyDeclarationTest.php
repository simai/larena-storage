<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Larena\Property\Runtime\PropertyTypeRegistry;
use Larena\Property\Runtime\PropertyTypeSettingsCatalog;
use Larena\Storage\Encryption\StorageEncryptionPolicy;
use Larena\Storage\Exceptions\StorageRejected;
use Larena\Storage\SchemaEvolution\SchemaDefinitionNormalizer;
use Larena\Storage\View\StorageViewFieldOptions;

/**
 * Storage reads which types take a search text, an index on request and encryption, and which constraints are
 * lists, from Property's type declaration; it keeps only its own policy (the index budget, the VARCHAR limit).
 */
$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, 'StoragePropertyDeclarationTest failed: ' . $message . PHP_EOL);
        exit(1);
    }
};
$catalog = PropertyTypeSettingsCatalog::builtIns();

foreach ($catalog->typeKeys() as $type) {
    $expect(StorageViewFieldOptions::searchableKind($type) === $catalog->can($type, 'searchable'), 'searchable ' . $type);
    $expect(StorageViewFieldOptions::indexedKind($type) === $catalog->can($type, 'indexable'), 'indexable ' . $type);
    $expect(StorageEncryptionPolicy::encryptable($type) === $catalog->can($type, 'encryptable'), 'encryptable ' . $type);
}
$expect(StorageEncryptionPolicy::encryptableTypes() === ['string', 'text', 'integer', 'number', 'date', 'datetime'], 'the encryptable types');
$expect(!StorageViewFieldOptions::searchableKind('') && !StorageEncryptionPolicy::encryptable('unknown'), 'an unknown type takes nothing');

$expect(StorageViewFieldOptions::indexableLength(['type' => 'string', 'constraints' => ['max_length' => 255]]) === 255, 'a 255 string is indexable');
$expect(StorageViewFieldOptions::indexableLength(['type' => 'string', 'type_version' => 2, 'constraints' => ['max_length' => 1]]) === 1, 'string@2 is indexable');
$expect(StorageViewFieldOptions::indexableLength(['type' => 'string', 'constraints' => ['max_length' => 256]]) === null, 'a 256 string is not');
$expect(StorageViewFieldOptions::indexableLength(['type' => 'text', 'constraints' => ['max_length' => 10]]) === null, 'a text is not');

$normalizer = new SchemaDefinitionNormalizer(PropertyTypeRegistry::builtIns());
$schema = static fn (string $type, array $constraints): array => ['schema_id' => 'site.files', 'owner_package' => 'larena/storage', 'fields' => [
    ['key' => 'value', 'type' => $type, 'type_version' => 1, 'required' => false, 'visibility' => 'public', 'constraints' => $constraints],
]];
$refused = static function (array $definition) use ($normalizer): ?string {
    try {
        $normalizer->normalize($definition);
    } catch (StorageRejected $rejected) {
        return $rejected->getMessage();
    }

    return null;
};

// A file's accepted formats are a list of strings, kept in their order.
$file = $normalizer->normalize($schema('file', ['accept' => ['pdf', 'image/*'], 'max_size' => 1_048_576]));
$expect($file['fields'][0]['constraints'] === ['accept' => ['pdf', 'image/*'], 'max_size' => 1_048_576], 'file constraints kept');
$expect($refused($schema('file', ['accept' => 'pdf'])) === 'storage_schema_constraint_invalid', 'accept as a string');
$expect($refused($schema('file', ['accept' => [['pdf']]])) === 'storage_schema_constraint_invalid', 'accept with a nested list');
$expect($refused($schema('file', ['accept' => ['.pdf']])) === 'storage_schema_constraint_invalid', 'accept with a dotted extension');
// A list is a list only where the type declares it.
$expect($refused($schema('string', ['accept' => ['pdf']])) === 'storage_schema_constraint_invalid', 'accept on a string');
$expect($refused($schema('string', ['options' => [['value' => 'a', 'label' => 'A']]])) === 'storage_schema_constraint_invalid', 'options on a string');
// Form settings of string and number pass to the schema as scalars.
$string = $normalizer->normalize($schema('string', ['mask' => '00.00.0000', 'placeholder' => 'Дата', 'max_length' => 10]));
$expect($string['fields'][0]['constraints'] === ['mask' => '00.00.0000', 'max_length' => 10, 'placeholder' => 'Дата'], 'string form settings kept');
$number = $normalizer->normalize($schema('number', ['step' => '0.5', 'unit' => 'kg']));
$expect($number['fields'][0]['constraints'] === ['step' => '0.5', 'unit' => 'kg'], 'number form settings kept');
// An encrypted field takes no uniqueness: no type declares it, so Property refuses it.
$encrypted = $schema('string', ['unique' => true]);
$encrypted['fields'][0]['visibility'] = 'admin';
$encrypted['fields'][0]['encrypted'] = true;
$expect($refused($encrypted) === 'storage_schema_constraint_invalid', 'unique on an encrypted field');

echo "StoragePropertyDeclarationTest passed.\n";
