# Implementation summary

- `SchemaDefinitionNormalizer`: constraints stay scalar-only except `options` on `choice`/`choices`, which must be a list of non-empty objects with scalar values; Property validates it. Canonical JSON keeps option order and sorts option keys, so digests stay deterministic.
- Record values: `choices` values are normalized by Property into declared option order; an empty list for a required field raises `storage_record_required_field_missing`.
- `DatabaseStorageWorkbench::listRecords`: operators `eq`, `in`, `contains`, `starts_with`, `gt`, `gte`, `lt`, `lte`, `between` with a per-type matrix; unsupported operators or combinations raise `storage_query_filter_operator_unsupported`; malformed shapes or values keep `storage_workbench_record_filter_invalid`.
- `MAX_SCAN` is 5000; rows are iterated with a query cursor and only matching records are retained. Numbered pages follow (`ceil(5000 / limit)`).
- `StorageWorkbench` has one implementation in this package; the contract signature is unchanged.
- Review follow-up: `in` on a `choices` field validates each value as a single option value, de-duplicates and caps at 100 values without applying `max_items`; datetime `min`/`max` constraints are stored as `YYYY-MM-DDTHH:MM:SS`; `MAX_SCAN` documents its memory budget.
