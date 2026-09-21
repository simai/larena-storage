# Tests

- `tests/Integration/StorageWorkbenchFieldTypesFiltersTest.php`: options constraint acceptance/rejection, digest determinism, choices normalization and JSON shape, required empty choices, every operator per type, unsupported combinations, AND composition, continuation binding, 5000-record scan, page cap and scan-limit rejection.
- Existing Storage suite unchanged and passing.
- `composer test` and `composer quality:gate`: PASS.
