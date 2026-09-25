# Implementation

- Added StorageWorkbench::filterOperators(): array<string,list<string>>.
- DatabaseStorageWorkbench returns its existing FILTER_OPERATORS_BY_TYPE constant. There is no second operator list, no query change and no relative period operator.
- Added a package integration using real Storage structure/record APIs and PropertyDisplayProjector. It creates and then edits every built-in field type with expected revision, rejects a stale edit, reopens SQLite and projects the edited values. The test supplies a fixture-only resolver for authorized reference labels; it does not introduce production reference lookup.
