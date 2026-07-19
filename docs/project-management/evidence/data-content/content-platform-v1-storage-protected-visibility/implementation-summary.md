# Implementation summary

- `SchemaDefinitionNormalizer` admits exactly `public`, `protected` and
  `admin`, preserving all three values in canonical immutable definitions.
- `FieldVisibility` classifies `protected`, `admin`, `hidden` and `encrypted`
  as requiring protected projection.
- `InMemoryStorageRuntime` and `PdoLocalDevStorageAdapter` now include a field
  only when its visibility is exactly `public`. Unknown, missing, null and
  other invalid legacy values fail closed by omission.
- The database-native `VersionedStorage` exact-public projection was already
  correct and remains unchanged.
- One compatibility test proves schema admission/rejection, canonical
  round-trip, immutable admin preservation, exact public projection,
  equivalent output across all three runtime surfaces and absence of raw
  protected values from projection evidence.

The batch adds no persistence schema, migration, provider, route, authorization
bypass or Content-owned logic.
