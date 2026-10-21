<?php

declare(strict_types=1);

namespace Larena\Storage\SitePack;

/**
 * The storage SitePack format: a SitePack envelope (core's `sitepack.manifest.json` and `sitepack.catalog.json`) whose
 * annotations carry the schema stamp `larena.storage.sitepack`, version 1, and these artifacts:
 *
 * - `artifacts/storage/structures.jsonl`: one line per structure: id, label, history_keep, fields (type, type_version,
 *   required, visibility, constraints, position, label and the options hidden, searchable, indexed, localized), the
 *   current schema definition (without the reserved fields), role bindings by role ref, and the source versions
 *   (informational).
 * - `artifacts/storage/records/<structure key>/part-000001.jsonl` …: one line per record, in record id order, at most
 *   PART_RECORDS a part: `{id, state, versions, publication, relations}`. A version is `{revision, current,
 *   published_in, schema_version, values, localized: {locale: {field: value}}, content_hash}`, live versions only (the
 *   current one and those a publication state points to as published or scheduled), values read under the current
 *   schema; `schema_version` is the one the version was written under (informational) and `content_hash` is
 *   `sha256:` over the canonical JSON of `{"localized": …, "values": …}`.
 * - `artifacts/storage/references.jsonl`: records of other storages the records point at (record@1 values,
 *   relations): `{id, structure_id}`, structure_id null when the target is not a workbench structure.
 * - `artifacts/assets/index.jsonl`: one line per file a live version references, in logical ref order:
 *   `{key: "asset:<logical ref>", …PortableLogicalFileDescriptor fields…, blob}`.
 * - `artifacts/assets/blobs/sha256/<hex>`: each file's current content, once per SHA-256.
 *
 * Never in a pack: older revisions, the publication log, audit, field-change rows, actor ids, correlation ids, the
 * reserved fields larena_scope_ref and larena_record_state, and derived tables (витрины, slots, published keys).
 */
final class StorageSitePackFormat
{
    public const string SCHEMA_NAME = 'larena.storage.sitepack';
    public const int SCHEMA_VERSION = 1;
    public const string PACKAGE_PREFIX = 'storage';
    public const string ADAPTER = 'larena/storage';
    /** The manifest annotation of a pack whose encrypted values are in plaintext. */
    public const string DECRYPTED_ANNOTATION = 'contains_decrypted_values';

    public const string STRUCTURES = 'artifacts/storage/structures.jsonl';
    public const string REFERENCES = 'artifacts/storage/references.jsonl';
    public const string ASSET_INDEX = 'artifacts/assets/index.jsonl';
    public const string RECORDS_DIRECTORY = 'artifacts/storage/records';
    public const string BLOBS_DIRECTORY = 'artifacts/assets/blobs/sha256';

    public const string STRUCTURES_ID = 'storage.structures';
    public const string REFERENCES_ID = 'storage.references';
    public const string ASSET_INDEX_ID = 'assets.index';

    /** Records per part file. */
    public const int PART_RECORDS = 10000;

    /** The fields every workbench record carries that never leave the site. */
    public const array RESERVED_FIELDS = ['larena_scope_ref', 'larena_record_state'];

    public const string PROFILE_DATA = 'storage';
    public const string PROFILE_ASSETS = 'storage+assets';

    /** The structure id as a path segment: anything outside [A-Za-z0-9._-] becomes `-`. */
    public static function structureKey(string $structureId): string
    {
        return (string) preg_replace('/[^A-Za-z0-9._-]/', '-', $structureId);
    }

    public static function recordsPart(string $structureKey, int $part): string
    {
        return self::RECORDS_DIRECTORY . '/' . $structureKey . '/' . self::partName($part);
    }

    public static function recordsPartId(string $structureKey, int $part): string
    {
        return 'storage.records.' . $structureKey . '.' . substr(self::partName($part), 0, -6);
    }

    public static function partName(int $part): string
    {
        return sprintf('part-%06d.jsonl', $part);
    }

    public static function blob(string $sha256): string
    {
        return self::BLOBS_DIRECTORY . '/' . $sha256;
    }

    public static function blobId(string $sha256): string
    {
        return 'assets.blob.' . $sha256;
    }

    public static function assetKey(string $logicalRef): string
    {
        return 'asset:' . $logicalRef;
    }

    /**
     * The manifest annotations beside the schema stamp. No actor: a pack never says who made it. A pack that carries
     * encrypted values in plaintext says `contains_decrypted_values: true`.
     *
     * @param list<string> $structureIds
     * @return array<string, mixed>
     */
    public static function annotations(string $siteRef, array $structureIds, string $exportedAt, bool $decrypted = false): array
    {
        return [
            'adapter' => self::ADAPTER,
            'includes' => ['history' => false, 'archived_records' => true],
            'source' => ['site_ref' => $siteRef, 'structure_ids' => $structureIds, 'exported_at' => $exportedAt],
        ] + ($decrypted ? [self::DECRYPTED_ANNOTATION => true] : []);
    }

    /** One JSON line, encoded as core's SitePack writer encodes it. @param array<array-key, mixed>|\stdClass $object */
    public static function line(array|\stdClass $object): string
    {
        return json_encode(
            $object === [] ? new \stdClass() : $object,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        ) . "\n";
    }
}
