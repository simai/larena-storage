# Implementation summary

| File | Change |
| --- | --- |
| `operations.yaml` | declares storage.record.create and storage.record.update |
| `src/Runtime/RecordOperationHandlers.php` | new: create, and update as compare-and-swap; proposals |
| `src/Registry/StorageOperationProvider.php` | binds the two to storage.handler.record |
| `src/Providers/StorageServiceProvider.php` | registers the handler references in core's OperationHandlerCatalog; registers the twelve declared-but-unregistered Access codes |
| `resources/lang/*/operations.php` | labels for the new codes |
| `tests/Integration/RecordOperationExecutionTest.php` | new |
| `tests/Unit/StorageOperationDeclarationTest.php` | 29 operations; record codes |
| `src/Contracts/AdminRecordTreeReader.php`, `src/Runtime/DatabaseAdminRecordTreeReader.php` | new: an editor's read of a structure's current records with their tree parents, behind storage.record.read |
